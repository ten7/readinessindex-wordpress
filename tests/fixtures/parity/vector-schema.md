# Vector schema

A **vector** is one situation for one fixer: the state of a site, the owner's choices, and the exact
preview, result, undo and second apply that follow. `fixers/<fixer>/<case>.json`, one file per
situation. The vectors are *computed* by a reference implementation in the web repository
(`tests/cms_parity/reference.py`) and written by `scripts/regen-cms-contract`; a test regenerates all of
them and fails on any difference, so a vector is never edited by hand. Neither module imports the
reference. Each module is tested against the files: feed `given` to the module's own fixer and compare
every step.

`check_vectors.py` (standard library only) validates the shape described here and the rules that tie
the steps together; it does not run a fixer.

## The envelope

```json
{
  "schema": 1,
  "fixer": "robots-txt",
  "case": "physical-file-wins",
  "title": "A real robots.txt on the server beats anything the CMS generates ...",
  "covers": ["AIR-1.1", "AIR-1.4", "AIR-1.5"],
  "tags": ["conflict"],
  "given":    { ... },
  "preview":  { ... },
  "apply":    { ... },
  "undo":     { ... },
  "reapply":  { ... }
}
```

| Key | Meaning |
|---|---|
| `schema` | `1`. A change a module has to notice raises it. |
| `fixer` | `robots-txt`, `llms-txt`, `robots-directives`, `dates` or `entity-graph`. The file is in a directory of that name. |
| `case` | The situation, in kebab-case. It equals the file name without `.json`. |
| `title` | One sentence a person can read to know what the vector is about. |
| `covers` | The Index tests the situation moves, by AIR id. The union over a fixer's vectors is exactly the tests the fixer is for. |
| `tags` | What it demonstrates: `apply`, `conflict`, `defer`, `noop`, `undo`, `idempotent`. Each fixer has a vector for every tag that can happen to it. |

## `given`

Everything the fixer may know, and nothing else. Every fixer's `given` has `site` (`{url, name}`, plus
`language`, `description`, `about_url` and `contact_url` where the fixer uses them), `tooling` (the SEO
and related tools active on the site, as ids such as `yoast`, `rank_math`, `schema_org_metatag`,
`metatag`, `simple_xml_sitemap`, `robotstxt_module`, `llms_txt_plugin`, `llms_txt_module`), `files` (which
physical files exist, such as `{"robots.txt": true}`), `state` (the choices stored before; `null` the first
time) and `choices` (the owner's choices, which the fixer's spec names). Each fixer adds its inputs:

| Fixer | Adds to `given` |
|---|---|
| `robots-txt` | `baseline`: `{"robots.txt": <text>}`, what is served with the fixer off |
| `llms-txt` | `content`: `{sections, sitemap_count}`; `baseline`: `{"llms.txt": <text>}` |
| `robots-directives` | `pages`: `[{path, directives, source, header?}]` |
| `dates` | `now`, `platform_sitemap` (`core` or `none`), `items`: `[{path, url, published, modified_recorded, content_changed_at}]` |
| `entity-graph` | `pages`: `[{url, kind, title, breadcrumb?, dates?}]`; `choices.organization` and `choices.search_template` |

## `preview`

What the owner sees before anything happens. Nothing is changed.

| Key | Meaning |
|---|---|
| `status` | `ready` (it would change something), `unchanged` (nothing to do), `conflict` (it cannot be applied here; the change is still shown, to paste) or `defer` (an SEO tool owns the setting) |
| `reason` | `null` when ready; otherwise `physical_file`, `other_tool`, `tooling_owns_it`, `nothing_to_change`, `already_present` or `no_content` |
| `findings` | What the fixer found, in the fixer's own shape (its spec lists them) |
| `changes` | `[{target, before, after}]`, strings: the exact change. Empty when unchanged |
| `paste` | The exact text the owner can place by hand: set for a `conflict`, else `null` |
| `output` | What the site would serve with the fixer on: `{"robots.txt": ...}`, `{"llms.txt": ...}`, `{"pages": [...]}`, `{"items": [...]}` or the graph. For a conflict it is the text that *would* be served were the conflict gone |

## `apply`, `undo`, `reapply`

| Step | When present | Shape |
|---|---|---|
| `apply` | Always | `{status, reason, recorded, output}`. `status` is `applied`, `unchanged` or `refused`. `recorded` is `{fixer, prior, new}` (the stored choices before and after), or `null` when nothing was done. For a refusal, `output` is what the site still serves |
| `undo` | Only when `apply.status` is `applied` | `{status: "undone", recorded, output}`: the record the other way round, and the baseline exactly |
| `reapply` | Only when `apply.status` is `applied` | `{status: "unchanged", recorded: null, output}`: pressing Apply again changes nothing, records nothing and serves the same output |

The record a module keeps is the whole of what it stores for a fixer: the choices. That is what makes undo
exact and apply idempotent.

## Rules `check_vectors.py` enforces

- A `ready` preview is applied; its record is `{fixer, prior: given.state, new: given.choices}`; its
  `apply.output` is the preview's `output`; it has an `undo` whose record is the inverse and a `reapply`.
- A `conflict` has `paste`; a `conflict` and a `defer` are refused for the reason the preview gave, record
  nothing, and have no `undo` or `reapply`.
- An `unchanged` preview is applied as `unchanged`, records nothing, and has no `undo` or `reapply`.
- `case` is the file name, the directory is the fixer, and `covers` names only tests the fixer moves.

## Using them from a module

```
for each fixer, for each vector:
    site     = a fake of the CMS built from vector.given
    result   = the module's fixer run against it
    assert   result.preview == vector.preview        (status, findings, changes, paste, output)
    assert   applying the choices gives vector.apply (and stores vector.apply.recorded)
    assert   undoing gives vector.undo
    assert   applying again gives vector.reapply
```

Exact string comparison is the point: a robots.txt that differs by a blank line is a robots.txt the other
module would not have written. JSON objects compare without regard to key order; arrays compare in order.
