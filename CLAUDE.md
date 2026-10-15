# Working in this repo

The Readiness Index WordPress plugin: it puts a site's own AI readiness score inside
wp-admin. It will later fix the part of the score that WordPress is in a position to fix; that
code is built and tested but switched off (see *The fixers*).
Slug and directory `readiness-index`, text domain `readiness-index`, GPLv2 or later,
`Requires at least: 6.4`, `Requires PHP: 7.4`. It is built for wordpress.org review, which
is stricter than WordPress itself and is the reason for most rules below.

**This file is the operating manual, not the documentation.** The design is in the web
repository (`ai-readiness-index`, `docs/PLAN-cms-modules.md`); the API contract is the
web repository's `docs/cms-fixtures/`, copied into `docs/` here and never written here.
What follows is the set of rules that are not obvious from reading the code.

---

## Commands

```bash
composer install            # development tools only; nothing in vendor/ ships
composer check              # lint then unit tests; run before every commit
composer lint               # PHPCS: WordPress Coding Standards, PHP 7.4 to latest
composer lint:fix           # phpcbf
composer test               # PHPUnit unit suite (Brain Monkey, no WordPress)
bin/build-zip               # build dist/readiness-index-<version>.zip and fail on dev files
bin/sync-contract           # copy fixtures, strings and screens from the web repo and re-pin them
bin/deploy-pantheon --dry-run   # deploy to the Pantheon dev site; docs/DEPLOY-PANTHEON.md
tests/deploy-pantheon-test.sh   # shim-based test of that script; no network

npm install
bin/wp-env-variant plain    # or yoast, rankmath, dist
npx wp-env start --update
npx wp-env run cli wp plugin check readiness-index
```

`dist` runs the site from the built zip's contents (run `bin/build-zip` first and restart
wp-env after every rebuild, since the zip's folder is recreated). Run Plugin Check there:
on the working tree it reports `.github` and `CLAUDE.md`, which never ship. Plugin Check and
the SEO plugins are installed by wp-env's `afterStart` script with WP-CLI, not listed as
`plugins` zip URLs: on Node 26 wp-env 10's zip extraction hangs, and `wp-env start` exits 0
having started only MySQL, with no message. The plugin is mapped to
`wp-content/plugins/readiness-index` explicitly, because wp-env otherwise installs it under
the checkout's directory name and `wp plugin is-active readiness-index` fails.

`composer test:integration` runs the suite that needs a real WordPress (`wp-phpunit`), inside wp-env
(`docs/TESTING.md` has the command, and how Yoast SEO and Rank Math are loaded). The unit suite must
stay free of WordPress so it stays fast; a test that needs `$wpdb` or a hook that really fires is an
integration test.

---

## Rules that are not negotiable

**No remote code, ever.** The server tells the plugin which test failed and what the
evidence was. The change itself is code shipped inside the plugin. Never `eval`, never
include or download a file the server names, never turn a server string into a callable,
a hook name, a file path or a query. wordpress.org forbids it, and a compromised
readinessindex.io must not become write access to every connected site.

**Escape late, sanitize early.** Input is sanitized the moment it enters (`$_POST`,
`$_GET`, the API response, an option read back); output is escaped at the point it is
printed, with the function that matches the context (`esc_html`, `esc_attr`, `esc_url`,
`wp_kses`). Everything read from a scan is data about a site written by that site, so it
is escaped on every output and never interpreted.

**Nonce and capability on every action.** Every form, `admin-post`, `admin-ajax` and REST
handler checks `current_user_can( 'manage_options' )` and a nonce before it does anything,
and the capability check comes first so a stranger learns nothing from a nonce error.

**One API client.** Every outbound call goes through the one API client class and nowhere
else. `wp_remote_*` and `curl_*` appear only in it, and a test greps for both. The only
outbound host is readinessindex.io; `readme.txt` says so, and it must keep saying so.

**No telemetry.** Nothing is sent that the person did not ask for by clicking something,
and nothing about visitors or users is ever sent.

**Nothing is autoloaded unless it is needed on every request.** Options are written with
`autoload` false; the connection key above all. Admin code is not loaded on the front end.

