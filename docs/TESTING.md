# Testing

Three layers, each catching what the one above cannot.

## Unit (`composer test`)

PHPUnit with Brain Monkey: no WordPress, no database, no network, a few seconds. It holds:

- **Every golden vector** (`tests/fixtures/parity/fixers/`) through the fixers' pure logic
  (`VectorTest`) and through the runner (`RunnerTest`): the exact preview, apply result, undo and
  second apply, compared as JSON with object keys sorted. A robots.txt that differs by a blank line
  fails by name.
- The runner's rules with a fake fixer: step order, refusal on a conflict, a stale preview, a throwing
  apply, a failed verify, undo byte for byte, a superseded undo, capability and nonce before anything,
  and a GET that never applies.
- Structure: every `Fixer` is registered, nothing outside the runner can call `apply`, `undo` or
  `cleanup`, no sentence is written in code, every key exists, no price appears.
- The Fixes, Changes and Preview screens, row kinds and the points banner (with Yoast active and not).
- The robots.txt parser against RFC 9309's own examples.

## Integration (`composer test:integration`, inside wp-env)

A real WordPress, a real database, the plugin's real tables. It holds the parts around the logic:

- The robots.txt adapter against all seventeen vectors, through the real `robots_txt` filter and a real
  `robots.txt` where a vector has one.
- What llms.txt lists and refuses, its stored file and 304, dates across a daylight-saving change in
  three site timezones, the directives found on pages, the graph each kind of page prints, that a title
  cannot close the script element, every refusal, the diagnostics, the security of every action, and
  activation, upgrade, deactivation and uninstall.
- The real Yoast SEO and the real Rank Math, when loaded:

```bash
npx wp-env start
npx wp-env run tests-cli --env-cwd=wp-content/plugins/readiness-index \
  vendor/bin/phpunit -c phpunit-integration.xml.dist                      # no SEO plugin

npx wp-env run tests-cli wp plugin install wordpress-seo --version=20.0 --force
npx wp-env run tests-cli --env-cwd=wp-content/plugins/readiness-index \
  bash -c "READINESS_INDEX_TEST_SEO=yoast vendor/bin/phpunit -c phpunit-integration.xml.dist --filter YoastTest"
```

Tested range: Yoast SEO 20.0 to 28.7, Rank Math 1.0.220 to 1.0.280, WordPress 6.4 to the latest. CI runs
the pinned versions as blocking cells and the newest unpinned versions as cells that may fail without
failing the workflow and say so in the log. Outside the range the integration degrades to instructions
(`Integration\Yoast::supported`, `RankMath::supported`).

## End to end (`tests/e2e/lib.sh`)

Shell helpers that sign in to the wp-env development site as its default administrator and press
Preview, Apply and Undo exactly as a browser does, reading the nonce and the preview hash from the page.
`bin/wp-env-loopback` first installs a must-use plugin so the site can request itself inside the
container (the verify step); without it every verify says it could not look. Nothing here ships.

```bash
bin/wp-env-loopback
source tests/e2e/lib.sh && ri_login
ri_preview_apply llms-txt
```

What it cannot do is connect the site to Readiness Index: that needs a person at the staging password and
a WorkOS sign-in, so the Fixes tab's list (fed by the API) is not driven from here. Each preview page is
reachable by its address without the API: `admin.php?page=readiness-index-preview&fixer=robots-txt`.
