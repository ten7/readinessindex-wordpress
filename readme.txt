=== Readiness Index ===
Tags: seo, ai, llms-txt, robots-txt, schema
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your site's AI readiness score inside wp-admin: connect, scan, and see what to fix.

== Description ==

Readiness Index scores a website out of 100 against a published standard for how well AI systems can find, read and cite it. This plugin puts that score inside wp-admin.

You connect the site to your Readiness Index account once. After that you can scan the site and see its score, its seven dimensions and what changed since the last scan.

The Readiness screen in wp-admin shows the score, its grade, the seven dimensions as a table and the change since the scan before, and has a Scan now button. When a free account has used its rescans, the screen shows the server's own next step. The plugin knows no prices and no plan rules: it shows what the service tells it.

== Installation ==

1. Upload the `readiness-index` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate it through the Plugins screen.
3. Open Readiness in the admin menu and choose Connect. You sign in to your Readiness Index account on readinessindex.io and approve the connection, then you are returned to your site.

Your site must be reachable on the public internet over https to be connected and scanned. A site behind a password, in maintenance mode, or with a robots.txt that shuts every crawler out will be explained on the screen before you connect, and refused by the scanner without using a rescan.

== Frequently Asked Questions ==

= Does the plugin change my site? =

Not in this release. When fixes arrive, each one shows a preview, applies only when you click, and can be undone.

== Privacy ==

The plugin collects no analytics and sends no telemetry.

== External services ==

This plugin connects to one service, and to no other host: Readiness Index at https://readinessindex.io, operated by TEN7. Connecting is optional and starts only when an administrator clicks Connect.

Once connected, the plugin sends this site's address, the plugin and WordPress versions, and the connection key to readinessindex.io when you open the Readiness screen, start a scan, check ownership, or while a scan is running (including a background check every minute that stops when the scan ends), so the service can run the scan and return the result. When you choose Connect, your browser is sent to readinessindex.io with this site's address and a one-time code; nothing else leaves the site then. It sends no information about your visitors or users.

The connection key is stored in one non-autoloaded option in your database, or read from a `READINESS_INDEX_API_KEY` constant in wp-config.php if you define one. Disconnect removes it from the site; to end the connection on the service as well, use your profile page on readinessindex.io. To prove the site is yours, the plugin serves a small text file at `/.well-known/readinessindex-verify` that holds a token; the token proves control of the site and opens nothing.

The terms are at https://readinessindex.io/terms and the privacy policy is at https://readinessindex.io/privacy.

== Changelog ==

= 0.2.0 =
* Connect to a Readiness Index account from the new Readiness menu.
* The Score screen: score, grade, verdict, the seven dimensions, change since the last scan, rescans left, and Scan now with live progress.
* Ownership file and Check ownership.
* The service's next step is shown when rescans are used up.

= 0.1.0 =
* Repository, tooling and plugin skeleton. No features yet.
