# Releasing

This repository is **public** (`ten7/readinessindex-wordpress`), so a tag, a release and anything in a commit
is world-readable the moment it is pushed. Preparing a release is automatic and safe; **submitting to wordpress.org,
running `bin/release-svn`, signing with a key and creating any account, subdomain or secret are not, and are never
done without Ivan's go-ahead at that moment.** A GitHub release of the zip is approved for this plugin.

The build that is submitted is the **Score only**. The fixers are on the `fixers` branch and are never part of a
submitted zip (`bin/build-zip` fails if a word of them is in it).

## Prepare (no approval needed)

1. Bump the version in `readiness-index.php` (header and constant), `readme.txt` (stable tag and its changelog),
   and `CHANGELOG.md`. `bin/check-version` fails unless they agree, and CI runs it.
2. Bring the contract up to date if the web repository's production `main` moved: clone it to a scratch directory
   at `origin/main` (the script refuses a tree with uncommitted contract files, and records the commit it reads) and run
   `bin/sync-contract <that directory>`. Fix any test fallout in the Score code or tests; the plugin has to render the
   contract production serves.
3. `composer check` (lint, then the unit suite), and `composer test:integration` inside wp-env (`docs/TESTING.md`).
4. `bin/build-zip` builds `dist/readiness-index-<version>.zip` from `.distignore`, fails if the zip holds a development
   file or any fixer code, and writes `dist/SHA256SUMS`.
5. **Plugin Check on the built zip, strictest form, on the latest WordPress and on trunk**: zero errors and zero warnings.
   `bin/wp-env-variant dist`, `npx wp-env start --update`, then
   (`strict-table` exits non-zero on a warning too)
   `npx wp-env run cli wp plugin check readiness-index --format=strict-table --severity=0 --include-experimental --include-low-severity-errors --include-low-severity-warnings`.
   Then strip the remaining `phpcs:ignore` comments in a scratch copy, run PHPCS, and read what surfaces: fix what is real
   instead of ignoring it (`CLAUDE.md`).
6. Drive every screen state against the stand-in API (`tests/e2e/fake-api.php`) with `WP_DEBUG` on and confirm no
   request reaches the service before Connect.
7. Commit, push `main` only when Ivan has said to, wait for CI to be green, then tag `v<version>`. The `release-zip`
   workflow rebuilds the zip from the tag, checks the version against the tag and parses every PHP file on 7.4.

## GitHub release (approved for this plugin)

`gh release create v<version> dist/readiness-index-<version>.zip dist/SHA256SUMS --title "Readiness Index <version>"
--notes-file <the changelog entry>`. Attach exactly the zip `bin/build-zip` built and its `SHA256SUMS`; verify
the checksum of the downloaded asset against the file before saying it is done.

## Publish (needs Ivan)

- **Name check.** "Readiness Index" must be checked against wordpress.org's name rules (no trademark of another
  party, no misleading claim) before submitting. The team may ask for a change; the slug is `readiness-index`.
- **Submit** the zip at https://wordpress.org/plugins/developers/add/ : upload `dist/readiness-index-<version>.zip`
  from the GitHub release (the file a person downloads, not a rebuild), signed in as the wordpress.org account that
  will own the plugin. Review is a queue outside our control; every exchange is logged in `docs/REVIEW-LOG.md` (not
  created until there is one).
- **A `Contributors:` line** in `readme.txt` names wordpress.org usernames, which only the owning account can supply;
  it is added when the submission account is known.
- **After approval**, `bin/release-svn v<version>` prepares the SVN commit and stops; it refuses unless the tag is on
  origin and CI is green on that commit. `--commit` makes the commit. It has never been run.
- **Assets for the listing**: banner, icon and screenshots from the real screens (not created; needs Ivan's design
  decision). The short description (under 150 characters) and the honest statement of the free allowance are in
  `readme.txt`.
- **A signed zip for agencies while in review**: needs a signing key, which needs Ivan.
- **Security-disclosure address and support path** for the readme: needs Ivan.
