=== Readiness Index ===
Contributors: ten7
Tags: seo, ai, llms-txt, robots-txt, schema
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your site's AI readiness score inside wp-admin: connect, scan, and see what to fix.

== Description ==

Readiness Index scores a website out of 100 against a published standard for how well AI systems can find, read and cite it. This plugin puts that score inside wp-admin.

You connect the site to your Readiness Index account once. After that you can scan the site and see its score, its seven dimensions and what changed since the last scan.

This is the foundation release. It contains the plugin's structure and tooling and does not yet connect to anything.

== Installation ==

1. Upload the `readiness-index` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate it through the Plugins screen.

== Frequently Asked Questions ==

= Does the plugin change my site? =

Not in this release. When fixes arrive, each one shows a preview, applies only when you click, and can be undone.

== Privacy ==

The plugin collects no analytics and sends no telemetry.

== External services ==

This plugin connects to one service, and to no other host: Readiness Index at https://readinessindex.io, operated by TEN7. Connecting is optional and starts only when an administrator clicks Connect.

Once connected, the plugin sends this site's address and the connection key to readinessindex.io when you open the Readiness screen, start a scan, or while a scan is running, so the service can run the scan and return the result. It sends no information about your visitors or users.

The terms and privacy policy are published on that site.

== Changelog ==

= 0.1.0 =
* Repository, tooling and plugin skeleton. No features yet.
