=== Readiness Index ===
Tags: seo, ai, score, readiness, scan
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your site's AI readiness score inside wp-admin: connect, scan, and see how your site scores.

== Description ==

Readiness Index scores a website out of 100 against a published standard for how well AI systems can find, read and cite it. This plugin puts that score inside wp-admin.

You connect the site to your Readiness Index account once. After that you can scan the site and see its score, its grade, its seven dimensions and what changed since the last scan.

The Readiness menu has one screen, Score:

* The score, its grade, a verdict, the seven dimensions and the change since the scan before.
* **Scan now**, with how many rescans are left, and live progress while a scan runs.
* **View the full report**, a link that opens the complete report of the scan on readinessindex.io in a new tab.
* Ownership: the plugin serves the small file the service checks to confirm the site is yours, and a button to check it.
* When a free account has used its rescans, the screen shows the service's own next step. The plugin knows no prices and no plan rules: it shows what the service tells it.

The plugin reads and reports. It does not change your site: it edits no setting, writes no file and adds nothing to your pages, robots.txt or sitemap.

== Installation ==

1. Upload the `readiness-index` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate it through the Plugins screen.
3. Open Readiness in the admin menu and choose Connect. You sign in to your Readiness Index account on readinessindex.io and approve the connection, then you are returned to your site.

Your site must be reachable on the public internet over https to be connected and scanned. The ownership file the service checks (`/.well-known/readinessindex-verify`) is answered by WordPress, so the site needs pretty permalinks (any structure except Plain); on Plain permalinks the web server usually answers that address itself and ownership cannot be verified. A site behind a password, in maintenance mode, or with a robots.txt that shuts every crawler out will be explained on the screen before you connect, and refused by the scanner without using a rescan.

== Frequently Asked Questions ==

= Does the plugin change my site? =

No. It shows your score and starts scans. The only thing it serves on your site is the small ownership file at `/.well-known/readinessindex-verify`.

= What happens if I deactivate or delete the plugin? =

Deactivating stops the background check that follows a running scan. Deleting the plugin removes everything it stored: the connection key and its other settings. To end the connection on the service as well, use your profile page on readinessindex.io.

== Privacy ==

The plugin collects no analytics and sends no telemetry.

== External services ==

This plugin connects to one service, and to no other host: Readiness Index at https://readinessindex.io, operated by TEN7. Connecting is optional and starts only when an administrator clicks Connect.

Once connected, the plugin sends this site's address, the plugin and WordPress versions, and the connection key to readinessindex.io when you open the Readiness screen, start a scan, check ownership, or while a scan is running (including a background check every minute that stops when the scan ends), so the service can run the scan and return the result. When you choose Connect, your browser is sent to readinessindex.io with this site's address and a one-time code; nothing else leaves the site then. It sends no information about your visitors or users.

The connection key is stored in one non-autoloaded option in your database, or read from a `READINESS_INDEX_API_KEY` constant in wp-config.php if you define one. Disconnect removes it from the site; to end the connection on the service as well, use your profile page on readinessindex.io. To prove the site is yours, the plugin serves a small text file at `/.well-known/readinessindex-verify` that holds a token; the token proves control of the site and opens nothing.

The terms are at https://readinessindex.io/terms and the privacy policy is at https://readinessindex.io/privacy.

== Changelog ==

= 0.6.0 =
* The Score screen is reorganized into five sections: your score and grade first, then where the points are, the scan details, the weekly watch and the connection.
* The rescan count now sits beside Scan again, and the two sentences that ran together no longer do.
* The category table names the weakest category, and each category has a labelled progress bar.
* The weekly watch offer sits below the data and can be closed with Not now, for your user only.
* Fixed: Connected as showed the plan label instead of the site. It now shows the site's address. Disconnect is a quiet button apart from the data.

= 0.5.0 =
* The plugin now ships the Score alone: connect, scan, the score with its dimensions and change, rescans left, live progress, ownership and the service's next step.
* New: View the full report, which opens the complete report of the scan on readinessindex.io in a new tab.
* The Fixes and Changes screens and every fixer are switched off. Nothing is added to robots.txt, llms.txt, your pages, dates or structured data, and a change made under an earlier version is no longer served.

= 0.4.0 =
* Five fixes, each with preview, Apply and Undo: robots.txt for AI crawlers, /llms.txt, stray noarchive and nosnippet directives, honest published and modified dates, and one linked structured data graph.
* Works with Yoast SEO and Rank Math instead of printing a second graph beside them; other SEO plugins get the exact setting to change.
* Three diagnostics that never fix anything: images without alt text, a CDN or firewall that may turn AI crawlers away, and content that needs JavaScript.
* A change that is saved but not live names the page cache it found and asks it to clear.
* Adds the plugin's paragraph to the privacy policy guide.

= 0.3.0 =
* The Fixes tab: the service's ranked list of what to fix, each row one of fix it here, fix it with a tool your site already has, or hand it on, with a count of the points this plugin can reach.
* Preview, apply and undo: every change is previewed first, applied only by a click, checked as a visitor would see it, and undoable. Nothing runs by itself.
* The Changes tab: everything the plugin changed, when, by whom, with Undo.
* Deactivating keeps the record of your changes; deleting the plugin removes its own data, and undoes nothing unless you turn that on.

= 0.2.0 =
* Connect to a Readiness Index account from the new Readiness menu.
* The Score screen: score, grade, verdict, the seven dimensions, change since the last scan, rescans left, and Scan now with live progress.
* Ownership file and Check ownership.
* The service's next step is shown when rescans are used up.

= 0.1.0 =
* Repository, tooling and plugin skeleton. No features yet.
