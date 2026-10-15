# Security and privacy review

> As of 0.5.0 the fixer entries below are switched off by default (`Features::fixers()`): with the switch off the Apply, Undo, settings, diagnose and prompt actions, the Fixes, Changes and preview pages and every front-end filter are not registered, so those rows describe code that does not run in a release.

Written as an attacker would read the plugin, and then as a wordpress.org reviewer would. Every entry
point is listed with who may call it, what proves they meant to, what it reads and what it changes;
`tests/Unit/HookAllowlistTest.php` fails when one is added, removed or renamed without this file being
updated. Disclosure address and support path: **needs Ivan** (see the end).

## Entry points

Every one except the Connect return requires a signed-in user with `manage_options`, checked **before**
the nonce so a stranger learns nothing from a nonce error.

| Entry point | Kind | Capability | Nonce | Inputs | Effects |
|---|---|---|---|---|---|
| `readiness_index_connect` (Connector::ACTION_START) | admin-post | `manage_options` | `readiness_index_connect` | none | Stores a one-time state (transient, 20 minutes) and redirects to readinessindex.io with the site's address, platform, callback and state. |
| `readiness_index_callback` (Connector::ACTION_CALLBACK) | admin-post, and **admin-post nopriv** | `manage_options` when signed in; none before | the one-time **state** is the defense; see below | `key`, `state`, `plan`, `verify_token`, `error`, each sanitized, length-capped, and the key and token pattern-checked | Stores the key (non-autoloaded) and the ownership token after the state matches and is consumed. |
| `readiness_index_disconnect` | admin-post | `manage_options` | yes | none | Forgets the key, token and cached answer; stops scheduled events. |
| `readiness_index_scan` | admin-post | `manage_options` | yes | none | Asks readinessindex.io to start a scan. |
| `readiness_index_verify` | admin-post | `manage_options` | yes | none | Asks readinessindex.io to check the ownership file. |
| `readiness_index_refresh` | admin-post | `manage_options` | yes | none | Clears the cached answer and the back-off. |
| `readiness_index_dismiss` | admin-post | `manage_options` | yes | none | Sets one user meta flag. |
| `readiness_index_save_settings` (SAVE_SETTINGS) | admin-post | `manage_options` | yes | one checkbox | Sets the "remove my changes when I delete the plugin" option. |
| `readiness_index_diagnose` (DIAGNOSE) | admin-post | `manage_options` | yes | `diagnostic`, accepted only if it is `cdn` or `js_only` | Requests this site's own pages and stores the result. Changes nothing else. |
| `readiness_index_apply` (APPLY_ACTION) | admin-post | `manage_options` | yes, **POST only** (405 otherwise) | `fixer` (looked up in the registry, never used as a class name), `choices` (sanitized by the fixer), `ri_hash` | The runner: recomputes the preview, refuses on a stale hash or a conflict, applies, records, verifies. |
| `readiness_index_undo` (UNDO_ACTION) | admin-post | `manage_options` | yes, **POST only** | `change` (integer) | Restores the recorded prior value, refuses if superseded. |
| `readiness_index_progress` (Ajax::ACTION) | admin-ajax | `manage_options` | `check_ajax_referer` | none | Reads the scan's progress; returns sentences from the shared strings. |
| `readiness_index_fix_prompt` (Ajax::PROMPT_ACTION) | admin-ajax | `manage_options` | `check_ajax_referer` | `check`, which must match `AIR-n.n` | Fetches one fix prompt; returned as a string for `textContent`. |
| llms.txt (rewrite `^llms\.txt$`, the `RULE` constant; `template_redirect` `serve`) | public, only while that fixer is on | none: it is a public file | none: it is a GET that changes nothing | the request path | Serves one stored text with `nosniff`; 304 on a matching validator. |
| the ownership file (`init`, `maybe_serve`) | public | none: the token proves control of the site and opens nothing | none | the request path | Serves the 32-hex token as the exact body of `/.well-known/readinessindex-verify`. |
| REST routes | none | n/a | n/a | n/a | There are none. A route would need a permission callback and a row here. |
| Front-end filters (robots.txt, `wp_robots`, sitemap entries, `wp_head`) | public output | n/a | n/a | the site's own data | Only while the owner has applied the fixer. |

