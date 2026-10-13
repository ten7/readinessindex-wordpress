=== Readiness Index ===
Tags: seo, ai, llms-txt, robots-txt, schema
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your site's AI readiness score inside wp-admin: connect, scan, see what to fix, and fix what WordPress can safely fix.

== Description ==

Readiness Index scores a website out of 100 against a published standard for how well AI systems can find, read and cite it. This plugin puts that score inside wp-admin, and fixes the part of it that WordPress is in a position to fix.

You connect the site to your Readiness Index account once. After that you can scan the site and see its score, its seven dimensions and what changed since the last scan.

The Readiness menu has three tabs.

* **Score**: the score, its grade, the seven dimensions and the change since the scan before, and a Scan now button. When a free account has used its rescans, the screen shows the service's own next step. The plugin knows no prices and no plan rules: it shows what the service tells it.
* **Fixes**: what to fix, in the service's order, with the points at stake. Each row is one of three kinds. *Fix it here* is a change this plugin can make, with a preview, Apply and Undo. *Fix it with* names an SEO plugin your site already has and the exact setting to change. *Hand it on* gives you the instructions to copy and give to whoever looks after the site.
* **Changes**: everything this plugin changed, when and by whom, with Undo.

The fixes this plugin can make: AI crawler access in robots.txt (through WordPress's own filter, never a file), a guide for AI assistants at /llms.txt, stray noarchive and nosnippet directives, honest published and modified dates in the sitemap and structured data, and one linked structured data graph of who you are. With Yoast SEO or Rank Math active, the facts go to that plugin and this one prints nothing of its own. Three things it can see and will not fix are shown as diagnostics: images without alt text, a CDN or firewall that may turn AI crawlers away, and content that needs JavaScript to appear.

Nothing changes until you have read the exact before and after and pressed Apply, and nothing runs by itself.

== Installation ==

1. Upload the `readiness-index` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate it through the Plugins screen.
3. Open Readiness in the admin menu and choose Connect. You sign in to your Readiness Index account on readinessindex.io and approve the connection, then you are returned to your site.

Your site must be reachable on the public internet over https to be connected and scanned. A site behind a password, in maintenance mode, or with a robots.txt that shuts every crawler out will be explained on the screen before you connect, and refused by the scanner without using a rescan.

== Frequently Asked Questions ==

= Does the plugin change my site? =

Only when you ask. Every change shows the exact before and after on a page of its own, applies only when you press Apply, is checked as a visitor would see it, and can be undone from the Changes tab. If the site changes while you are reading a preview, it is shown again and not applied. A fixer never edits a robots.txt or llms.txt file on your server, and never writes a date into WordPress.

= What happens if I deactivate or delete the plugin? =

Deactivating keeps the record of your changes and stops serving them until you activate the plugin again; nothing is undone. Deleting the plugin removes its own data (the connection key and the record of changes). It does not undo what the fixers changed unless you turned on "Remove my changes when I delete the plugin" on the Changes tab.

= Will it work with Yoast SEO or Rank Math? =

Yes, within the versions it is tested against (Yoast SEO 20.0 to 28.7, Rank Math 1.0.220 to 1.0.280). Their settings for the organization's name, logo and profiles are written only after you have seen the before and after, and Undo gives them back exactly. If both are active the plugin asks you to turn one off. Outside the tested versions it shows the setting to change instead of writing anything.

== Privacy ==

The plugin collects no analytics and sends no telemetry.

== External services ==

This plugin connects to one service, and to no other host: Readiness Index at https://readinessindex.io, operated by TEN7. Connecting is optional and starts only when an administrator clicks Connect.

Separately from that service, the plugin requests this site's own pages (its home page, a few of its pages, robots.txt, llms.txt and its sitemap) from this site's own address, to check that a change is live and to look for the problems the diagnostics report. Those requests go to no other host and carry no key.

Once connected, the plugin sends this site's address, the plugin and WordPress versions, and the connection key to readinessindex.io when you open the Readiness screen, start a scan, check ownership, or while a scan is running (including a background check every minute that stops when the scan ends), so the service can run the scan and return the result. When you choose Connect, your browser is sent to readinessindex.io with this site's address and a one-time code; nothing else leaves the site then. It sends no information about your visitors or users.

The connection key is stored in one non-autoloaded option in your database, or read from a `READINESS_INDEX_API_KEY` constant in wp-config.php if you define one. Disconnect removes it from the site; to end the connection on the service as well, use your profile page on readinessindex.io. To prove the site is yours, the plugin serves a small text file at `/.well-known/readinessindex-verify` that holds a token; the token proves control of the site and opens nothing.

The terms are at https://readinessindex.io/terms and the privacy policy is at https://readinessindex.io/privacy.

== Changelog ==

= 0.4.0 =
* Five fixes, each with preview, Apply and Undo: robots.txt for AI crawlers, /llms.txt, stray noarchive and nosnippet directives, honest published and modified dates, and one linked structured data graph.
* Works with Yoast SEO and Rank Math instead of printing a second graph beside them; other SEO plugins get the exact setting to change.
* Three diagnostics that never fix anything: images without alt text, a CDN or firewall that may turn AI crawlers away, and content that needs JavaScript.
* A change that is saved but not live names the page cache it found and asks it to clear.

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
