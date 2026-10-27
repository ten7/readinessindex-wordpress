# Security and privacy review

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
| `readiness_index_dismiss_network` (Notices::DISMISS_ACTION) | admin-post | `manage_options` | yes | none | Sets one user meta flag that closes the multisite notice for that user. |
| `readiness_index_progress` (Ajax::ACTION) | admin-ajax | `manage_options` | `check_ajax_referer` | none | Reads the scan's progress; returns sentences from the shared strings. |
| the ownership file (`init`, `maybe_serve`) | public | none: the token proves control of the site and opens nothing | none | the request path | Serves the 32-hex token as the exact body of `/.well-known/readinessindex-verify`. |
| REST routes | none | n/a | n/a | n/a | There are none. A route would need a permission callback and a row here. |

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
2. **CSRF an action.** Each admin-post action (disconnect, scan, check ownership, try again, the two dismissals)
   was posted with no nonce, a wrong nonce and no capability. An editor: 403 before the nonce. An administrator
   with a wrong nonce: refused, nothing requested, the key still stored (`tests/Integration/SecurityTest.php`).
3. **Extract the key.** Searched every response the plugin can produce, every option, transient, log line,
   exception message, notice and the page source for the key. It appears only in the one non-autoloaded
   option (or the constant), in the `Authorization` header of a request to the one origin, and as its last four
   characters on screen. The client's errors carry the server's message and never the request.

## Reviewer's checklist

- **Escaping and SQL.** Output is escaped where it is printed with the function for its context; each of the few
  `phpcs:ignore` comments says why (`StaticGuardsTest` fails on one that does not). The one SQL statement, in the
  uninstall, is prepared; there is no table.
- **No remote code.** No `eval`, `include` of a computed path, `unserialize`, `serialize`, `call_user_func` or `extract`
  anywhere (`StaticGuardsTest`). The server says what the score is; nothing it sends is run, included or used as a
  hook, a path or a query. Every address it sends is shown only as a link with an allowed protocol.
- **The key** is never in a URL, log, exception, notice, transient or source, and no part of it is printed. A constant
  in `wp-config.php` wins over the option.
- **Outbound requests** go to one constant origin (`READINESS_INDEX_API_ORIGIN`, set only in `wp-config.php`, never by
  an option or request), only from the API client (`StaticGuardsTest`), and none are made before an administrator
  clicks Connect (`tests/Integration/ScreenTest.php`). Two links open readinessindex.io in the administrator's browser.
- **Admin responses.** Every plugin screen and action is behind `manage_options`; WordPress sends `nocache_headers`
  on wp-admin pages and the relay page sends them itself.
- **Data lifecycle.** Deactivation stops scheduled events and keeps the connection. Uninstall removes the plugin's
  options, its transients by prefix, both pieces of user meta and its scheduled events, per site on a network, asking
  for every site (`UninstallTest`, unit and integration).
- **Privacy.** No telemetry, no analytics, nothing about visitors or users is sent. `wp_add_privacy_policy_content`
  adds the plugin's paragraph to the privacy policy guide. The readme's External services section names the one
  service, what is sent, what the two links do and what a free account is.
- **wordpress.org guidelines.** GPLv2 or later; no obfuscation; no remote code; no tracking without consent; no
  update server, license screen or upsell inside wp-admin beyond what the service returns for an account; no
  calls to other hosts; the name is checked in `docs/RELEASING.md`.
- **Not in this tree.** Nothing that writes to the site: no filter on robots.txt, llms.txt, directives, dates or
  structured data, no table, no rewrite rule (`StaticGuardsTest::test_nothing_of_the_fixer_side_ships`). The fixers
  are on the `fixers` branch.
