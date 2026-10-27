# Docs

| File | What it is |
|---|---|
| `contract/` | The shared contract, copied unedited from the web repository by `bin/sync-contract`: `screens.md`, `MATRIX.md` and `PIN`, which records the web commit and a SHA-256 of every copied file. |
| `PARITY-STATUS.md` | This repository's column of the parity matrix: the Score rows this plugin ships. |
| `TESTING.md` | The unit suite, the integration suite against a real WordPress, Plugin Check on the built zip, and the stand-in API. |
| `COMPATIBILITY.md` | What was run in which cell, and the failure drills mapped to tests. |
| `SECURITY.md` | Every entry point with its capability, nonce, inputs and effects, and the reviewer's checklist. |
| `RELEASING.md` | How a release is prepared, checked and published, and what only Ivan does. |
| `DEPLOY-PANTHEON.md` | How the plugin reaches the one Pantheon dev site Ivan reviews on. |

The copied API fixtures are under `tests/fixtures/cms/`. They are not written here: the web
repository is the contract, and a fixture invented here would test the plugin against an API that
does not exist. The design is in the web repository's `docs/PLAN-cms-modules.md` (a private repository).

**The fixers are not in this tree.** The plugin that fixes (robots.txt, llms.txt, directives,
dates and the entity graph, with Preview, Apply and Undo, their golden vectors and their Yoast SEO
and Rank Math integration) is on the `fixers` branch and the `fixers-v0.6.0` tag of this
repository, with its own documentation. Nothing on `main` depends on it.
