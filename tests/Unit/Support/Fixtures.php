<?php
/**
 * Loads the golden API fixtures copied from the web repository.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Tests\Support;

/**
 * Every API test reads its request and response from `tests/fixtures/cms/`, the files
 * the server's own tests generate. A response shape is therefore never typed by hand
 * here, which is the only way the client and the server cannot drift apart unnoticed.
 */
final class Fixtures {

	/**
	 * The directory the fixtures were copied to.
	 */
	public static function dir(): string {
		return dirname( __DIR__, 2 ) . '/fixtures/cms';
	}

	/**
	 * One fixture, decoded.
	 *
	 * @param string $name Path without extension, such as `site/running`.
	 * @return array<string,mixed>
	 */
	public static function load( string $name ): array {
		$raw  = file_get_contents( self::dir() . '/' . $name . '.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test file.
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Unreadable fixture ' . $name ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		return $data;
	}

	/**
	 * A fixture's response as the array `wp_remote_request` returns.
	 *
	 * @param string $name Fixture name.
	 * @return array{response:array{code:int},headers:array<string,string>,body:string}
	 */
	public static function response( string $name ): array {
		$fixture = self::load( $name );
		$headers = array();
		foreach ( $fixture['response']['headers'] as $key => $value ) {
			$headers[ strtolower( (string) $key ) ] = (string) $value;
		}
		$body = $fixture['response']['body'] ?? null;
		return array(
			'response' => array( 'code' => (int) $fixture['response']['status'] ),
			'headers'  => $headers,
			'body'     => null === $body ? '' : (string) wp_json_encode_double( $body ),
		);
	}

	/**
	 * The names of every fixture, from the index the server writes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function index(): array {
		$data = json_decode( (string) file_get_contents( self::dir() . '/index.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test file.
		return $data['fixtures'];
	}
}

/**
 * Stand-in for wp_json_encode in a test with no WordPress.
 *
 * @param mixed $value Value.
 */
function wp_json_encode_double( $value ): string { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed,Generic.Files.OneObjectStructurePerFile.MultipleFound
	return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}
