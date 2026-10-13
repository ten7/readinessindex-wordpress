<?php
/**
 * The one place the plugin talks to readinessindex.io.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Api;

use ReadinessIndex\Settings;
use ReadinessIndex\Strings;

/**
 * Every outbound call goes through this class and nowhere else; a test greps the rest of
 * the plugin for `wp_remote_` and `curl_`. The only host is the one in `Settings::origin()`.
 *
 * What the class guarantees, each against a bug it prevents:
 *
 * - TLS verification is never turned off, so a network in the middle cannot read the key.
 * - Redirects are never followed: a redirect would carry the Authorization header to
 *   wherever it points. A 3xx is a contract error.
 * - A GET is retried once on a transport failure or a 5xx, and a POST never: a retried
 *   POST of a scan could spend a second rescan for one click.
 * - A response is read only through the model classes, so a missing field is a
 *   `contract` error and never a notice that draws half a screen.
 * - The key is placed in one header and appears in no log line, error or return value.
 */
final class Client {

	const TIMEOUT = 15;

	/**
	 * API origin.
	 *
	 * @var string
	 */
	private $origin;

	/**
	 * Connection key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Builds a client. Without arguments it reads the stored origin and key.
	 *
	 * @param string|null $origin Origin override, for a test.
	 * @param string|null $key    Key override, for a test.
	 */
	public function __construct( ?string $origin = null, ?string $key = null ) {
		$this->origin = $origin ?? Settings::origin();
		$this->key    = $key ?? Settings::key();
	}

	/**
	 * `GET /cms/v1/site`, with `If-None-Match` and the cached body on a 304.
	 *
	 * @throws ApiError On any failure.
	 */
	public function site(): Model\SiteResponse {
		$cached  = SiteCache::read();
		$headers = array();
		if ( null !== $cached && '' !== $cached['etag'] ) {
			$headers['If-None-Match'] = $cached['etag'];
		}
		$answer = $this->send( 'GET', '/cms/v1/site', $headers, true );

		if ( 304 === $answer['status'] ) {
			if ( null === $cached ) {
				// A 304 to a request that carried no validator: the server is broken, not the cache.
				$error = Shape::fail( 'etag' );
				throw $error;
			}
			return Model\SiteResponse::from_array( $cached['body'] );
		}

		$site = Model\SiteResponse::from_array( $answer['body'] );
		SiteCache::write( $answer['etag'], $answer['body'] );
		return $site;
	}

	/**
	 * `POST /cms/v1/scan`. Never retried.
	 *
	 * @throws ApiError On any failure, including `quota` and `unreachable`.
	 */
	public function start_scan(): Model\ScanStarted {
		$answer = $this->send( 'POST', '/cms/v1/scan', array(), false );
		return Model\ScanStarted::from_array( $answer['body'] );
	}

	/**
	 * `GET /cms/v1/scan/{scan}`.
	 *
	 * @param string $token Scan token.
	 * @throws ApiError On any failure.
	 */
	public function scan_status( string $token ): Model\ScanStatus {
		$answer = $this->send( 'GET', '/cms/v1/scan/' . $this->segment( $token ), array(), true );
		return Model\ScanStatus::from_array( $answer['body'] );
	}

	/**
	 * `GET /cms/v1/fixes/{check}`.
	 *
	 * @param string $check Test id, such as AIR-1.1.
	 * @throws ApiError On any failure.
	 */
	public function fix_prompt( string $check ): Model\FixPrompt {
		$answer = $this->send( 'GET', '/cms/v1/fixes/' . $this->segment( $check ), array(), true );
		return Model\FixPrompt::from_array( $answer['body'] );
	}

	/**
	 * `POST /cms/v1/verify`. Never retried.
	 *
	 * @throws ApiError On any failure, including `not_verified`.
	 */
	public function verify(): Model\VerifyResult {
		$answer = $this->send( 'POST', '/cms/v1/verify', array(), false );
		return Model\VerifyResult::from_array( $answer['body'] );
	}

	/**
	 * One path segment, refused when it is not made of the characters a token or a test id has.
	 *
	 * A token that came from a response, or from an option, is still put through this: the
	 * segment is part of a URL that carries the Bearer key.
	 *
	 * @param string $value Candidate segment.
	 * @throws ApiError A `not_found` when it is not safe to send.
	 */
	private function segment( string $value ): string {
		if ( 1 !== preg_match( '/^[A-Za-z0-9._-]{1,100}$/', $value ) ) {
			$error = new ApiError( 'not_found', Strings::get( 'error.not_found' ), 404 );
			throw $error;
		}
		return rawurlencode( $value );
	}

