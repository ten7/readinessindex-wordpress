# Working in this repo

The Readiness Index WordPress plugin: it puts a site's own AI readiness score inside
wp-admin, and later fixes the part of the score that WordPress is in a position to fix.
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

npm install
bin/wp-env-variant plain    # or yoast, or rankmath
npx wp-env start --update
npx wp-env run cli wp plugin check readiness-index
```

`composer test:integration` runs the suite that needs a real WordPress (`wp-phpunit`).
The unit suite must stay free of WordPress so it stays fast; a test that needs `$wpdb`
or a hook that really fires is an integration test.

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

## The zip is what ships

`bin/build-zip` builds the release from `.distignore` and then fails if the result holds
tests, `vendor`, `node_modules`, `.github`, composer files or docs, checking the outcome
without reading `.distignore`. CI runs the same script. Add a new development-only file
to `.distignore`; if the check trips, the file is in the zip and a reviewer would see it.

## Git

`main` is the only long-lived branch. There is no remote yet; do not create one or push
until Ivan says so (the repository name and visibility are his to confirm).

## Keeping this file current

Add to it when you learn something that would have saved an hour. Remove anything that
stops being true. It is not a second README.
