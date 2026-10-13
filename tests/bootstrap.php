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
