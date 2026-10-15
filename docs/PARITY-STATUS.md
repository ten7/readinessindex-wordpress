# WordPress column of the parity matrix

This repository's view of `docs/contract/MATRIX.md` (the copy of the web repository's `docs/cms-parity/MATRIX.md`). The matrix itself is not edited here; the web repository owns it, and the WordPress cell of each row moves there when READY-254 is accepted. **As of 0.5.0 (2026-10-15) the plugin ships the Score only.** Ivan decided the plugin is not ready to ship fixing, so every row below under Screen: Fixes, Screen: Changes, Fixer, Fixer conflict and Diagnostic is *built, tested and switched off*: `Features::fixers()` (the constant `READINESS_INDEX_FIXERS`, default off, or the `readiness_index_fixers` filter) gates every screen, action, filter and diagnostic, and the unit and integration suites run those rows with the switch on. Those rows are not live in a release until the switch ships on; the Connect, API, API error and Screen: Score rows are the shipped surface. The Score screen also links to the full report at `<API origin>/report/<score.scan>`, which the matrix has no row for. Status as of READY-256: all 71 rows built for WordPress (READY-255 and READY-256).

`Verified` says how a row is known to work: `tests` is the PHPUnit suite over the golden fixtures; `live` is a run on the Pantheon dev site (API origin edge). `e2e` is the real plugin driven over HTTP in wp-env (`tests/e2e/lib.sh`).

