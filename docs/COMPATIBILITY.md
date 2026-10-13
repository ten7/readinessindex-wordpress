# Compatibility

Every cell says what was done to it, not what is hoped. **Run** means a test ran in that cell and passed
(CI, or by hand where noted); **by detection** means the plugin recognizes it by a function or constant and
the code path is tested with that detection forced, but the real product was not run; **not run** means nothing
was run and nothing is claimed. Dated 2026-10-13, plugin 0.4.0.

## Platform

| Cell | Result |
|---|---|
| WordPress 6.4 (the minimum) | Run: unit, integration, Plugin Check (CI). Core's sitemap does not emit `lastmod` here; pinned by a test. |
| WordPress latest (7.1.3) | Run: unit, integration, Plugin Check, e2e over HTTP (CI and by hand). Core emits `lastmod`; pinned. |
| WordPress trunk | Run in CI as an allowed-to-fail cell (an early warning). Green at this writing. |
| PHP 7.4 | Run: lint and unit (CI), and every shipped file parsed by a real 7.4 binary. The integration cells start at 8.1 because wp-env cannot build a 7.4 image. |
| PHP 8.1, 8.4 | Run: unit, integration, Plugin Check (CI). |
| Subdirectory multisite sub-site | By detection: robots.txt is refused with its reason (`subdirectory_site`). Not run on a real network. |
| Network activation | The notice and per-site table creation are code-reviewed; not run on a real network. |

## SEO plugins

| Cell | Result |
|---|---|
| None | Run (all of the above). |
| Yoast SEO | Run against the real plugin at 20.0, 22.0 (also on WordPress 6.4), 28.7 and latest: one Organization, one WebSite with a search action, no repeated `@id`, no block of ours, options restored byte for byte. |
| Yoast SEO Premium | Not run. Same options and filters as the free plugin; nothing Premium-specific is read. |
| Rank Math | Run against the real plugin at 1.0.220, 1.0.280 and latest. |
| All in One SEO, SEOPress, The SEO Framework, Schema Pro | By detection: the entity graph is refused (`other_tool`) and the row becomes Fix it with, naming the setting. Not run against the real plugins. |
| Yoast and Rank Math both active | Run (real WordPress, detection forced): refused with its sentence. |
| WooCommerce | By detection only; its product structured data is left alone and is not a conflict. Not run. |

## Caching, security and builders

| Cell | Result |
|---|---|
| WP Rocket, W3 Total Cache, LiteSpeed Cache, Cloudflare APO | By detection and by the documented purge entry point; a test checks every named cache has both. Not run against the real plugins. |
| Pantheon and WP Engine edge caches | Pantheon run live on the dev site (the purge ran and the home page was fresh immediately). WP Engine by detection only. |
| WP Super Cache | Not run, not detected: a change behind it is reported "not live" without naming it. |
| Wordfence, Sucuri, Solid Security, a host WAF | The plugin's own request to the site may be refused; that is "could not look" and not "not live" (run live against Cloudflare's challenge on Pantheon; unit-tested for every refusal status). Not run against the real security plugins. |
| Gutenberg, Elementor, Divi | The JavaScript diagnostic names builder animation by markup; not run against real builder pages. |
| A physical `robots.txt` and `llms.txt` | Run: refused with the exact text to paste (all 17 robots.txt vectors, llms.txt integration tests). |
| A site behind basic auth, or in maintenance mode | By the same path as a refused request: "could not look". Not run. |
| A non-English and a right-to-left locale | Not run. Every sentence comes from one English strings file, the admin CSS uses logical properties, and no layout assumes left to right. |

## Failure drills

Each drill has an expected plain message and a test that fails if the message or the behavior changes.

| Drill | What the person sees | Test |
|---|---|---|
| readinessindex.io down or no route | "Readiness Index could not be reached", with Try again; the last answer stays where there is one | `ScoreScreenTest::test_offline_offers_try_again`, `ApiClientTest::test_no_answer_at_all_is_offline_and_not_an_error_key` |
| Revoked key | "revoked" and "reconnect" sentences with Connect; the last score stays | `ScoreScreenTest::test_revoked_shows_both_sentences_connect_again_and_keeps_the_last_score` |
| 429 | The last answer, no error; no further call until `Retry-After` | `ScoreScreenTest::test_rate_limited_shows_the_last_answer_and_waits_without_calling_again` |
| A failed scan | The server's one plain sentence and the hint; the last score stays; Scan again | `ScoreScreenTest::test_failed_shows_the_problem_the_hint_and_the_last_score_beneath` |
| A response missing a field | Shown as our side failing, never as half a screen | `ScoreScreenTest::test_a_contract_failure_is_shown_as_our_side_failing_never_as_a_half_screen`, `ApiClientTest::test_a_response_missing_a_required_field_is_a_contract_error` |
| A slow response | A request that times out is "could not be reached", retried once for a GET and never for a POST | `ApiClientTest::test_a_get_is_retried_once_and_a_post_never`, `test_a_post_that_gets_no_answer_is_not_repeated` |
| The tab closed mid-scan | The scan keeps running; WP-Cron picks it up and stops when it ends | `ScanLifecycleTest::test_the_cron_event_reschedules_itself_while_the_scan_runs`, `test_the_cron_event_removes_itself_when_the_scan_is_done` |
| Two administrators pressing Apply | One change, one row; the second is "nothing to change" | `RunnerTest::test_two_administrators_pressing_apply_on_the_same_preview_change_the_site_once` |
| Two administrators pressing Scan now | One call reaches the server | `ScanLifecycleTest::test_a_double_click_reaches_the_server_once` |
| A stale preview | "Your site changed after you previewed this, so nothing was applied", with the new preview | `RunnerTest::test_a_stale_preview_is_not_applied_and_is_shown_again` |
| Undo after a manual edit | "A later change, or an edit made by hand, has replaced what this change wrote ... nothing was changed" | `RunnerTest::test_an_undo_is_refused_after_the_value_was_edited_by_hand`, `YoastTest::test_an_edit_made_in_yoast_after_apply_blocks_an_undo_that_would_overwrite_it` |
| The site's own request refused by a CDN or firewall | "This module could not look at your site to check" | `LlmsTxtTest::test_verify_says_it_could_not_look_when_its_own_request_is_challenged_and_not_live_when_the_route_is_missing` |

## Performance budget

With nothing applied, the plugin adds no database query and under a millisecond to a front-end request
(`tests/Integration/PerformanceBudgetTest.php`): the one option it reads is autoloaded and no hook is added.
