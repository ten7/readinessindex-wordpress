<?php
/**
 * Unit test bootstrap: Composer's autoloader for the test tools, and the
 * plugin's own hand-written autoloader for the code under test, so the tests
 * exercise the loader a real install uses.
 *
 * @package ReadinessIndex
 */

namespace ReadinessIndex\Tests;

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/src/Autoloader.php';

\ReadinessIndex\Autoloader::register( 'ReadinessIndex\\', dirname( __DIR__ ) . '/src/' );
\ReadinessIndex\Autoloader::register( 'ReadinessIndex\\Tests\\', __DIR__ . '/Unit/' );

// The plugin file defines these when WordPress loads it; the unit suite loads classes
// directly, so it defines them itself.
define( 'READINESS_INDEX_VERSION', '0.2.0' );
define( 'READINESS_INDEX_DIR', dirname( __DIR__ ) . '/' );
define( 'READINESS_INDEX_FILE', dirname( __DIR__ ) . '/readiness-index.php' );

// A stand-in for WordPress's error object: the client only asks is_wp_error().
class WP_Error_Double {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound,Universal.Files.SeparateFunctionsFromOO.Mixed -- Test double.
