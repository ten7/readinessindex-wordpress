// Browser tests for the plugin in wp-env. They run against the stand-in API (tests/e2e/fake-api.php)
// and never against edge: a run against edge needs a dedicated test account, which needs Ivan.
//
//   php -S 0.0.0.0:8099 tests/e2e/fake-api.php &
//   (wp-env started with READINESS_INDEX_API_ORIGIN pointing at it, see fake-api.php)
//   npx playwright test -c tests/e2e/playwright
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: '.',
	timeout: 60000,
	workers: 1,
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		trace: 'retain-on-failure',
	},
	projects: [
		{ name: 'desktop', use: { viewport: { width: 1280, height: 900 } } },
		{ name: 'mobile', use: { viewport: { width: 375, height: 812 } } },
	],
} );
