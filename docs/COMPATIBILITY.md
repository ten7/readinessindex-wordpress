# Compatibility

Every cell says what was done to it, not what is hoped. **Run** means a test ran in that cell and passed;
**not run** means nothing was run and nothing is claimed. Dated 2026-10-26, plugin 0.6.1, the Score-only
build, against the contract of Index 12.1. The compatibility of the fixers (Yoast SEO, Rank Math, page
caches, security plugins) is on the `fixers` branch; this plugin changes nothing a cache or an SEO plugin
holds, so none of it applies here.

## Platform

| Cell | Result |
|---|---|
| WordPress 6.4 (the minimum) | Run: integration suite (PHP 8.1) and Plugin Check on the built zip, strictest form, zero errors and zero warnings. |
| WordPress 7.1.3 (latest at this writing) | Run: integration suite (PHP 8.1 and 8.4), Plugin Check on the built zip, zero errors and zero warnings, and every screen state driven over HTTP with `WP_DEBUG` on (`tests/e2e/drive-screens.py`). |
| WordPress trunk (7.2-beta1) | Run: integration suite and Plugin Check on the built zip, zero errors and zero warnings. In CI trunk is an allowed-to-fail cell, an early warning. |
| PHP 7.4 | Run in CI: lint and unit suite, and every shipped file parsed by a real 7.4 binary. The integration cells start at 8.1 because wp-env cannot build a 7.4 image. Not run locally. |
| PHP 8.1, 8.4 | Run: unit (CI), integration and Plugin Check. |
| Network activation | The notice (plugin's own screen only, dismissible per user) and the uninstall's walk over every site, asked for with `'number' => 0`, are unit-tested. **Not run on a real network.** |
| Plain permalinks | By design the ownership file cannot be verified: the web server answers `/.well-known/` itself. The readme says so; `tests/e2e/drive-screens.py` sets pretty permalinks first for that reason. |
| A non-English and a right-to-left locale | Not run. Every sentence comes from one English strings file, the admin CSS uses logical properties, and no layout assumes left to right. **The sentences are not passed through WordPress's translation functions**, because they come from a file shared with the Drupal module; a wordpress.org reviewer may ask for that. |

## Failure drills

Each drill has an expected plain message and a test that fails if the message or the behavior changes.

| Drill | What the person sees | Test |
|---|---|---|
| readinessindex.io down or no route | "Readiness Index could not be reached", with Try again; the last answer stays where there is one | `ScoreScreenTest::test_offline_offers_try_again`, `ApiClientTest::test_no_answer_at_all_is_offline_and_not_an_error_key`, and over HTTP step 6 of `drive-screens.py` |
| Revoked key | "revoked" and "reconnect" sentences with Connect; the last score stays | `ScoreScreenTest::test_revoked_shows_both_sentences_connect_again_and_keeps_the_last_score` |
| 429 | The last answer, no error; no further call until `Retry-After` | `ScoreScreenTest::test_rate_limited_shows_the_last_answer_and_waits_without_calling_again` |
| A failed scan | The server's one plain sentence and the hint; the last score stays; Scan again | `ScoreScreenTest::test_failed_shows_the_problem_the_hint_and_the_last_score_beneath` |
| A response missing a field | Shown as our side failing, never as half a screen | `ScoreScreenTest::test_a_contract_failure_is_shown_as_our_side_failing_never_as_a_half_screen`, `ApiClientTest::test_a_response_missing_a_required_field_is_a_contract_error` |
| A slow response | A request that times out is "could not be reached", retried once for a GET and never for a POST | `ApiClientTest::test_a_get_is_retried_once_and_a_post_never`, `test_a_post_that_gets_no_answer_is_not_repeated` |
| The tab closed mid-scan | The scan keeps running; WP-Cron picks it up and stops when it ends | `ScanLifecycleTest::test_the_cron_event_reschedules_itself_while_the_scan_runs`, `test_the_cron_event_removes_itself_when_the_scan_is_done` |
| Two administrators pressing Scan now | One call reaches the server | `ScanLifecycleTest::test_a_double_click_reaches_the_server_once` |
| A forged Connect return | Refused, nothing stored | `ConnectTest`, and the relay's branches in `callback_plan` |
| An administrator's browser sent a crafted link or form | Capability before nonce on every action; the disconnect confirmation opens only with its own nonce | `tests/Integration/SecurityTest.php`, `tests/Integration/ScreenTest.php` |
| The plugin deleted | Every option, transient, user meta and event it stored is gone, on every site of a network | `tests/Unit/UninstallTest.php`, `tests/Integration/UninstallTest.php` |
| An unconnected site | No request leaves it at all | `tests/Integration/ScreenTest.php`, and step 1 of `drive-screens.py` against the stand-in's request log |