| Area | Capability | WordPress | Verified |
|---|---|---|---|
| Connect | Connect button sends the browser to `/connect/cms` with platform, site, callback and state | done | tests |
| Connect | Callback accepts the POST of key, state, plan and verify_token and checks state | done | tests |
| Connect | Cancel is understood (`error=access_denied` and state) | done | tests |
| Connect | Key kept where it is never exported or autoloaded | done | tests |
| Connect | Disconnect and reconnect from the module | done | tests |
| Connect | Ownership file served at `/.well-known/readinessindex-verify` with the verify_token | done | tests |
| Connect | Checkout opened with `?return=` of the module's own admin screen | done | tests |
| API call | `GET /cms/v1/site` | done | tests |
| API call | `POST /cms/v1/scan` | done | tests |
| API call | `GET /cms/v1/scan/{scan}` | done | tests |
| API call | `GET /cms/v1/fixes/{check}` | done | tests (client method; the Hand it on row loads it when the row opens) |
| API call | `POST /cms/v1/verify` | done | tests |
| API call | Calls sent server to server with a Bearer key and the `User-Agent` the contract names | done | tests + live |
| API call | Polling uses `If-None-Match` and treats 304 as unchanged | done | tests |
| API call | Validates what it reads against `docs/cms-fixtures/openapi.json` | done | tests (runtime checks required fields; unknown fields are ignored, the fixture test is strict) |
| API error | Handles `unauthenticated` | done | tests + live |
| API error | Handles `account_disabled` | done | tests |
| API error | Handles `insufficient_scope` | done | tests |
| API error | Handles `rate_limited` | done | tests |
| API error | Handles `not_found` | done | tests |
| API error | Handles `quota` | done | tests |
| API error | Handles `no_result` | done | tests |
| API error | Handles `unreachable` | done | tests |
| API error | Handles `not_verified` | done | tests |
| API error | Handles `internal_error` | done | tests |
| API error | Shows the server's `message` as it is and switches only on `error` | done | tests |
| API error | Backs off on `Retry-After` and never retries a `quota` | done | tests |
| API error | Treats a call that cannot connect at all as `state.offline` | done | tests |
| Screen: Score | State: unconnected | done | tests |
| Screen: Score | State: never scanned | done | tests |
| Screen: Score | State: pending | done | tests |
| Screen: Score | State: running | done | tests |
| Screen: Score | State: done | done | tests |
| Screen: Score | View the full report: opens `<API origin>/report/<score.scan>` in a new tab, only with a finished scan | done | tests |
| Screen: Score | State: failed | done | tests |
| Screen: Score | State: quota | done | tests |
| Screen: Score | State: revoked | done | tests |
| Screen: Score | State: unreachable | done | tests |
| Screen: Score | State: update required | done | tests |
| Screen: Score | State: reconnect after revoke | done | tests |
| Screen: Score | Scan now spends a rescan and shows what is left | done | tests |
| Screen: Score | Free account: five lifetime rescans, then Basic, never a pack | done | tests |
| Screen: Score | Billing off shows no plan to buy | done | tests |
| Screen: Fixes | Fixes listed in the server's order with points and who fixes them | built, off in 0.5.0 | tests (the list needs a connected site, so it is not driven live; each preview page is) |
| Screen: Fixes | Fix it here: preview, apply, undo | built, off in 0.5.0 | tests (the list needs a connected site, so it is not driven live; each preview page is) |
| Screen: Fixes | Fix it with an SEO tool the site already has | built, off in 0.5.0 | tests (the list needs a connected site, so it is not driven live; each preview page is) |
| Screen: Fixes | Hand it on: the fix prompt with a copy button | built, off in 0.5.0 | tests (the list needs a connected site, so it is not driven live; each preview page is) |
| Screen: Fixes | Points the fixers can reach, said before anything is applied | built, off in 0.5.0 | tests (the list needs a connected site, so it is not driven live; each preview page is) |
| Screen: Changes | Every change listed with what, when, by whom and Undo | built, off in 0.5.0 | tests + e2e (wp-env) + live (Pantheon dev) |
| Screen: Changes | Empty state | built, off in 0.5.0 | tests + e2e (wp-env) + live (Pantheon dev) |
| Fixer | AIR-1.1 robots.txt: take blanket AI blocks off the crawlers the owner chooses | built, off in 0.5.0 | tests (vector + WordPress adapter) + e2e (wp-env) |
| Fixer | AIR-1.4 robots.txt: state what AI may do (Content Signals) | built, off in 0.5.0 | tests (vector + WordPress adapter) + e2e (wp-env) |
| Fixer | AIR-1.5 robots.txt: the policy in plain words | built, off in 0.5.0 | tests (vector + WordPress adapter) + e2e (wp-env) |
| Fixer | AIR-1.10 `/llms.txt` generated from the site's content | built, off in 0.5.0 | tests (vector + WordPress adapter) + e2e (wp-env) |
| Fixer | AIR-1.6 Stray `noarchive`, `nosnippet` and `max-snippet:0` removed | built, off in 0.5.0 | tests (vector + WordPress adapter) + e2e (wp-env) |
| Fixer | AIR-4.8 Sitemap `lastmod` from when the content really changed | built, off in 0.5.0 | tests (vector + WordPress adapter) + e2e (wp-env) |
| Fixer | AIR-5.4 `datePublished` and `dateModified` from when the content really changed | built, off in 0.5.0 | tests (vector + WordPress adapter) + e2e (wp-env) |
| Fixer | AIR-1.11 One linked entity graph with `@id` references | built, off in 0.5.0 | tests (vector + WordPress adapter) + e2e (wp-env) |
| Fixer | AIR-1.12 `sameAs` links to authority records | built, off in 0.5.0 | tests (vector + WordPress adapter) + e2e (wp-env) |
| Fixer | AIR-1.13 About, Contact and organizational detail | built, off in 0.5.0 | tests (vector + WordPress adapter) + e2e (wp-env) |
| Fixer | AIR-3.2 `BreadcrumbList` below the home page | built, off in 0.5.0 | tests (vector + WordPress adapter) + e2e (wp-env) |
| Fixer | AIR-3.7 `SearchAction` on the WebSite node | built, off in 0.5.0 | tests (vector + WordPress adapter) + e2e (wp-env) |
| Fixer conflict | robots.txt: a physical file wins, so previewed to paste and not applied | built, off in 0.5.0 | tests (real WordPress, real Yoast SEO and Rank Math where named) |
| Fixer conflict | llms.txt: a physical file or another tool serves it | built, off in 0.5.0 | tests (real WordPress, real Yoast SEO and Rank Math where named) |
| Fixer conflict | Stray directives: a directive an SEO tool sets is deferred to it | built, off in 0.5.0 | tests (real WordPress, real Yoast SEO and Rank Math where named) |
| Fixer conflict | Dates and entity graph: through the SEO tool, never a second copy | built, off in 0.5.0 | tests (real WordPress, real Yoast SEO and Rank Math where named) |
| Fixer conflict | Applying twice is the same as once; undo restores the baseline exactly | built, off in 0.5.0 | tests (real WordPress, real Yoast SEO and Rank Math where named) |
| Diagnostic | AIR-2.5 images without alt text: listed, linked to the media library, never written | built, off in 0.5.0 | tests |
| Diagnostic | AIR-1.2 a CDN or firewall turning AI crawlers away: detected and handed on | built, off in 0.5.0 | tests |
| Diagnostic | AIR-2.1 and AIR-2.2 content that needs JavaScript: detected and handed on | built, off in 0.5.0 | tests |
| Shared | Every sentence shown comes from `strings/en.json` | done | tests |
| Shared | CI runs `check_vectors.py` and the module's fixers against every vector | done | tests + CI |

## What was checked where

