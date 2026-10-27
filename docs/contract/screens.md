# Screens and states

What the WordPress plugin and the Drupal module show, in every situation, written once. A module
chooses its own layout, components and styling (wp-admin and Drupal's admin theme look different, and
should). **What it must not choose is which situation it is in, what it says, or what the person can do
there.** The sentences are in `strings/en.json`, referenced here by key in backticks; a module loads them
and adds none of its own. The situations come from the API (`docs/cms-fixtures/openapi.json`, with a
golden fixture for each), and the matrix (`MATRIX.md`) has a row for each state below.

There is one menu entry, **Readiness** (`menu.title`), with three tabs: **Score** (`tab.score`),
**Fixes** (`tab.fixes`) and **Changes** (`tab.changes`). Every screen is for people who can manage the
site (`manage_options` on WordPress, the permission `administer readiness index` on Drupal), every
action is a POST with a nonce or token, and everything read from the API under `untrusted_site_text` is
escaped on output and never interpreted. The product name wherever one is needed is `app.name`.

## How a state is decided

The module makes at most one call to `GET /cms/v1/site` per screen load and reads, in this order:

1. No key stored: **unconnected**.
2. The call could not be made at all (no route to readinessindex.io, a timeout): **offline**.
3. `unauthenticated`: **revoked**. `account_disabled`: **disabled**. `rate_limited`: wait `retry_after`
   seconds, then show the last answer; nothing is shown as an error for it. `internal_error`: the call
   is tried again on the next load and the last answer stays on screen.
4. Otherwise `scan.state` is the state: `none` is **never scanned**; `pending`, `running`, `done` and
   `failed` are themselves. A `notices` entry with key `module_update_required` adds the **update
   required** notice above whatever else is shown; it never replaces it, because a module that is out of
   date and still works should keep working while its owner is told.

While a scan is `pending` or `running` and the Score tab is open, the module polls
`GET /cms/v1/scan/{scan}` (with `If-None-Match` on `/site` once it finishes) and does not poll at all
when the tab is closed; a scheduled task picks up a scan the owner left (WP-Cron, Drupal cron and the
Queue API). There is no call back to the site.

## Score

Heading `score.title`. When there is a score it shows `score.value` and `score.grade`; the verdict is
the API's `score.verdict`, as it is. The seven dimensions follow, each `score.dimension_row` under
`score.dimensions`. `score.gated` is shown beside the number when `score.gated` is true: a fact beside
the score and never a cap on it. The line `score.scanned_on` names the date and `score.pages_read` the
count; `score.sample_note` is shown on a free account; `score.index` names the Index version.

The change since the scan before comes from `score.change`: `score.change.up`, `score.change.down` or
`score.change.same` when `comparable` is true; `score.change.not_comparable` when it is false (across an
Index major version no delta is ever given); `score.change.first` when it is null.

Beneath the score: the rescans left, `score.rescans.left` with `score.rescans.lifetime` on a free account
(whose rescans are for the life of the account and never refill) or `score.rescans.monthly` on a paid
one, and the **Scan now** button (`score.scan_now`, or `score.scan_again` once there is a score), disabled
while a scan is pending or running. Ownership shows `score.verified` or `score.not_verified`, with
`verify.check` to look again (`POST /cms/v1/verify`) and `verify.serving` explaining the file.

### Every state

