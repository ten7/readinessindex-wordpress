# Changelog

The same entries are in `readme.txt`, which is what wordpress.org shows.

## 0.5.0

- The plugin now ships the Score alone: connect, scan, the score with its dimensions and change, rescans left, live progress, ownership and the service's next step.
- New: View the full report, which opens the complete report of the scan on readinessindex.io in a new tab.
- The Fixes and Changes screens and every fixer are switched off by one switch (`READINESS_INDEX_FIXERS`, default off). Nothing is added to robots.txt, llms.txt, pages, dates or structured data, and a change made under an earlier version is no longer served. The code stays in the repository and in the test suite.

## 0.4.0

- Five fixes, each with preview, Apply and Undo: robots.txt for AI crawlers, /llms.txt, stray noarchive and nosnippet directives, honest published and modified dates, and one linked structured data graph.
- Works with Yoast SEO and Rank Math instead of printing a second graph beside them; other SEO plugins get the exact setting to change.
- Three diagnostics that never fix anything: images without alt text, a CDN or firewall that may turn AI crawlers away, and content that needs JavaScript.
- A change that is saved but not live names the page cache it found and asks it to clear.
- Adds the plugin's paragraph to the privacy policy guide.

## 0.3.0

- The Fixes tab, Preview, Apply and Undo, and the Changes tab.

## 0.2.0

- Connect, the Score screen, ownership file and Check ownership.

## 0.1.0

- Repository, tooling and plugin skeleton.
