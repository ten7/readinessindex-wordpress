# Working in this repo

The Readiness Index WordPress plugin: it puts a site's own AI readiness score inside
wp-admin. It reads and reports and changes nothing about the site. **This tree is the Score only,
and it is the one submitted to wordpress.org.** The plugin that also fixes (robots.txt, llms.txt,
directives, dates and the entity graph) is on the `fixers` branch and the `fixers-v0.6.0` tag, with
its own documentation; nothing on `main` depends on it, and nothing from it comes back here without
Ivan deciding to (see *Where the fixers went*).
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
bin/sync-contract [web]     # copy fixtures, strings and screens from the web repo and re-pin them
bin/build-zip               # also fails if the zip holds a word of the fixers
bin/check-version [vX.Y.Z]  # the version is the same in the header, constant, readme and changelog
bin/deploy-pantheon --dry-run   # deploy to the Pantheon dev site; docs/DEPLOY-PANTHEON.md
tests/deploy-pantheon-test.sh   # shim-based test of that script; no network

npm install
bin/wp-env-variant plain    # or dist
npx wp-env start --update
npx wp-env run cli wp plugin check readiness-index
python3 tests/e2e/drive-screens.py   # every screen state over HTTP against the stand-in API, WP_DEBUG on
```

`dist` runs the site from the built zip's contents (run `bin/build-zip` first and restart
wp-env after every rebuild, since the zip's folder is recreated). A constant a previous run put in
wp-config.php (the stand-in's `READINESS_INDEX_API_ORIGIN`) stays there until `wp-env destroy`, so the
integration tests read `Settings::origin()` and never a literal address. Run Plugin Check there:
on the working tree it reports `.github` and `CLAUDE.md`, which never ship. Plugin Check is
installed by wp-env's `afterStart` script with WP-CLI, not listed as
`plugins` zip URLs: on Node 26 wp-env 10's zip extraction hangs, and `wp-env start` exits 0
having started only MySQL, with no message. The plugin is mapped to
`wp-content/plugins/readiness-index` explicitly, because wp-env otherwise installs it under
the checkout's directory name and `wp plugin is-active readiness-index` fails.

`composer test:integration` runs the suite that needs a real WordPress (`wp-phpunit`), inside wp-env
(`docs/TESTING.md` has the command). The unit suite must stay free of WordPress so it stays fast; a
test that needs `$wpdb` or a hook that really fires is an integration test.

---

## Rules that are not negotiable

**No remote code, ever.** The server tells the plugin the score and what the scan found. Never
`eval`, never include or download a file the server names, never turn a server string into a
callable, a hook name, a file path or a query. wordpress.org forbids it, and a compromised
readinessindex.io must not become code running on every connected site.

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

**No telemetry, and no request before Connect.** Nothing is sent that the person did not ask for by
clicking something, and nothing about visitors or users is ever sent. An unconnected site makes no
request to the service at all (`tests/Integration/ScreenTest.php` counts them).

**Nothing is autoloaded unless it is needed on every request.** Options are written with
`autoload` false; the connection key above all. Admin code is not loaded on the front end.

**The runtime has no Composer.** wordpress.org builds do not run it. `src/Autoloader.php`
is the autoloader and `vendor/` is development only. A class that works only because
Composer's autoloader found it fails on every real install and nowhere in CI.

**The key is never printed, logged or exposed.** No part of it is shown, no `error_log`, no debug
output, no response body that echoes it.

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

**Sentences the shared contract lacks live in `strings/plugin.en.json`.** `Strings::all()` merges them
under the contract's; a contract key always wins and `StringsTest` fails on the overlap, so delete a
local sentence when the kit adds it. Code still writes none. Do not add a price to either file.
`strings/en.json` is the contract's file **filtered** by `bin/filter-strings` (called by
`bin/sync-contract`): the sentences of the screens this plugin does not have are left out, and
`StaticGuardsTest` fails on a sentence that ships and that nothing asks for. A new shared sentence the
Score needs is used with `Strings::get( 'key' )`, and a new one the shared file adds that it does not
need goes in the filter's list.

**The API client reads only what the Score draws.** `SiteResponse` ignores `fixes` (the list of
outstanding tests) on purpose: reading a field nothing prints makes a change to it break every
connected site. The API calls the categories `dimensions`; the model class is `Category`.

**Uninstall removes everything and `StaticGuardsTest`-style tests keep it honest.** Every option the
code writes is in `Uninstall::OPTIONS` (`UninstallTest` greps for a new one), every transient starts
`ri_` (one `DELETE ... LIKE` removes the per-user and per-state ones, whose names cannot be listed),
both pieces of user meta are deleted, and a network asks `get_sites()` with `'number' => 0`: the
default returns one hundred sites, and every site after the hundredth would keep its key.

**A `phpcs:ignore` carries its reason, and is the last resort.** Strip them in a scratch copy and read
what surfaces before a release; the ones left are a cross-site POST that has no nonce by nature (the
state is its defense), a display-only `$_GET` flag, a local file read and the uninstall's one query.
Anything else was fixed in the code instead.

## Where the fixers went

Version 0.6.1 removed them from `main` for the wordpress.org submission: `src/Fixers`, `src/Changes`,
`src/Diagnostics`, the Fixes, Changes and Preview screens, `Features` and its constant and filter,
their tests, the Yoast SEO and Rank Math cells and the golden vectors. They are complete on the
`fixers` branch and the `fixers-v0.6.0` tag, which keeps its CLAUDE.md and its docs
(`docs/PARITY-STATUS.md`, `docs/COMPATIBILITY.md`, `docs/TESTING.md`). **A fixer is never merged back
into a build that is submitted to wordpress.org without Ivan saying so**; a plugin that rewrites
robots.txt and structured data is reviewed differently and needs its own submission decision.
`StaticGuardsTest::test_nothing_of_the_fixer_side_ships` and `bin/build-zip` both fail if it comes back.

## The zip is what ships

`bin/build-zip` builds the release from `.distignore` and then fails if the result holds
tests, `vendor`, `node_modules`, `.github`, composer files or docs, or any word of the fixers,
checking the outcome without reading `.distignore`. CI runs the same script. Add a new development-only file
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

`main` is the only long-lived branch besides `fixers`; origin is the **public** repository
`ten7/readinessindex-wordpress`. Nothing secret goes in it, in a commit message or in an issue. The web
repository it copies its contract from is private, so its CI check of the copy needs
`WEB_REPO_READ_TOKEN` and says so when it has none. Push to `main` only when Ivan has said to.

## Keeping this file current

Add to it when you learn something that would have saved an hour. Remove anything that
stops being true. It is not a second README.
