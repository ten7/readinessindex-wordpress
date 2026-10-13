#!/usr/bin/env python3
"""Check fixer vectors, and the parity matrix, against the schema in vector-schema.md.

    python3 check_vectors.py fixers/                 # every vector under a directory
    python3 check_vectors.py fixers/robots-txt/physical-file-wins.json
    python3 check_vectors.py --matrix MATRIX.md      # exit 1 while a row differs between modules

Standard library only, so a module repository can copy this one file next to a checkout of the
vectors and run it in CI. It checks the *shape* of a vector: the envelope, the keys each fixer's
`given` and `output` must carry, and the rules that tie the steps together (a fixer that is ready
can be undone and applied twice; one that conflicts changes nothing and records nothing). It does not
re-run a fixer: that is what a module's own test does, by feeding `given` to its implementation
and comparing with `preview`, `apply`, `undo` and `reapply`.

Exit status is 0 when everything checked is well formed, 1 otherwise, and a problem is printed as
`path: what is wrong`, one per line.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

SCHEMA = 1
AIR_ID = re.compile(r"^AIR-\d+\.\d+$")
TAGS = {"apply", "conflict", "defer", "noop", "undo", "idempotent"}
PREVIEW_STATUS = {"ready", "unchanged", "conflict", "defer"}
APPLY_STATUS = {"applied", "unchanged", "refused"}

#: fixer id -> (the tests it may claim to move, the keys its `given` must carry, its output keys)
FIXERS: dict[str, tuple[set[str], set[str], set[str]]] = {
    "robots-txt": ({"AIR-1.1", "AIR-1.4", "AIR-1.5"},
                   {"site", "tooling", "files", "baseline", "state", "choices"}, {"robots.txt"}),
    "llms-txt": ({"AIR-1.10"},
                 {"site", "tooling", "files", "content", "baseline", "state", "choices"},
                 {"llms.txt"}),
    "robots-directives": ({"AIR-1.6"}, {"site", "tooling", "files", "pages", "state", "choices"},
                          {"pages"}),
    "dates": ({"AIR-4.8", "AIR-5.4"},
              {"site", "tooling", "files", "now", "platform_sitemap", "items", "state", "choices"},
              {"items"}),
    "entity-graph": ({"AIR-1.11", "AIR-1.12", "AIR-1.13", "AIR-3.2", "AIR-3.7"},
                     {"site", "tooling", "files", "pages", "state", "choices"},
                     {"prints_graph", "pages", "tooling", "provides"}),
}


def check_vector(path: Path) -> list[str]:
    """Every problem with one vector file, as sentences."""
    try:
        doc = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError) as error:
        return [f"{path}: cannot be read as JSON ({error})"]
    problems: list[str] = []

    def bad(message: str) -> None:
        problems.append(f"{path}: {message}")

    if not isinstance(doc, dict):
        return [f"{path}: the top level is not an object"]
    if doc.get("schema") != SCHEMA:
        bad(f"schema is {doc.get('schema')!r}, this checker reads {SCHEMA}")
    fixer = doc.get("fixer")
    if fixer not in FIXERS:
        bad(f"fixer {fixer!r} is not one of {sorted(FIXERS)}")
        return problems
    allowed_tests, given_keys, output_keys = FIXERS[fixer]
    if doc.get("case") != path.stem:
        bad(f"case {doc.get('case')!r} is not the file name {path.stem!r}")
    if path.parent.name != fixer:
        bad(f"a {fixer} vector belongs in a directory named {fixer}")
    if not isinstance(doc.get("title"), str) or not doc["title"].strip():
        bad("title is missing")
    covers = doc.get("covers")
    if (not isinstance(covers, list) or not covers
            or any(not isinstance(c, str) or not AIR_ID.match(c) for c in covers)):
        bad("covers must be a non-empty list of AIR ids")
    elif set(covers) - allowed_tests:
        bad(f"covers names {sorted(set(covers) - allowed_tests)}, which {fixer} does not move")
    tags = doc.get("tags")
    if not isinstance(tags, list) or not tags or set(tags) - TAGS:
        bad(f"tags must be a non-empty subset of {sorted(TAGS)}")

    given = doc.get("given")
    if not isinstance(given, dict):
        bad("given is missing")
        return problems
    if missing := given_keys - set(given):
        bad(f"given lacks {sorted(missing)}")
    if "tooling" in given and not (
            isinstance(given["tooling"], list) and all(isinstance(t, str) for t in given["tooling"])):
        bad("given.tooling must be a list of strings")

    preview = doc.get("preview")
    if not isinstance(preview, dict):
        bad("preview is missing")
        return problems
    for key in ("status", "reason", "findings", "changes", "paste", "output"):
        if key not in preview:
            bad(f"preview lacks {key}")
    status = preview.get("status")
    if status not in PREVIEW_STATUS:
        bad(f"preview.status {status!r} is not one of {sorted(PREVIEW_STATUS)}")
    for change in preview.get("changes") or []:
        if not (isinstance(change, dict) and set(change) == {"target", "before", "after"}
                and all(isinstance(v, str) for v in change.values())):
            bad("every change is {target, before, after} of strings")
            break
    if status == "conflict" and not preview.get("paste"):
        bad("a conflict carries the exact text to paste")
    if status != "conflict" and preview.get("paste") is not None:
        bad("only a conflict carries paste")
    if status in ("conflict", "defer", "unchanged") and not preview.get("reason"):
        bad(f"a {status} preview names its reason")
    if status == "ready" and preview.get("reason") is not None:
        bad("a ready preview has no reason")
    output = preview.get("output")
    if not isinstance(output, dict) or not (set(output) & output_keys):
        bad(f"preview.output must carry one of {sorted(output_keys)}")

    apply = doc.get("apply")
    if not isinstance(apply, dict) or apply.get("status") not in APPLY_STATUS:
        bad(f"apply.status must be one of {sorted(APPLY_STATUS)}")
        return problems
    for key in ("reason", "recorded", "output"):
        if key not in apply:
            bad(f"apply lacks {key}")

    if status == "ready":
        if apply["status"] != "applied":
            bad("a ready preview is applied")
        if apply.get("output") != output:
            bad("apply.output is what the preview showed")
        record = apply.get("recorded")
        if not (isinstance(record, dict) and set(record) == {"fixer", "prior", "new"}
                and record["fixer"] == fixer):
            bad("an applied fixer records {fixer, prior, new}")
        elif record["prior"] != given.get("state") or record["new"] != given.get("choices"):
            bad("the record is the stored state before and the choices after")
        undo = doc.get("undo")
        if not isinstance(undo, dict) or undo.get("status") != "undone":
            bad("a ready vector shows its undo")
        else:
            inverse = undo.get("recorded")
            if not (isinstance(record, dict) and isinstance(inverse, dict)
                    and inverse.get("prior") == record.get("new")
                    and inverse.get("new") == record.get("prior")):
                bad("the undo record is the apply record the other way round")
        reapply = doc.get("reapply")
        if not isinstance(reapply, dict) or reapply.get("status") != "unchanged" \
                or reapply.get("recorded") is not None or reapply.get("output") != output:
            bad("applying again is unchanged, records nothing and serves the same output")
    elif status in ("conflict", "defer"):
        if apply["status"] != "refused" or apply.get("reason") != preview.get("reason"):
            bad("a conflict or deferral is refused for the reason the preview gave")
        if apply.get("recorded") is not None:
            bad("a refusal records nothing")
        if "undo" in doc or "reapply" in doc:
            bad("nothing was done, so there is nothing to undo or apply again")
    elif status == "unchanged":
        if apply["status"] != "unchanged" or apply.get("recorded") is not None:
            bad("an unchanged preview is applied as unchanged and records nothing")
        if "undo" in doc or "reapply" in doc:
            bad("nothing was done, so there is nothing to undo or apply again")
    return problems


# ----------------------------------------------------------------------------- the matrix

ROW = re.compile(r"^\|\s*(?P<area>[^|]+?)\s*\|\s*(?P<capability>[^|]+?)\s*\|\s*"
                 r"(?P<server>[^|]+?)\s*\|\s*(?P<wordpress>[^|]+?)\s*\|\s*"
                 r"(?P<drupal>[^|]+?)\s*\|\s*(?P<notes>[^|]*?)\s*\|\s*$")
STATUSES = {"done", "in progress", "planned", "n/a"}


def matrix_rows(text: str) -> list[dict[str, str]]:
    """The capability rows of MATRIX.md: those whose WordPress and Drupal cells are statuses."""
    rows = []
    for line in text.splitlines():
        found = ROW.match(line)
        if found and found["wordpress"].lower() in STATUSES and found["drupal"].lower() in STATUSES:
            rows.append({k: v.strip() for k, v in found.groupdict().items()})
    return rows


def matrix_problems(text: str) -> list[str]:
    """Rows that block a release: a capability one module has and the other does not. `n/a` is
    only for both, because a capability that does not apply to one CMS does not apply to the
    other either, and a row that says otherwise is a gap written down as a decision."""
    problems = []
    for row in matrix_rows(text):
        wp, dr = row["wordpress"].lower(), row["drupal"].lower()
        if wp != dr:
            problems.append(
                f"{row['area']}: {row['capability']}: WordPress is {wp} and Drupal is {dr}")
    return problems


def main(argv: list[str]) -> int:
    if len(argv) == 3 and argv[1] == "--matrix":
        problems = matrix_problems(Path(argv[2]).read_text(encoding="utf-8"))
        for problem in problems:
            print(problem)
        print(f"{len(matrix_rows(Path(argv[2]).read_text(encoding='utf-8')))} rows, "
              f"{len(problems)} blocking")
        return 1 if problems else 0
    paths: list[Path] = []
    for argument in argv[1:] or ["."]:
        path = Path(argument)
        paths += sorted(path.rglob("*.json")) if path.is_dir() else [path]
    problems = [p for path in paths for p in check_vector(path)]
    for problem in problems:
        print(problem)
    print(f"{len(paths)} vectors, {len(problems)} problems")
    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
