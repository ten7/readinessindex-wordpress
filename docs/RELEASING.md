# Releasing

Preparing a release is automatic and safe; **publishing is not, and is never done without Ivan's go-ahead at
that moment**: submitting to wordpress.org, making this repository public, creating a public release, signing
with a key, and creating any account, subdomain or secret.

## Prepare (no approval needed)

1. Bump the version in `readiness-index.php` (header and constant), `readme.txt` (stable tag and its changelog),
   and `CHANGELOG.md`. `bin/check-version` fails unless they agree, and CI runs it.
2. `composer check`, and `composer test:integration` inside wp-env (`docs/TESTING.md`).
3. `bin/build-zip` builds `dist/readiness-index-<version>.zip` from `.distignore`, fails if the zip holds a development
   file, and writes `dist/SHA256SUMS`.
4. Tag `v<version>` on a green `main`. The `release-zip` workflow rebuilds the zip from the tag, checks the version
   against the tag, parses every PHP file on 7.4 and attaches the zip and checksum to the run. It publishes nothing.

## Publish (needs Ivan)

- **Name check.** "Readiness Index" must be checked against wordpress.org's name rules (no trademark of another
  party, no misleading claim) before submitting. Not done.
- **Submit** the zip at https://wordpress.org/plugins/developers/add/ . Review is a queue outside our control; every
  exchange is logged in `docs/REVIEW-LOG.md` (not created until there is one).
- **After approval**, `bin/release-svn v<version>` prepares the SVN commit and stops; it refuses unless the tag is on
  origin and CI is green on that commit. `--commit` makes the commit. It has never been run.
- **Assets for the listing**: banner, icon and screenshots from the real screens (not created; needs Ivan's design
  decision). The short description (under 150 characters) and the honest statement of the free allowance are in
  `readme.txt`.
- **A signed zip for agencies while in review**: needs a signing key, which needs Ivan.
- **Security-disclosure address and support path** for the readme: needs Ivan.
- **The Playwright suite against edge** (`tests/e2e/playwright`): needs a dedicated edge test account, which needs Ivan.