**The runtime has no Composer.** wordpress.org builds do not run it. `src/Autoloader.php`
is the autoloader and `vendor/` is development only. A class that works only because
Composer's autoloader found it fails on every real install and nowhere in CI.

**The key is never printed, logged or exposed.** Last four characters at most, no
`error_log`, no debug output, no response body that echoes it.

**American English.** Not a preference; it is wrong otherwise.

**No time estimates anywhere.** Not in code, comments, docs, commits or the readme.

---

## Prose style

Comments and commit messages are written to be read by Ivan, who notices.

- Say **why**, not what. A comment restating the code earns nothing.
- Name the bug a guard prevents, so nobody removes it as redundant.
- No filler, no hedging, no exclamation marks. Plain declarative sentences.
- Commit messages explain the reasoning and the consequence, in prose paragraphs, not
  bullet lists of files changed. Each ends with:

  `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`

---

## The contract is copied, pinned and never edited

`tests/fixtures/cms/` (the golden API fixtures and `openapi.json`), `strings/en.json` (every
sentence the plugin shows; it ships in the zip) and `docs/contract/` (`screens.md`, `MATRIX.md`)
are copied from the web repository by `bin/sync-contract`, which writes `docs/contract/PIN`: the
web commit and a SHA-256 of each file. `tests/Unit/ContractTest.php` fails on a hand edit or a
stale pin, and checks every fixture strictly against the OpenAPI document. The runtime models
ignore a field they do not know (an additive server change must not break a site that has not
updated) and raise a `contract` error for a missing one; the strictness lives in that test.
A sentence is never written in code: `StaticGuardsTest` fails on one, on a missing string key,
on a price or a per-period figure, and on any `wp_remote_` outside `src/Api/Client.php`.

**The key and token patterns end in `D`.** Without it `$` also matches before a trailing
newline, and a key with one would be stored and sent in an Authorization header.

**Connect's return is a cross-site POST, which a SameSite=Lax cookie does not accompany.**
`Actions::handle_callback` is also registered `admin_post_nopriv_`; a pending state is relayed
once from this site to itself (same-site, so the cookie travels) and the signed-in branch does
the work. Do not "simplify" this to a `manage_options` check alone: Connect then fails in
browsers that default to Lax, with no error anywhere.

**Tests that fake `time()` need `patchwork.json`** (`redefinable-internals`), which is excluded
from the zip. A handler never calls `exit` where a test can reach it: the decision is a pure
method (`Actions::callback_plan`) and the handler only executes it, because an `exit` inside
PHPUnit ends the whole run silently.

## The fixers

**0.5.0 ships the Score alone, and `Features::fixers()` is the one switch** (the constant `READINESS_INDEX_FIXERS`,
default off, or the `readiness_index_fixers` filter, strictly `true`). It is checked where things are registered
and never inside a fixer: `Plugin::wire_fixers` (on `plugins_loaded` 20) adds the fixer hooks, the apply/undo,
save-settings, diagnose and fix-prompt actions and the Plugins-screen note; `Menu::add_menu` adds the Fixes,
Changes and preview pages; `Tabs::render` prints no tabs. Off, nothing of the fixer side runs, so a change a
site applied under 0.4.0 is not served (its record stays; turning the switch on resumes it), and activation and
upgrade store no table and no autoloaded option. `Uninstall` ignores the switch on purpose, so deleting the
plugin still cleans up whatever the fixers ever stored. Unit tests of the fixer side call `fixers_on()`; the
integration bootstrap defines the constant. A new fixer registration goes inside `wire_fixers` or a
`register_fixers()` it calls, and `ScoreOnlyTest` lists the hooks that must not appear when off.

**Every change goes through `Fixers\Runner`, and nothing else may apply, undo or clean up.** The runner
checks capability and then nonce, refuses anything but a POST, compares the preview's hash with the one
the Apply form carried, refuses on a conflict, restores the prior value if `apply` throws, writes a row
and verifies. A fixer's `apply`, `undo` and `cleanup` take a `Permit` only the runner can issue, and
`RegistryTest` greps for a call from anywhere else. A fixer is a filter over what WordPress already
produces, and the only thing stored is the owner's choices (one autoloaded option, `State::OPTION`, the
one the front end needs on every request), which is what makes Undo exact.