| State | When | Shows | The person can |
|---|---|---|---|
| **Unconnected** | No key stored | `state.unconnected.title`, `state.unconnected.body`, `connect.intro` | Connect (`common.connect`, `connect.button`) |
| **Waiting to approve** | The browser is at readinessindex.io | `connect.waiting` | Nothing; they come back by the callback |
| **Never scanned** | `scan.state` is `none` | `state.never_scanned.title`, `state.never_scanned.body`, the rescans left | Scan now |
| **Pending** | `scan.state` is `pending` | `state.pending.title`, `state.pending.body` | Leave and come back |
| **Running** | `scan.state` is `running` | `state.running.title` and `state.running.body` (`{read}` of `{total}` pages), or `state.running.body_unknown` when the API gives no progress | Leave and come back |
| **Done** | `scan.state` is `done` | The score, as above, and `state.done.title` | Scan again; open Fixes |
| **Failed** | `scan.state` is `failed` | `state.failed.title`, `state.failed.body` (the API's `problem`, one plain sentence), `state.failed.hint`; the last finished score stays underneath if there is one | Scan again |
| **Quota** | `quota` on a scan, or `allowance.rescans_left` is 0 | `state.quota.title` and `state.quota.body` (the API's own message), then the upgrade below | Open the upgrade; nothing else, because nothing was spent and nothing was created |
| **Unreachable** | `unreachable` on a scan; `reason` is one of `no_answer`, `staging_password`, `maintenance_mode`, `robots_blocked` | `state.unreachable.title` and the sentence for the reason, `unreachable.no_answer`, `unreachable.staging_password`, `unreachable.maintenance_mode` or `unreachable.robots_blocked`, each ending "Nothing was used." | Fix the cause, then Scan now |
| **Revoked** | `unauthenticated` on a call that had worked | `state.revoked.title`, `state.revoked.body` | Connect again |
| **Reconnect after revoke** | The revoked state, after Connect | `state.reconnect.title`, `state.reconnect.body`; on return the stored key is replaced and the last score is still there | Connect (`common.connect`) |
| **Disabled** | `account_disabled` | `state.disabled.title`, `state.disabled.body` | Nothing |
| **Offline** | No answer from readinessindex.io | `state.offline.title`, `state.offline.body` | Try again (`common.retry`) |
| **Rate limited** | `rate_limited` | The last answer, and `state.rate_limited.body` only if there is none | Wait |
| **Update required** | A `module_update_required` notice | `state.update_required.title` and `state.update_required.body` above the rest | Update the module; everything else keeps working |

Every API refusal also has its own sentence under `error.<key>` for a module that needs to show one where
this table does not: `error.unauthenticated`, `error.account_disabled`, `error.insufficient_scope`,
`error.rate_limited`, `error.not_found`, `error.quota`, `error.no_result`, `error.unreachable`,
`error.not_verified` and `error.internal_error`. They are the API's own words, so a module that shows the
response's `message` shows the same sentence.

### The upgrade

It appears only when `allowance.next_step` is not null, which is only with billing on and only on a free
account. The module reads the key and shows exactly one of:

- `monitoring_basic`: `upgrade.monitoring_basic.label` and `upgrade.monitoring_basic.body`, once, beside
  the score and never in front of a fix.
- `upgrade_basic`: `upgrade.upgrade_basic.label` and `upgrade.upgrade_basic.body`, at the moment the
  rescans are spent.

Either one is a button to `next_step.url` with `?return=` the module's own screen appended, so the owner
lands back where they started (`checkout.subscribe`, `checkout.back`); on their return the module reads
`readiness=subscribed` and shows `upgrade.returned`. The module holds no price and no rule: where
`allowance.can_buy_pack` is true it offers `upgrade.pack` to `allowance.pack_url`, and never otherwise.
There is no pack for a free account.

## Connect

`connect.title` and `connect.intro` introduce the button (`connect.button`). The return is a POST to the
module's callback with `key`, `state`, `plan` and `verify_token`:

| Result | Shows |
|---|---|
| A key and a `state` that matches the one the module made | `connect.success`, `connect.disconnect_hint`; the module begins serving the ownership file with `verify_token` |
| `error=access_denied` | `connect.denied` |
| A `state` that does not match, or none | `connect.state_mismatch`; nothing is stored |
| The site cannot be reached from outside (it is local, or not https) | `connect.site_unreachable`, before sending the browser away |

The ownership file (`verify.serving`) is served at `/.well-known/readinessindex-verify` with exactly the
token as its body. The results of `POST /cms/v1/verify` are `verify.verified`, and for `not_verified` the
`state` the API gives: `verify.not_found` or `verify.error`.

Settings (a small section on the Score tab or a settings page) shows `settings.account`,
`common.disconnect`, a confirmation `settings.disconnect.confirm` with `common.disconnect` and `common.cancel`, and afterwards
`settings.disconnect.done`.
Disconnecting from the module forgets the key on the site; it does not revoke it. The key is ended from the
website's profile page, and a new Connect replaces it, so a key that is forgotten here and still live
somewhere is the owner's to end there.

## Fixes

Heading `fixes.title`, fed by the same `GET /cms/v1/site` call. With no scan, `fixes.no_scan`; with a scan
and nothing outstanding, `fixes.empty`. Otherwise a list in the API's order, never re-sorted, each row
showing `fixes.rank`, the title, `fixes.points`, and `fixes.fixed_by` naming the roles the API gives
(`fixed_by`). Before anything is applied the screen says how much the module can reach on this site,
`fixes.reachable`: the sum of the points of the rows that are *fix it here*, out of what the site is
missing. A promise the module cannot keep is worse than none.

Each row is exactly one of three kinds, decided by the module's own table of which AIR id has a fixer, and
which SEO tooling is active:

| Kind | Label | What the row does |
|---|---|---|
| Fix it here | `fixes.kind.fix_here` | Preview (`fixes.preview`), Apply (`fixes.apply`), Undo (`fixes.undo`) |
| Fix it with a tool the site has | `fixes.kind.fix_with` | `fixes.fix_with.open` to that tool's settings, with `fixes.fix_with.field` naming the field |
| Hand it on | `fixes.kind.hand_on` | `fixes.hand_on.explain`, and `fixes.hand_on.copy` to copy the API's fix prompt (`GET /cms/v1/fixes/{check}`); the prompt is shown under `fixes.hand_on.untrusted` |

A fix-it-here row follows the fixer's spec exactly. **Preview** shows the exact `changes` (`fixes.before`
and `fixes.after`) and any `findings`. The preview's `status` decides what the screen can do:

| Preview status | The screen says | Apply |
|---|---|---|
| `ready` | The change, then Apply | Applies, records `{fixer, prior, new}`, shows `fixes.applied`, then verifies locally (`fixes.live.yes` or `fixes.live.no`) and offers `fixes.rescan_hint` |
| `unchanged` | `fixes.nothing_to_change`, or `fixes.already_present` for reason `already_present` | Nothing to do |
| `conflict` | `fixes.conflict.physical_file` or `fixes.conflict.other_tool`, and the exact `paste` text under `fixes.paste` with a copy button (`common.copy`, then `common.copied`) | Refused, nothing recorded |
| `defer` | `fixes.defer.tooling_owns_it` naming the tool | Refused, nothing recorded |
| through a tool | `fixes.through_tooling` | The facts are handed over; nothing is printed twice |

A fixer never needs a rescan to work, and applying one never spends one. Nothing runs by itself: every
apply is a click, and the fixer is the only code that can change the site.

The fixers (each with its spec and golden vectors in `fixers/`) are `fixer.robots_txt.title`,
`fixer.llms_txt.title`, `fixer.robots_directives.title`, `fixer.dates.title` and
`fixer.entity_graph.title`, each with a one-line summary (`fixer.robots_txt.summary`,
`fixer.llms_txt.summary`, `fixer.robots_directives.summary`, `fixer.dates.summary` and
`fixer.entity_graph.summary`).

### Diagnostics

Three things the module can see and must not fix. Each is shown as a *hand it on* row with its own copy:

| Test | Shows | Why it is not fixed |
|---|---|---|
| RED-17, images without alt text | `diagnostic.alt_text.title`, `diagnostic.alt_text.body` (with the count), `diagnostic.alt_text.open` to the media library | A wrong description is worse than none |
| RCH-2, a CDN or firewall turning AI crawlers away | `diagnostic.cdn.title`, `diagnostic.cdn.body` | The rule is outside the site |
| RED-1 and RED-2, content that needs JavaScript | `diagnostic.js_only.title`, `diagnostic.js_only.body` | It is a change to the theme |

## Changes

Heading `changes.title`. Every change the module made, newest first, with `changes.col.what`,
`changes.col.when`, `changes.col.who` and `changes.col.action`. An apply is `changes.applied` and an undo
is `changes.undone`, each naming the fixer; the **Undo** button (`changes.undo`, `fixes.undo`) on an
applied row restores the stored prior value and writes a row of its own. With nothing yet,
`changes.empty`. After an undo the screen says `fixes.undone`. The record is the one in the vectors: the
fixer, the prior stored choices and the new ones, so an undo is the same record the other way round.

## Small things every screen shares

`common.loading` while a call is in flight, `common.close` on a dismissible notice, `common.never` where a
date is absent and `common.learn_more` on a link to the website.
