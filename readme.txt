=== Readiness Index ===
Tags: seo, ai, score, readiness, scan
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your site's AI readiness score inside wp-admin: connect, scan, and see how your site scores.

== Description ==

Readiness Index scores a website out of 100 against a published standard for how well AI systems can find, read and cite it. This plugin puts that score inside wp-admin.

You connect the site to your Readiness Index account once. After that you can scan the site and see its score, its grade, its four categories (Reach, Read, Understand and Act) and what changed since the last scan.

The Readiness menu has one screen, Score:

* The score, its grade, a verdict, the four categories and the change since the scan before.
* **Scan now**, with how many rescans are left, and live progress while a scan runs.
* **View the full report**, a link that opens the complete report of the scan on readinessindex.io in a new browser tab.
* Ownership: the plugin serves the small file the service checks to confirm the site is yours, and a button to check it.
* When the service has a next step to offer, such as watching the site every week or more rescans, the screen shows it as a link to readinessindex.io. The plugin knows no prices and no plan rules: it shows what the service tells it.

The plugin reads and reports. It does not edit your content, settings or theme, writes no files and adds nothing to your pages, robots.txt or sitemap. It stores its own settings in your database and answers one address on your site, the ownership file described below.

The source code is public: https://github.com/ten7/readinessindex-wordpress

== Installation ==

1. Upload the `readiness-index` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate it through the Plugins screen.
3. Open Readiness in the admin menu and choose Connect. You sign in to your Readiness Index account on readinessindex.io and approve the connection, then you are returned to your site.

Your site must be reachable on the public internet over https to be connected and scanned. The ownership file the service checks (`/.well-known/readinessindex-verify`) is answered by WordPress, so the site needs pretty permalinks (any structure except Plain); on Plain permalinks the web server usually answers that address itself and ownership cannot be verified. A site behind a password, in maintenance mode, or with a robots.txt that shuts every crawler out will be explained on the screen before you connect, and refused by the scanner without using a rescan.

== Frequently Asked Questions ==

= Does the plugin change my site? =

It does not edit your content, settings, theme or files, and it adds nothing to your pages. It stores its own settings in your database, and it answers one address with a small text file: `/.well-known/readinessindex-verify`, which holds the token the service reads to confirm the site is yours.

= Do I need an account? =

Yes. You connect the plugin to a Readiness Index account, which you create on readinessindex.io when you choose Connect. A free account includes a limited number of rescans. Paid plans are optional and are offered on readinessindex.io, not inside this plugin.

= What happens if I deactivate or delete the plugin? =

Deactivating stops the background check that follows a running scan. Deleting the plugin removes everything it stored: the connection key and its other settings, the cached answer, and each user's choice to close a notice. To end the connection on the service as well, use your profile page on readinessindex.io.

= Does it work on a multisite network? =

Yes. Each site in the network connects on its own, and activating the plugin one site at a time is supported.

== Privacy ==

The plugin collects no analytics and sends no telemetry.

== External services ==

This plugin connects to one service, and to no other host: Readiness Index at https://readinessindex.io, operated by TEN7. Connecting is optional and starts only when an administrator clicks Connect. Until then the plugin makes no request to the service.

Using it needs a Readiness Index account. A free account includes a limited number of rescans; paid plans are optional and are offered and managed on readinessindex.io, and this plugin shows none of their prices.

Once connected, the plugin sends this site's address, the plugin and WordPress versions, and the connection key to readinessindex.io when you open the Readiness screen, start a scan, check ownership, or while a scan is running (including a background check every minute that stops when the scan ends), so the service can run the scan and return the result. When you choose Connect, your browser is sent to readinessindex.io with this site's address and a one-time code; nothing else leaves the site then. It sends no information about your visitors or users.

Two links on the Score screen open readinessindex.io in your browser, and the plugin itself makes no request when you click them. View the full report opens the complete report of your scan. The upgrade link, shown only when the service offers a next step, opens the service's checkout page, and it carries this site's own admin screen address as the page to return to afterward.

The connection key is stored in one non-autoloaded option in your database, or read from a `READINESS_INDEX_API_KEY` constant in wp-config.php if you define one. Disconnect removes it from the site; to end the connection on the service as well, use your profile page on readinessindex.io. To prove the site is yours, the plugin serves a small text file at `/.well-known/readinessindex-verify` that holds a token; the token proves control of the site and opens nothing.

The terms are at https://readinessindex.io/terms and the privacy policy is at https://readinessindex.io/privacy.

== Changelog ==

= 0.6.1 =
* The release prepared for the wordpress.org directory. It reads and reports: connect, scan, and see the score. It does not edit your site.
* The Score screen now reads the current Index: four categories (Reach, Read, Understand and Act) and the test ids of Index 12.
* The multisite notice appears on the plugin's own screen only and can be dismissed.
* Uninstall now removes every transient and every user's closed notice, and visits every site of a large network.
* Connect redirects with `wp_safe_redirect`, and the plugin's own sentences no longer borrow WordPress's text domain.
* The readme says what a free account includes and what the Upgrade and View the full report links do.

= 0.6.0 =
* The Score screen is reorganized into five sections: your score and grade first, then where the points are, the scan details, the weekly watch and the connection.
* The rescan count now sits beside Scan again, and the two sentences that ran together no longer do.
* The category table names the weakest category, and each category has a labelled progress bar.
* The weekly watch offer sits below the data and can be closed with Not now, for your user only.
* Fixed: Connected as showed the plan label instead of the site. It now shows the site's address. Disconnect is a quiet button apart from the data.

= 0.5.0 =
* The plugin ships the Score alone: connect, scan, the score with its categories and change, rescans left, live progress, ownership and the service's next step.
* New: View the full report, which opens the complete report of the scan on readinessindex.io in a new tab.

= 0.2.0 =
* Connect to a Readiness Index account from the new Readiness menu.
* The Score screen: score, grade, verdict, categories, change since the last scan, rescans left, and Scan now with live progress.
* Ownership file and Check ownership.
* The service's next step is shown when rescans are used up.

= 0.1.0 =
* Repository, tooling and plugin skeleton.
