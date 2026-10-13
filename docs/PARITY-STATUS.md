# WordPress column of the parity matrix

This repository's view of `docs/contract/MATRIX.md` (the copy of the web repository's `docs/cms-parity/MATRIX.md`). The matrix itself is not edited here; the web repository owns it, and the WordPress cell of each row moves there when READY-254 is accepted. Status as of READY-254: 41 of 71 rows done.

`Verified` says how a row is known to work: `tests` is the PHPUnit suite over the golden fixtures; `live` is a run against https://edge.readinessindex.io from the Pantheon dev site.

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
| API call | `GET /cms/v1/fixes/{check}` | in progress | tests (client method only; the Fixes screen is READY-255) |
| API call | `POST /cms/v1/verify` | done | tests |
| API call | Calls sent server to server with a Bearer key and the `User-Agent` the contract names | done | tests |
| API call | Polling uses `If-None-Match` and treats 304 as unchanged | done | tests |
| API call | Validates what it reads against `docs/cms-fixtures/openapi.json` | in progress | tests (runtime checks required fields; unknown fields are ignored, the fixture test is strict) |
| API error | Handles `unauthenticated` | done | tests |
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
| Screen: Score | State: failed | done | tests |
| Screen: Score | State: quota | done | tests |
| Screen: Score | State: revoked | done | tests |
| Screen: Score | State: unreachable | done | tests |
| Screen: Score | State: update required | done | tests |
| Screen: Score | State: reconnect after revoke | done | tests |
| Screen: Score | Scan now spends a rescan and shows what is left | done | tests |
| Screen: Score | Free account: five lifetime rescans, then Basic, never a pack | done | tests |
| Screen: Score | Billing off shows no plan to buy | done | tests |
| Screen: Fixes | Fixes listed in the server's order with points and who fixes them | planned |  |
| Screen: Fixes | Fix it here: preview, apply, undo | planned |  |
| Screen: Fixes | Fix it with an SEO tool the site already has | planned |  |
| Screen: Fixes | Hand it on: the fix prompt with a copy button | planned |  |
| Screen: Fixes | Points the fixers can reach, said before anything is applied | planned |  |
| Screen: Changes | Every change listed with what, when, by whom and Undo | planned |  |
| Screen: Changes | Empty state | planned |  |
| Fixer | AIR-1.1 robots.txt: take blanket AI blocks off the crawlers the owner chooses | planned |  |
| Fixer | AIR-1.4 robots.txt: state what AI may do (Content Signals) | planned |  |
| Fixer | AIR-1.5 robots.txt: the policy in plain words | planned |  |
| Fixer | AIR-1.10 `/llms.txt` generated from the site's content | planned |  |
| Fixer | AIR-1.6 Stray `noarchive`, `nosnippet` and `max-snippet:0` removed | planned |  |
| Fixer | AIR-4.8 Sitemap `lastmod` from when the content really changed | planned |  |
| Fixer | AIR-5.4 `datePublished` and `dateModified` from when the content really changed | planned |  |
| Fixer | AIR-1.11 One linked entity graph with `@id` references | planned |  |
| Fixer | AIR-1.12 `sameAs` links to authority records | planned |  |
| Fixer | AIR-1.13 About, Contact and organizational detail | planned |  |
| Fixer | AIR-3.2 `BreadcrumbList` below the home page | planned |  |
| Fixer | AIR-3.7 `SearchAction` on the WebSite node | planned |  |
| Fixer conflict | robots.txt: a physical file wins, so previewed to paste and not applied | planned |  |
| Fixer conflict | llms.txt: a physical file or another tool serves it | planned |  |
| Fixer conflict | Stray directives: a directive an SEO tool sets is deferred to it | planned |  |
| Fixer conflict | Dates and entity graph: through the SEO tool, never a second copy | planned |  |
| Fixer conflict | Applying twice is the same as once; undo restores the baseline exactly | planned |  |
| Diagnostic | AIR-2.5 images without alt text: listed, linked to the media library, never written | planned |  |
| Diagnostic | AIR-1.2 a CDN or firewall turning AI crawlers away: detected and handed on | planned |  |
| Diagnostic | AIR-2.1 and AIR-2.2 content that needs JavaScript: detected and handed on | planned |  |
| Shared | Every sentence shown comes from `strings/en.json` | done | tests |
| Shared | CI runs `check_vectors.py` and the module's fixers against every vector | planned |  |