**A fixer's logic is pure and is held to the golden vectors; the adapter around it is not.**
`src/Fixers/Logic/` has no WordPress in it and reproduces `tests/fixtures/parity/fixers/` to the
character (`VectorTest`). `Impl/` builds the facts from WordPress. Never read what the site serves now
to find the baseline: it contains the fixer's own change. `RobotsTxt::baseline()` takes the fixer's own
filter out, runs the others and puts it back; a real file in the web root is the baseline when there is one.

**Feature detection runs on `plugins_loaded`, never when this plugin loads.** WordPress loads plugins
alphabetically, so `readiness-index` loads before `wordpress-seo`: `defined('WPSEO_VERSION')` is false
at load and the integration silently registered nothing. `Hooks::register` is on `plugins_loaded` 20.

**Yoast derives the logo's size and type lazily, and Rank Math keeps the settings it read at the start of
the request.** `Integration\Yoast::write` records every key that differs plus `company_logo_meta`, and
`RankMath` calls `settings->reset()`; without both, Undo is not byte for byte. Both were found by the
integration suite, not by reading.

**Sentences the shared contract lacks live in `strings/plugin.en.json`.** `Strings::all()` merges them
under the contract's; a contract key always wins and `StringsTest` fails on the overlap, so delete a
local sentence when the kit adds it. Code still writes none. Do not add a price to either file.

**Diagnostics and the verify step request only this site's own host,** through `Http\SiteFetcher`, the
one file besides the API client allowed to make a request (`StaticGuardsTest`). Inside wp-env that needs
`bin/wp-env-loopback`.

**A site's request to itself may be refused, and a refusal is not "not live".** On the Pantheon dev site
the plugin's own request to its public address was answered by Cloudflare's challenge page (a 403) and
the change, which was live, was reported as not. `SiteFetcher::was_turned_away` (401, 403, 407, 429,
503, a CDN mitigation header) turns every verify and diagnostic into "could not look"; a 404 is still
"not live". The same host's edge kept the old home page for a week, so `PageCache` knows Pantheon's
and WP Engine's purge functions as well as the four caching plugins. A dev environment's platform
serves its own `robots.txt` before WordPress is asked, which no file check can see: verify says so.

**To see the Fixes tab without a connected site,** run `php -S 0.0.0.0:8099 tests/e2e/fake-api.php` and
point wp-env at it (the file says how). It serves the golden fixtures and nothing else.

## The zip is what ships

`bin/build-zip` builds the release from `.distignore` and then fails if the result holds
tests, `vendor`, `node_modules`, `.github`, composer files or docs, checking the outcome
without reading `.distignore`. CI runs the same script. Add a new development-only file
to `.distignore`; if the check trips, the file is in the zip and a reviewer would see it.

## Pantheon

`bin/deploy-pantheon` ships the built zip to one Pantheon dev site and nowhere else. Its
allowlist (site `readiness-index-wordpress-plugin`, environment `dev`) is hard-coded on
purpose: the account holds client sites, and the script must never run a command against
one. Never widen it, never add a flag that bypasses it, never run `terminus auth:*` or read
the machine token. `READINESS_INDEX_API_ORIGIN` is defined only in the Pantheon repository's
`wp-config.php` by that script, never in this repository. See `docs/DEPLOY-PANTHEON.md`.

## CI and PHP 7.4

The plugin supports PHP 7.4, and three things cover it: the lint and unit jobs run on 7.4,
and the release-zip job runs `php -l` over every PHP file of the built zip on 7.4. The
wp-env activation and Plugin Check cells start at PHP 8.1 on purpose: wp-env builds its
7.4 image from Debian bullseye, whose apt mirrors return 404, so those cells failed on
infrastructure. Do not add 7.4 back to that matrix until wp-env can build it.

## Git

`main` is the only long-lived branch; origin is the private `ten7/readinessindex-wordpress`.
Push to `main` only when Ivan has said to.

## Keeping this file current

Add to it when you learn something that would have saved an hour. Remove anything that
stops being true. It is not a second README.
