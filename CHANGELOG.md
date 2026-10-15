# Changelog

The same entries are in `readme.txt`, which is what wordpress.org shows.

## 0.6.0

- The Score screen is five sections under one heading outline: Your site's Readiness score, Where the points are, Scan details, the weekly watch and Connection. The state of the newest scan is a plain status line above the score, not a heading.
- Scan again and the rescan count are one row, and the count is one element, which ends the run-together sentence. With none left the button is disabled and says so. A paid plan shows the server's numbers and never the free-account sentence.
- The category table is a real table with a header, a score cell and a labelled meter per row. The weakest row or rows are bold and say so in words. Names are sentence case.
- Scan details is a description list, and the sampling sentence sits with Pages read on a free plan only.
- The weekly watch is an aside below the data with a secondary button and Not now, which is remembered for the user who pressed it. It appears only when the server's next step says so.
- Fixed: Connected as showed the plan label (Full report). It shows the host the connection key is bound to. Disconnect is a quiet danger button, never filled, and keeps its confirmation.
- Every date on the screen goes through the site's own date and time formats.
- New sentences are in `strings/plugin.en.json` and are proposed to the shared kit. The page's stylesheet carries layout and the one danger color only.

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