No `nopriv` variant of any AJAX action exists, and the test asserts it.

## The Connect return, in detail

A cross-site POST does not carry a `SameSite=Lax` cookie, so a signed-in administrator arrives looking
signed out. A pending state is therefore relayed once from this site to itself (same-site, so the cookie
travels) and the signed-in branch does the work. The relay prints a form with fields that only come from a
request whose state this site minted. A replayed state is refused (it is consumed), a missing or wrong state
stores nothing, and a key that does not match `^air_[0-9a-f]{8}_[A-Za-z0-9_-]{20,}$` (with the `D` modifier,
so a trailing newline cannot ride along) is refused before it is stored.

## Attacks attempted

1. **Forge a Connect return.** Posted a callback with no state, a wrong state, a replayed state and a key
   of the wrong shape, signed in and not. Every one was refused and nothing was stored
   (`ConnectTest`, and the relay's branches in `callback_plan`).
2. **CSRF a fixer.** An administrator's browser was sent a GET and a POST to `readiness_index_apply` and
   `_undo` with no nonce, a wrong nonce and no capability. GET: 405. POST without a valid nonce: refused.
   An editor: 403 before the nonce (`tests/Integration/SecurityTest.php`).
3. **Extract the key.** Searched every response the plugin can produce, every option, transient, log line,
   exception message, notice and the page source for the key. It appears only in the one non-autoloaded
   option (or the constant), in the `Authorization` header of a request to the one origin, and as its last four
   characters on screen. The client's errors carry the server's message and never the request.

## Reviewer's checklist

- **Escaping and SQL.** Output is escaped where it is printed with the function for its context; the
  one place text is echoed unescaped (llms.txt, `text/plain` with `nosniff`) and the one JSON-LD block (hex-encoded
  so no tag, ampersand or quote survives) say why in the line. Every SQL statement on the plugin's own table is
  prepared or has no input, and the table name is escaped. PHPCS runs at error level with the WordPress ruleset;
  every `phpcs:ignore` carries its reason.
- **No remote code.** No `eval`, `include` of a computed path, `unserialize`, `serialize`, `call_user_func` or `extract`
  anywhere (`StaticGuardsTest`). The server says which test failed and what the evidence was; the change is code in
  the plugin. A fixer id from a form is only ever a key into the registry.
- **The key** is never in a URL, log, exception, notice, transient or source. A constant in `wp-config.php` wins over
  the option.
- **Outbound requests** go to one constant origin (`READINESS_INDEX_API_ORIGIN`, set only in `wp-config.php`, never by
  an option or request) and to this site's own host through `wp_safe_remote_get`, which refuses anything else
  (`SiteFetcher::is_own`). Only the API client and `SiteFetcher` may call a request function (`StaticGuardsTest`).
- **Admin responses.** Every plugin screen and action is behind `manage_options`; WordPress sends `nocache_headers`
  on wp-admin pages and the relay page sends them itself.
- **Data lifecycle.** Deactivation stops scheduled events and keeps the record of changes. Uninstall removes the
  plugin's tables, options, post meta and transients, per site on a network, and runs each fixer's `cleanup()` first only
  when the owner turned that on (`LifecycleTest`).
- **Privacy.** No telemetry, no analytics, nothing about visitors or users is sent. `wp_add_privacy_policy_content`
  adds the plugin's paragraph to the privacy policy guide. The readme's External services section names the one
  service and the site's own requests.
- **wordpress.org guidelines.** GPLv2 or later; no obfuscation; no remote code; no tracking without consent; no
  update server, license screen or upsell inside wp-admin beyond what the service returns for an account; no
  calls to other hosts; the name is checked in `docs/RELEASING.md`.

## Needs Ivan

- The security-disclosure address and support path to publish in the readme and `SECURITY` contact.
- Anything public: submitting to wordpress.org, making the repository public, cutting a release.
