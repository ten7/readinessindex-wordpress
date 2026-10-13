# Parity matrix

One row per thing either module does. The two columns are the WordPress plugin and the Drupal module,
and each cell is one of `done`, `in progress`, `planned` or `n/a`.

**A row with a gap in either column blocks the release of the other.** Parity is the product: a site
that moves from WordPress to Drupal should find the same screens, the same words, the same fixes
with the same results and the same undo. `n/a` is allowed only in both columns, because a capability
that does not apply to one CMS does not apply to the other either; a row that says otherwise is a gap
written down as a decision. `python3 check_vectors.py --matrix MATRIX.md` prints every row whose two
cells differ and exits 1 while there is one, and it is the release check of both repositories.

The Server column is this repository (`done` or `n/a`) and is not part of the gate. The tracking
tickets are the WordPress plugin epic READY-220 (READY-254 to 257) and the Drupal module epic
READY-250 (READY-258 to 261).

Where the truth for each kind of row lives:

| Kind of row | Truth |
|---|---|
| API call and error | `docs/cms-fixtures/openapi.json` and the golden fixtures in `docs/cms-fixtures/` |
| Screen state | `screens.md` |
| Sentence | `strings/en.json` |
| Fixer | `fixers/robots-txt`, `fixers/llms-txt`, `fixers/robots-directives`, `fixers/dates` and `fixers/entity-graph`: a spec (`<fixer>.md`) and golden vectors (`<fixer>/*.json`) |