- **Unit (PHPUnit, Brain Monkey, PHP 7.4, 8.1 and 8.4 in CI)**: every one of the 64 golden vectors through the fixers' pure logic and through the runner (`VectorTest`, `RunnerTest`): the exact preview, apply, undo and apply-again. The runner's rules with a fake fixer (step order, conflict refusal, stale preview, throwing apply, failed verify, undo byte for byte, superseded undo, capability and nonce before anything, no apply on a GET). The Fixes, Changes and Preview screens, the three row kinds and the points banner with Yoast active and not. RFC 9309's own examples against the parser. Structure: every `Fixer` registered, nothing outside the runner can apply, undo or clean up, no sentence in code.
- **Integration (a real WordPress in wp-env, WordPress 6.4 and the latest)**: the robots.txt adapter against all 17 vectors through the real `robots_txt` filter, options and changes tables, and a real file where a vector has one; what llms.txt lists and refuses; the directives found on pages; dates across a daylight-saving change in three site timezones; the graph each page prints and that a title cannot close its script element; the diagnostics asking only this site's own host; capability, nonce and method on every action; activation, upgrade, deactivation and both modes of uninstall.
- **Real SEO plugins (integration)**: Yoast SEO 20.0, 22.0 and 28.7 and Rank Math 1.0.220 and 1.0.280 pass: exactly one Organization, one WebSite with a search action, no repeated `@id`, no block from this plugin, and every option back to its prior value after Undo. 22.0 and 1.0.220 also pass on WordPress 6.4.
- **e2e (wp-env, the real plugin over HTTP)**: Preview, Apply, served and Undo for robots.txt, llms.txt (ETag, 304, rebuilt on publish, 404 after Undo, `/llms-full.txt` never served), the stray directives, the dates (sitemap `lastmod`), and the entity graph on a plain site, with Yoast SEO and with Rank Math.
- **Live (Pantheon dev, API origin edge)**: see *Live on the Pantheon dev site* below.
- **Plugin Check**: clean on the built zip, WordPress 7.1.3, PHP 8.1.

## Live on the Pantheon dev site

The plugin is active at 0.4.0 and its screens load. The fixers were run on the dev site through WP-CLI (`terminus wp ... eval`), as administrator 1, through the real runner, and each was undone afterwards, so the site is as it was found and the Changes tab shows the history.

| Fixer | Result on the dev site |
|---|---|
| llms.txt | Applied; `/llms.txt` served by the site's public address as `text/plain; charset=utf-8` with `nosniff` and an ETag, listing the site's two pages and posts. After Undo it answers 404. |
| Entity graph | Applied (no SEO plugin on the site); the home page printed one block with one Organization (with the legal name and `sameAs` given), one WebSite and one WebPage, no repeated `@id`, nothing else. After Undo the block is gone. |
| robots.txt | Applied, previewed and recorded correctly; **not live here, and verify said so**: Pantheon serves its own platform `robots.txt` for a dev environment before WordPress is asked, which the plugin cannot see as a file. Undone. |
| Dates | The site's dates were already honest: the preview said there was nothing to change. |
| Stray directives | The site's pages were not readable from the server (see below), so the preview said it could not read any page. |

Two things only the real host showed, both fixed:

- The site's request to its own public address was answered by Cloudflare's challenge page (a 403). The plugin compared that page with what it had made and said "not live" about a change that was live. A refused request is now "could not look", with its own sentence, and a diagnostic whose own request was refused says so and does not claim the crawlers were answered alike.
- The host's edge cache kept the old home page for a week. Pantheon's and WP Engine's documented purge functions are now detected, called after a change, and named.

## Hardening and release (READY-257), as far as it can go without Ivan

Written and tested: `docs/SECURITY.md` and a test that fails when an entry point is added without it; `docs/COMPATIBILITY.md` with the failure drills mapped to tests; a front-end performance budget test; `bin/check-version` (CI), checksums beside the zip, a tag-triggered workflow that builds the zip and attaches it to the run, `docs/RELEASING.md`, `bin/release-svn` (never run); the privacy policy paragraph; the readme's pretty-permalinks note; the Playwright suite against the stand-in API. The kit has no string for an unreachable-screen permalinks hint, so none was added; it is a kit proposal.

Needs Ivan: submitting to wordpress.org, making the repository public, a public release, a signing key, a dedicated edge test account (the Playwright run against edge and the nightly real-scan loop), the security-disclosure address and support path, the name check, listing banner, icon and screenshots.

## Decisions the contract leaves open

- The sentences a screen needs and `strings/en.json` does not have (a stale preview, a refused Undo, the uninstall setting, the diagnostics' results) are in `strings/plugin.en.json`, kept from shadowing the contract's by a test, and proposed to the kit.
- The robots.txt fixer does not treat an SEO plugin's robots.txt editor as a conflict, because the golden vector `yoast-edited-robots-is-just-the-baseline` says it is not: the editor feeds the baseline.
- Yoast SEO and Rank Math are integrated by writing the organization's name, logo and profiles to their own options (previewed as before and after, recorded, restored by Undo) and adding the rest through their schema filters. Any other plugin that prints a graph gets the exact setting to change, and the plugin prints nothing beside it.

## Not yet checked

- The whole Connect round trip and a real scan on the dev site (edge's `/connect/cms` sits behind the staging password and a WorkOS sign-in, so it needs a person), and so the Fixes tab's list fed by the API, Hand it on's prompt, and the READY-256 criterion that a before and after scan on edge moves each fixer's tests.
- Fixers' effect on a real score; the nightly real-scan loop (READY-257).