	/**
	 * The `User-Agent` the contract names: module and WordPress versions, then the site.
	 */
	private function user_agent(): string {
		$home  = wp_parse_url( home_url() );
		$site  = is_array( $home ) && isset( $home['scheme'], $home['host'] ) ? $home['scheme'] . '://' . $home['host'] : '';
		$agent = sprintf( 'ReadinessIndex-WordPress/%s WordPress/%s', READINESS_INDEX_VERSION, get_bloginfo( 'version' ) );
		return '' === $site ? $agent : $agent . '; ' . $site;
	}

	/**
	 * Makes one call, retrying a GET once, and returns the status, ETag and decoded body.
	 *
	 * @param string               $method  GET or POST.
	 * @param string               $path    Path under the origin.
	 * @param array<string,string> $headers Extra headers.
	 * @param bool                 $retry   Whether a transport failure or a 5xx may be retried once.
	 * @return array{status:int,etag:string,body:array<string,mixed>}
	 * @throws ApiError On any failure.
	 */
	private function send( string $method, string $path, array $headers, bool $retry ): array {
		if ( '' === $this->key ) {
			$error = new ApiError( 'unauthenticated', Strings::get( 'error.unauthenticated' ), 401 );
			throw $error;
		}

		$args = array(
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => array_merge(
				array(
					'Authorization' => 'Bearer ' . $this->key,
					'Accept'        => 'application/json',
					'User-Agent'    => $this->user_agent(),
				),
				$headers
			),
		);

		$attempts = $retry ? 2 : 1;
		$failure  = null;
		for ( $try = 0; $try < $attempts; $try++ ) {
			$response = wp_remote_request( $this->origin . $path, $args );
			if ( is_wp_error( $response ) ) {
				$failure = new ApiError( ApiError::OFFLINE, Strings::get( 'state.offline.body' ) );
				continue;
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( $status >= 500 && $try + 1 < $attempts ) {
				$failure = $this->error_from( $status, $response );
				continue;
			}
			return $this->read( $status, $response );
		}
		$error = $failure ?? new ApiError( ApiError::OFFLINE, Strings::get( 'state.offline.body' ) );
		throw $error;
	}

	/**
	 * Turns a response into the parsed body, or throws the error it carries.
	 *
	 * @param int   $status   HTTP status.
	 * @param mixed $response The wp_remote_request result.
	 * @return array{status:int,etag:string,body:array<string,mixed>}
	 * @throws ApiError When the status is not a success.
	 */
	private function read( int $status, $response ): array {
		if ( 304 === $status ) {
			return array(
				'status' => 304,
				'etag'   => '',
				'body'   => array(),
			);
		}
		if ( $status < 200 || $status >= 300 ) {
			$error = $this->error_from( $status, $response );
			throw $error;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$error = Shape::fail( '$' );
			throw $error;
		}
		$etag = wp_remote_retrieve_header( $response, 'etag' );
		return array(
			'status' => $status,
			'etag'   => is_string( $etag ) ? $etag : '',
			'body'   => $body,
		);
	}

	/**
	 * Maps a non-success response to one `ApiError`.
	 *
	 * The server's `message` is used as it is, because it is one plain sentence meant to
	 * be shown; the module switches only on `error`. A body that is not an error body at
	 * all (a gateway's HTML page) is the server failing, not a contract the server wrote.
	 *
	 * @param int   $status   HTTP status.
	 * @param mixed $response The wp_remote_request result.
	 */
	private function error_from( int $status, $response ): ApiError {
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['error'] ) || ! is_string( $body['error'] ) ) {
			if ( $status >= 300 && $status < 400 ) {
				return Shape::fail( 'redirect' );
			}
			return new ApiError( 'internal_error', Strings::get( 'error.internal_error' ), $status );
		}

		$key     = $body['error'];
		$message = isset( $body['message'] ) && is_string( $body['message'] ) && '' !== $body['message'] ? $body['message'] : '';
		if ( '' === $message ) {
			$message = Strings::has( 'error.' . $key ) ? Strings::get( 'error.' . $key ) : Strings::get( 'error.internal_error' );
		}

		$extra = array();
		foreach ( array( 'reason', 'state' ) as $field ) {
			if ( isset( $body[ $field ] ) && is_string( $body[ $field ] ) ) {
				$extra[ $field ] = $body[ $field ];
			}
		}
		if ( isset( $body['retry_after'] ) && is_int( $body['retry_after'] ) ) {
			$extra['retry_after'] = $body['retry_after'];
		} elseif ( 'rate_limited' === $key ) {
			$header = wp_remote_retrieve_header( $response, 'retry-after' );
			if ( is_string( $header ) && ctype_digit( $header ) ) {
				$extra['retry_after'] = (int) $header;
			}
		}
		if ( isset( $body['allowance'] ) && is_array( $body['allowance'] ) ) {
			try {
				$extra['allowance'] = Model\Allowance::from_array( $body['allowance'] );
			} catch ( ApiError $e ) {
				unset( $extra['allowance'] ); // Show the sentence without the allowance rather than nothing.
			}
		}
		return new ApiError( $key, $message, $status, $extra );
	}
}