| Area | Capability | Server | WordPress | Drupal | Notes |
|---|---|---|---|---|---|
| Connect | Connect button sends the browser to `/connect/cms` with platform, site, callback and state | done | planned | planned | WordPress READY-254, Drupal READY-258. Link rules: `docs/CMS-MODULES.md`. |
| Connect | Callback accepts the POST of key, state, plan and verify_token and checks state | done | planned | planned | The key is never in a URL, a header or a fragment. |
| Connect | Cancel is understood (`error=access_denied` and state) | done | planned | planned | Shows `connect.denied`. |
| Connect | Key kept where it is never exported or autoloaded | n/a | planned | planned | WordPress: a non-autoloaded option. Drupal: the Key module, else state, never config. |
| Connect | Disconnect and reconnect from the module | done | planned | planned | Reconnect revokes the old key for the host server side. |
| Connect | Ownership file served at `/.well-known/readinessindex-verify` with the verify_token | done | planned | planned | Exact body, https, no redirect. |
| Connect | Checkout opened with `?return=` of the module's own admin screen | done | planned | planned | `/billing/checkout/basic?return=`; the server validates and stores it. |
| API call | `GET /cms/v1/site` | done | planned | planned | Fills the Score and Fixes screens in one call. Fixtures: `site/`. |
| API call | `POST /cms/v1/scan` | done | planned | planned | Fixtures: `scan/`, `errors/quota`, `errors/unreachable-*`. |
| API call | `GET /cms/v1/scan/{scan}` | done | planned | planned | Polled while a scan runs. Fixtures: `scan-status/`. |
| API call | `GET /cms/v1/fixes/{check}` | done | planned | planned | The hand-it-on prompt. Fixtures: `fix-prompt/`. |
| API call | `POST /cms/v1/verify` | done | planned | planned | Fixtures: `verify/`, `errors/not-verified-*`. |
| API call | Calls sent server to server with a Bearer key and the `User-Agent` the contract names | n/a | planned | planned | `ReadinessIndex-<Platform>/<version> <Platform>/<version>; <site>`. |
| API call | Polling uses `If-None-Match` and treats 304 as unchanged | done | planned | planned | Fixture: `site/not-modified`. |
| API call | Validates what it reads against `docs/cms-fixtures/openapi.json` | done | planned | planned | Strict: unknown fields are a contract change, not noise. |
| API error | Handles `unauthenticated` | done | planned | planned | Fixture: `errors/unauthenticated`. |
| API error | Handles `account_disabled` | done | planned | planned | Fixture: `errors/account-disabled`. |
| API error | Handles `insufficient_scope` | done | planned | planned | Fixture: `errors/insufficient-scope`. |
| API error | Handles `rate_limited` | done | planned | planned | Fixture: `errors/rate-limited`. |
| API error | Handles `not_found` | done | planned | planned | Fixture: `errors/not-found`. |
| API error | Handles `quota` | done | planned | planned | Fixture: `errors/quota`. |
| API error | Handles `no_result` | done | planned | planned | Fixture: `errors/no-result`. |
| API error | Handles `unreachable` | done | planned | planned | Several fixtures, one per reason. |
| API error | Handles `not_verified` | done | planned | planned | Several fixtures, one per reason. |
| API error | Handles `internal_error` | done | planned | planned | Fixture: `errors/internal-error`. |
| API error | Shows the server's `message` as it is and switches only on `error` | n/a | planned | planned | `strings/en.json` has the same sentences under `error.*`. |
| API error | Backs off on `Retry-After` and never retries a `quota` | n/a | planned | planned |  |
| API error | Treats a call that cannot connect at all as `state.offline` | n/a | planned | planned | No answer is not an error key. |
| Screen: Score | State: unconnected | n/a | planned | planned | No key is stored. `screens.md`. |
| Screen: Score | State: never scanned | n/a | planned | planned | `scan.state` is `none`. `screens.md`. |
| Screen: Score | State: pending | n/a | planned | planned | `scan.state` is `pending`. `screens.md`. |
| Screen: Score | State: running | n/a | planned | planned | `scan.state` is `running`, with progress. `screens.md`. |
| Screen: Score | State: done | n/a | planned | planned | `scan.state` is `done`: score, grade, dimensions, change. `screens.md`. |
| Screen: Score | State: failed | n/a | planned | planned | `scan.state` is `failed`, with `problem`. `screens.md`. |
| Screen: Score | State: quota | n/a | planned | planned | `quota` on a scan, or `rescans_left` is 0. `screens.md`. |
| Screen: Score | State: revoked | n/a | planned | planned | `unauthenticated` on a call that had worked. `screens.md`. |
| Screen: Score | State: unreachable | n/a | planned | planned | `unreachable` on a scan, with its reason. `screens.md`. |
| Screen: Score | State: update required | n/a | planned | planned | `notices` carries `module_update_required`. `screens.md`. |
| Screen: Score | State: reconnect after revoke | n/a | planned | planned | Connect again after `revoked`. `screens.md`. |
| Screen: Score | Scan now spends a rescan and shows what is left | done | planned | planned | Never decides what the account may buy: reads `allowance`. |
| Screen: Score | Free account: five lifetime rescans, then Basic, never a pack | done | planned | planned | Read from `allowance`; the module holds no price and no rule. |
| Screen: Score | Billing off shows no plan to buy | done | planned | planned | `next_step` is null. |
| Screen: Fixes | Fixes listed in the server's order with points and who fixes them | done | planned | planned | Rank, points and `fixed_by` are the server's. |
| Screen: Fixes | Fix it here: preview, apply, undo | n/a | planned | planned | `fixes.*`. Every action needs a capability check and a nonce or token. |
| Screen: Fixes | Fix it with an SEO tool the site already has | n/a | planned | planned | Opens the tool's settings and names the field. |
| Screen: Fixes | Hand it on: the fix prompt with a copy button | done | planned | planned | The prompt is `untrusted_site_text`: escaped on output, never interpreted. |
| Screen: Fixes | Points the fixers can reach, said before anything is applied | n/a | planned | planned | `fixes.reachable`. |
| Screen: Changes | Every change listed with what, when, by whom and Undo | n/a | planned | planned | The module's own table; the record is `{fixer, prior, new}`. |
| Screen: Changes | Empty state | n/a | planned | planned | `changes.empty`. |
| Fixer | AIR-1.1 robots.txt: take blanket AI blocks off the crawlers the owner chooses | done | planned | planned | Spec and vectors: `fixers/robots-txt`. |
| Fixer | AIR-1.4 robots.txt: state what AI may do (Content Signals) | done | planned | planned | Spec and vectors: `fixers/robots-txt`. |
| Fixer | AIR-1.5 robots.txt: the policy in plain words | done | planned | planned | Spec and vectors: `fixers/robots-txt`. |
| Fixer | AIR-1.10 `/llms.txt` generated from the site's content | done | planned | planned | Spec and vectors: `fixers/llms-txt`. |
| Fixer | AIR-1.6 Stray `noarchive`, `nosnippet` and `max-snippet:0` removed | done | planned | planned | Spec and vectors: `fixers/robots-directives`. |
| Fixer | AIR-4.8 Sitemap `lastmod` from when the content really changed | done | planned | planned | Spec and vectors: `fixers/dates`. |
| Fixer | AIR-5.4 `datePublished` and `dateModified` from when the content really changed | done | planned | planned | Spec and vectors: `fixers/dates`. |
| Fixer | AIR-1.11 One linked entity graph with `@id` references | done | planned | planned | Spec and vectors: `fixers/entity-graph`. |
| Fixer | AIR-1.12 `sameAs` links to authority records | done | planned | planned | Spec and vectors: `fixers/entity-graph`. |
| Fixer | AIR-1.13 About, Contact and organizational detail | done | planned | planned | Spec and vectors: `fixers/entity-graph`. |
| Fixer | AIR-3.2 `BreadcrumbList` below the home page | done | planned | planned | Spec and vectors: `fixers/entity-graph`. |
| Fixer | AIR-3.7 `SearchAction` on the WebSite node | done | planned | planned | Spec and vectors: `fixers/entity-graph`. |
| Fixer conflict | robots.txt: a physical file wins, so previewed to paste and not applied | n/a | planned | planned | `fixers/robots-txt` |
| Fixer conflict | llms.txt: a physical file or another tool serves it | n/a | planned | planned | `fixers/llms-txt` |
| Fixer conflict | Stray directives: a directive an SEO tool sets is deferred to it | n/a | planned | planned | `fixers/robots-directives` |
| Fixer conflict | Dates and entity graph: through the SEO tool, never a second copy | n/a | planned | planned | `fixers/dates`, `fixers/entity-graph` |
| Fixer conflict | Applying twice is the same as once; undo restores the baseline exactly | n/a | planned | planned | Every vector that applies shows both. |
| Diagnostic | AIR-2.5 images without alt text: listed, linked to the media library, never written | n/a | planned | planned | `diagnostic.alt_text.*` |
| Diagnostic | AIR-1.2 a CDN or firewall turning AI crawlers away: detected and handed on | n/a | planned | planned | `diagnostic.cdn.*` |
| Diagnostic | AIR-2.1 and AIR-2.2 content that needs JavaScript: detected and handed on | n/a | planned | planned | `diagnostic.js_only.*` |
| Shared | Every sentence shown comes from `strings/en.json` | done | planned | planned | A module adds no sentence of its own. |
| Shared | CI runs `check_vectors.py` and the module's fixers against every vector | done | planned | planned | A vector the module does not match is a failing test. |
