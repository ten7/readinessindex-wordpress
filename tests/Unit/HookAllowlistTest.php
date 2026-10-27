<?php
namespace ReadinessIndex\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every entry point a request can reach (`admin-post`, `admin-ajax`, REST, rewrite rules,
 * `template_redirect`, and the code that answers on `init`) is listed here and in
 * `docs/SECURITY.md` with its capability, nonce, inputs and effects. A new one fails this test until
 * it is added to both, which is when somebody has to say who may call it.
 */
final class HookAllowlistTest extends TestCase {

	/**
	 * Entry points by kind: the registering file and the action or route.
	 *
	 * @var array<string,string[]>
	 */
	const ALLOWED = array(
		'admin_post'        => array(
			'Admin/Actions.php:Connector::ACTION_START',
			'Admin/Actions.php:Connector::ACTION_CALLBACK',
			'Admin/Actions.php:readiness_index_disconnect',
			'Admin/Actions.php:readiness_index_scan',
			'Admin/Actions.php:readiness_index_verify',
			'Admin/Actions.php:readiness_index_refresh',
			'Admin/Actions.php:readiness_index_dismiss',
			'Admin/Actions.php:Notices::DISMISS_ACTION',
		),
		'admin_post_nopriv' => array(
			// The return from readinessindex.io is a cross-site POST that carries no cookie; see SECURITY.md.
			'Admin/Actions.php:Connector::ACTION_CALLBACK',
		),
		'wp_ajax'           => array(
			'Admin/Ajax.php:self::ACTION',
		),
		'wp_ajax_nopriv'    => array(),
		'rest'              => array(),
		'rewrite'           => array(),
		'template_redirect' => array(),
		'init_responder'    => array( 'Connect/OwnershipFile.php:maybe_serve' ),
	);

	/**
	 * What the source registers, in the same notation.
	 *
	 * @return array<string,string[]>
	 */
	private function found(): array {
		$root  = dirname( __DIR__, 2 ) . '/src';
		$found = array_fill_keys( array_keys( self::ALLOWED ), array() );
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$rel  = substr( $file->getPathname(), strlen( $root ) + 1 );
			$code = (string) file_get_contents( $file->getPathname() );
			if ( preg_match_all( "/add_action\(\s*'admin_post_nopriv_'\s*\.\s*([\w:]+)/", $code, $m ) ) {
				foreach ( $m[1] as $a ) {
					$found['admin_post_nopriv'][] = $rel . ':' . $a;
				}
			}
			if ( preg_match_all( "/add_action\(\s*'admin_post_'\s*\.\s*([\w:]+)/", $code, $m ) ) {
				foreach ( $m[1] as $a ) {
					$found['admin_post'][] = $rel . ':' . $a;
				}
			}
			if ( preg_match_all( "/add_action\(\s*'admin_post_(readiness_index_\w+)'/", $code, $m ) ) {
				foreach ( $m[1] as $a ) {
					$found['admin_post'][] = $rel . ':' . $a;
				}
			}
			if ( preg_match_all( "/add_action\(\s*'wp_ajax_nopriv_/", $code, $m ) ) {
				$found['wp_ajax_nopriv'][] = $rel;
			}
			if ( preg_match_all( "/add_action\(\s*'wp_ajax_'\s*\.\s*([\w:]+)/", $code, $m ) ) {
				foreach ( $m[1] as $a ) {
					$found['wp_ajax'][] = $rel . ':' . $a;
				}
			}
			if ( preg_match_all( '/register_rest_route\(/', $code, $m ) ) {
				$found['rest'][] = $rel;
			}
			if ( preg_match_all( '/add_rewrite_rule\(\s*([\w:]+)/', $code, $m ) ) {
				foreach ( $m[1] as $a ) {
					$found['rewrite'][] = $rel . ':' . $a;
				}
			}
			if ( preg_match_all( "/add_action\(\s*'template_redirect',\s*array\(\s*self::class,\s*'(\w+)'/", $code, $m ) ) {
				foreach ( $m[1] as $a ) {
					$found['template_redirect'][] = $rel . ':' . $a;
				}
			}
		}
		// The ownership file is registered in Plugin.php with the class named there.
		if ( false !== strpos( (string) file_get_contents( $root . '/Plugin.php' ), 'OwnershipFile::class, \'maybe_serve\'' ) ) {
			$found['init_responder'][] = 'Connect/OwnershipFile.php:maybe_serve';
		}
		foreach ( $found as &$list ) {
			$list = array_values( array_unique( $list ) );
			sort( $list );
		}
		return $found;
	}

	public function test_no_entry_point_exists_that_is_not_on_the_list(): void {
		$found = $this->found();
		foreach ( self::ALLOWED as $kind => $allowed ) {
			sort( $allowed );
			$this->assertSame( $allowed, $found[ $kind ], $kind . ': an entry point was added or removed. Update this list and docs/SECURITY.md, saying its capability, nonce, inputs and effects.' );
		}
	}

	public function test_every_entry_point_is_documented_in_security_md(): void {
		$doc = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/SECURITY.md' );
		foreach ( self::ALLOWED as $kind => $allowed ) {
			foreach ( $allowed as $point ) {
				$name = substr( $point, strpos( $point, ':' ) + 1 );
				$name = preg_replace( '/^(self|Connector|Notices)::/', '', $name );
				$this->assertStringContainsString( $name, $doc, $kind . ' ' . $point . ' is not described in docs/SECURITY.md' );
			}
		}
	}

	public function test_the_only_request_without_a_session_is_the_connect_return_and_the_public_routes(): void {
		$found = $this->found();
		$this->assertCount( 1, $found['admin_post_nopriv'] );
		$this->assertSame( array(), $found['wp_ajax_nopriv'] );
		$this->assertSame( array(), $found['rest'], 'there is no REST route; one needs its own permission callback and a row in SECURITY.md' );
	}
}
