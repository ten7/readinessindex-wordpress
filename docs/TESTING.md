# Testing

Three layers, each catching what the one above cannot. This is the Score-only plugin; the tests of
the fixers, with their golden vectors and the Yoast SEO and Rank Math cells, are on the `fixers`
branch.

## Unit (`composer test`)

PHPUnit with Brain Monkey: no WordPress, no database, no network, under a second. It holds:

- **The contract.** Every golden API fixture in `tests/fixtures/cms/` against the OpenAPI document,
  strictly, and every copied file against the hash in `docs/contract/PIN` (`ContractTest`).
- **The client** reading each fixture, ETags and 304, the retry of a GET and never of a POST, no
  redirect followed, TLS never turned off (`ApiClientTest`, `StaticGuardsTest`).
- **Every state of the Score screen** from the fixtures: unconnected, never scanned, pending,
  running, done, failed, revoked, disabled, offline, rate limited, quota, and a response missing a
  field (`ScoreScreenTest`, `ScoreLayoutTest`), with every string a scan could contain escaped
  wherever it lands (`EscapingTest`).
- **Connect** and its cross-site return, the relay, state spending, key and token shape
  (`ConnectTest`), the ownership file (`OwnershipFileTest`), the pre-flight notices
  (`PreflightTest`), the scan lifecycle and its double click (`ScanLifecycleTest`), the upgrade
  offer built from the allowance alone (`UpgradeTest`) and the privacy paragraph (`PrivacyTest`).
- **Uninstall**: every option the code writes is one it removes, every transient name carries the
  prefix it deletes, both pieces of user meta go, and a network uninstall asks `get_sites()` for
  every site (`UninstallTest`).
- **Structure** (`StaticGuardsTest`, `HookAllowlistTest`): the API client is the only file that makes
  a request, nothing is logged, dumped or evaluated, no price or per-period figure is anywhere, no
  sentence is written in code and every key exists, every sentence that ships is one the code asks
  for, every option is written non-autoloaded, no fixer code or hook comes back, every
  `phpcs:ignore` says why, and every entry point is in `docs/SECURITY.md`.

## Integration (`composer test:integration`, inside wp-env)

A real WordPress with `WP_DEBUG` on, a real database and the plugin loaded as a must-use plugin:

- Capability before nonce on every admin-post action, and nothing registered for a visitor who is
  not signed in except the Connect return (`SecurityTest`).
- The Score screen for every site fixture, printed by real WordPress without a PHP notice or
  deprecation, with exactly one request to the service, and **none before Connect** (`ScreenTest`).
- Uninstall against a real options table, real transients, real user meta and real cron events,
  and that every option the plugin stores is not autoloaded (`UninstallTest`).

```bash
bin/wp-env-variant plain
npx wp-env start --update
npx wp-env run tests-cli --env-cwd=wp-content/plugins/readiness-index \
  vendor/bin/phpunit -c phpunit-integration.xml.dist
```

CI runs the same suite on WordPress 6.4 (PHP 8.1), the latest release and trunk (PHP 8.4, trunk
allowed to fail as an early warning).

## Plugin Check, on the built zip

```bash
bin/build-zip
bin/wp-env-variant dist && npx wp-env start --update
npx wp-env run cli wp plugin check readiness-index --format=table --severity=0 \
  --include-experimental --include-low-severity-errors --include-low-severity-warnings
```

Run it against the zip's contents, not the working tree (the tree has `.github` and `CLAUDE.md`, which
never ship), on the latest WordPress and on trunk. The release needs zero errors and zero warnings, and
Plugin Check prints nothing at all when there are none. **Plugin Check honors `phpcs:ignore` comments**, so a
clean run does not say what the comments hide. Before a release, copy the built folder to a scratch
directory, strip every `// phpcs:ignore` line from the PHP, point `.wp-env.override.json` at that directory
and run it again: what surfaces must be justified in the comment or fixed in the code. At 0.6.1 two things
surface and both are deliberate: the cross-site Connect return reads `$_POST` without a nonce (its
single-use state is the defense, and a nonce cannot cross sites), and the uninstall deletes the `ri_`
transients with one prepared `DELETE ... LIKE` because their names carry a user id or a hash.

## The stand-in API and the screen driver (`tests/e2e/`)

`fake-api.php` is a PHP built-in-server script that answers the four calls the plugin makes from the
golden fixtures, any fixture for any route (`/__state?site=site/running`), and that records each request
it receives (`/__log`). Its `/connect/cms` page answers like the real consent page after approval, with a
made-up key of the right shape. It checks no key and stores nothing but which fixture to answer with.

`drive-screens.py` starts it and drives the real plugin in wp-env over HTTP as the development
administrator: an unconnected site opened on every kind of screen (and the front end and the ownership
address) with **zero requests reaching the stand-in**, then Connect through the cross-site return and its
relay, every site state, Scan again, Check ownership, Not now, the disconnect confirmation and Disconnect,
and the service going away. At every step it also reads `debug.log`, which must stay empty with `WP_DEBUG`
on. Its header says how to set wp-env up (the built zip's folder mapped in, and
`READINESS_INDEX_API_ORIGIN` pointed at `http://host.docker.internal:8099`).

What nothing here can do is connect a site to the real service: that needs a person signed in on
readinessindex.io.
