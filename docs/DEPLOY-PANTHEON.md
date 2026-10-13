# Deploying to the Pantheon dev site

Ivan reviews the plugin on one Pantheon dev environment. The plugin repository (GitHub)
and that site's repository (Pantheon) are separate git repositories with no link, and
`bin/deploy-pantheon` is the link.

```bash
bin/deploy-pantheon --dry-run                      # everything except pushes and terminus writes
bin/deploy-pantheon                                # edge as the API origin, read from the web repo
bin/deploy-pantheon --origin https://example.test  # a different API origin
bin/deploy-pantheon --allow-dirty                  # deploy an uncommitted working tree
```

Requires `terminus` already logged in (the script never runs `terminus auth:*`), `rsync`,
`zip`, `python3` and an SSH key registered with Pantheon for the git push.

## The allowlist

The script is hard-coded to one site, `readiness-index-wordpress-plugin`, and one
environment, `dev`. A `--site` or `--env` that is not exactly those exits non-zero before
any other work, and before the first terminus call. The account holds many client sites;
none of them is ever contacted. `tests/deploy-pantheon-test.sh` covers the refusals,
including a `site.env` spelling and `live`. Do not make the list configurable.

## What it does, in order

1. Refuses a dirty plugin working tree unless `--allow-dirty` is given; the Pantheon
   commit message records the plugin's short hash and whether the tree was dirty.
2. Builds the release zip with `bin/build-zip` and unpacks it into a `mktemp -d`
   directory outside both repositories. Only the zip is shipped, so a development file
   cannot be deployed.
3. Reads the connection mode. In SFTP mode it refuses when `terminus env:diffstat` shows
   uncommitted changes (they would be orphaned), then runs `connection:set ... git`.
4. Clones the Pantheon repository into the temporary directory. The URL is parsed out of
   `terminus connection:info`'s `git_command` and must be an `ssh://` URL on `drush.in`;
   the string is never evaluated. `rsync --delete` replaces
   `wp-content/plugins/readiness-index/` and nothing else.
5. Writes the API origin into the clone's `wp-config.php` (below), commits, and pushes to
   `master`. A rerun that changes nothing makes no commit.
6. Waits for the code-sync workflow with `terminus workflow:wait`, activates the plugin
   with `terminus wp ... plugin activate readiness-index`, then prints `eval 'echo 1;'`,
   `plugin list`, the front page's HTTP status, the dev URL and the wp-admin path.

`--dry-run` clones, copies and commits locally, and prints each skipped write as
`DRY RUN, not run: ...`.

## The API origin

`READINESS_INDEX_API_ORIGIN` is defined only in the Pantheon repository's `wp-config.php`,
never in the plugin repository, in a block between `// BEGIN readiness-index api origin`
and `// END readiness-index api origin` placed above the "That's all, stop editing" line.
A rerun replaces the block. Without `--origin` the script reads the Edge row of the web
repository's `docs/EDGE.md` (override the path with `READINESS_EDGE_DOC`) and says which
host it found. The value must be `https://host[:port]` with no path, because it is
written into PHP source.

## Logging in

The script creates and prints no credentials. To sign in to the dev site's wp-admin, use
the Pantheon dashboard's login link for the site, or list users with
`terminus wp readiness-index-wordpress-plugin.dev -- user list`.

## Tests

`tests/deploy-pantheon-test.sh` runs the script against shim `terminus`, `git`, `curl`
commands and checks the allowlist, dirty-tree and uncommitted-SFTP refusals, the dry-run
command sequence, the real-run sequence, the wp-config block and its idempotence. CI runs
it in the release-zip job.
