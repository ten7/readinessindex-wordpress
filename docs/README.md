# Docs

| File | What it is |
|---|---|
| `contract/` | The shared parity contract, copied unedited from the web repository by `bin/sync-contract`: `screens.md`, `MATRIX.md` and `PIN`, which records the web commit and a SHA-256 of every copied file. |
| `PARITY-STATUS.md` | This repository's column of the parity matrix, and what was checked where. |
| `TESTING.md` | The unit suite, the integration suite against a real WordPress and real SEO plugins, and the end-to-end helpers. |
| `DEPLOY-PANTHEON.md` | How the plugin reaches the one Pantheon dev site Ivan reviews on. |

The copied API fixtures, golden fixer vectors, their schema and checker are under `tests/fixtures/`.
They are not written here: the web repository is the contract, and a fixture invented here would
test the plugin against an API that does not exist. The design is in the web repository's
`docs/PLAN-cms-modules.md`.
