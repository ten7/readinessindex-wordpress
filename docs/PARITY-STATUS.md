# WordPress column of the parity matrix

This repository's view of `docs/contract/MATRIX.md` (the copy of the web repository's `docs/cms-parity/MATRIX.md`, which this repository never edits). **This plugin ships the Score only.** It connects, scans and reads; it does not change the site. Every row of the matrix under Screen: Fixes, Screen: Changes, Fixer, Fixer conflict and Diagnostic is built and tested on the `fixers` branch (tag `fixers-v0.6.0`) and is **not in this tree**, so the WordPress cell of those rows reads *not in the Score-only plugin*. The matrix's own release gate (a gap in either column blocks the other's release) is about the two modules shipping together; it does not apply to this build, which Ivan decided to submit to wordpress.org without the fixers.

The Score screen also links to the full report at `<API origin>/report/<score.scan>`, which the matrix has no row for. The pinned contract is Index 12.1 (`docs/contract/PIN` names the web commit).

`Verified` says how a row is known to work: `tests` is the PHPUnit suite over the golden fixtures; `integration` is the same screens printed by a real WordPress in wp-env.

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
| Screen: Score | Five sections under one h1: score, breakdown, scan details, weekly watch (aside), connection; the newest scan's state is a status line | done | tests |
| Screen: Score | Scan again and the rescan count in one actions row; zero left disables the button; a paid plan shows the server's numbers and never the free sentence | done | tests |
| Screen: Score | Breakdown table with a header, score cell and labelled meter, sentence-case names, the weakest row or rows flagged in words | done | tests |
| Screen: Score | Weekly watch aside from `next_step`, dismissed with Not now per user | done | tests |
| Screen: Score | Connection: ownership, Connected as the key's bound host, quiet Disconnect | done | tests |
| Screen: Score | Billing off shows no plan to buy | done | tests |
| Shared | Every sentence shown comes from `strings/en.json` | done | tests |
| Screen: Fixes, Screen: Changes, Fixer, Fixer conflict, Diagnostic | every row | not in the Score-only plugin | `fixers` branch |

## What was checked where

See `docs/TESTING.md` for the layers and `docs/COMPATIBILITY.md` for the cells and failure drills. The sentences the contract lacks are in `strings/plugin.en.json`, kept from shadowing the contract's by a test.

## Not checked

- The whole Connect round trip against the real service and a real scan (it needs a person signed in on readinessindex.io). The round trip is run against the stand-in API in `tests/e2e/fake-api.php`.
