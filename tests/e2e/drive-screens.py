#!/usr/bin/env python3
"""Drives every screen state of the Score in a local wp-env run, over HTTP, against the stand-in API.

It signs in as wp-env's default administrator on localhost (development credentials only), presses the
buttons a person would, and reads what WordPress prints. It checks three things at every step: the
page says what the state should say, `WP_DEBUG` wrote nothing to debug.log, and the stand-in saw only
the requests that step should have caused. The first of those steps is the consent trace: an
unconnected site, however many screens are opened, makes no request to the service.

Setup, from the repository root (docs/TESTING.md has the longer version):

    bin/build-zip && bin/wp-env-variant dist
    # .wp-env.override.json: the dist mapping plus
    #   "config": { "READINESS_INDEX_API_ORIGIN": "http://host.docker.internal:8099" }
    npx wp-env start --update
    python3 tests/e2e/drive-screens.py     # starts the stand-in itself and stops it for the offline step

Nothing here ships and nothing here touches a real service.
"""
import html
import http.cookiejar
import json
import re
import os
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

WP = "http://localhost:8888"
API = "http://localhost:8099"
# The address the site (inside its container) uses for the stand-in; the browser reaches it as localhost.
SEEN_AS = "http://host.docker.internal:8099"
PAGE = WP + "/wp-admin/admin.php?page=readiness-index"
FAILURES = []
SERVER = None


def start_stand_in():
    global SERVER
    root = os.path.dirname(os.path.abspath(__file__))
    SERVER = subprocess.Popen(["php", "-S", "0.0.0.0:8099", os.path.join(root, "fake-api.php")], cwd=root, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(50):
        with socket.socket() as probe:
            if probe.connect_ex(("127.0.0.1", 8099)) == 0:
                return
        time.sleep(0.1)
    sys.exit("The stand-in did not start.")


def stop_stand_in():
    if SERVER is not None:
        SERVER.terminate()
        SERVER.wait()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


jar = http.cookiejar.CookieJar()
follow = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
stay = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)
anonymous = urllib.request.build_opener(NoRedirect)


def fetch(url, data=None, opener=follow):
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    try:
        response = opener.open(urllib.request.Request(url, data=body), timeout=60)
    except urllib.error.HTTPError as error:
        response = error
    return response.status if hasattr(response, "status") else response.code, response.headers, response.read().decode("utf-8", "replace")


def check(label, condition, detail=""):
    print(("  ok   " if condition else "  FAIL ") + label + (("  [" + detail + "]") if detail and not condition else ""))
    if not condition:
        FAILURES.append(label)


def api_log():
    log = fetch(API + "/__log")[2]
    return [json.loads(line) for line in log.splitlines() if line.strip()]


def plugin_requests():
    """Requests the plugin made: the ones that carry its User-Agent."""
    return [entry for entry in api_log() if entry["agent"].startswith("ReadinessIndex-WordPress/")]


def debug_log():
    out = subprocess.run(
        ["npx", "wp-env", "run", "cli", "wp", "eval", 'echo file_exists( WP_CONTENT_DIR . "/debug.log" ) ? file_get_contents( WP_CONTENT_DIR . "/debug.log" ) : "";'],
        capture_output=True, text=True, check=False,
    ).stdout
    return "\n".join(line for line in out.splitlines() if re.match(r"^\[", line) or "PHP " in line)


def clear_debug_log():
    subprocess.run(["npx", "wp-env", "run", "cli", "wp", "eval", '@unlink( WP_CONTENT_DIR . "/debug.log" );'], capture_output=True, check=False)


def state(**choices):
    fetch(API + "/__state?" + urllib.parse.urlencode(choices))


def text_of(page):
    page = re.sub(r"<(script|style)[^>]*>.*?</\1>", "", page, flags=re.S)
    return re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", " ", page)))


def screen():
    status, _, page = fetch(PAGE)
    return status, page, text_of(page.split('<div class="wrap readiness-index"', 1)[-1])


def form_fields(page, action):
    """The hidden fields of the admin-post form for an action, as the browser would post them."""
    for form in re.findall(r"<form[^>]*>.*?</form>", page, flags=re.S):
        if 'name="action" value="%s"' % action in form:
            return {m.group(1): html.unescape(m.group(2)) for m in re.finditer(r'<input type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"', form)}
    return None


def press(page, action):
    fields = form_fields(page, action)
    if fields is None:
        return None
    return fetch(WP + "/wp-admin/admin-post.php", fields, stay)


def sign_in():
    fetch(WP + "/wp-login.php")
    jar.set_cookie(http.cookiejar.Cookie(0, "wordpress_test_cookie", "WP Cookie check", None, False, "localhost", False, False, "/", True, False, None, False, None, None, {}))
    status, headers, _ = fetch(
        WP + "/wp-login.php",
        {"log": "admin", "pwd": "password", "wp-submit": "Log In", "redirect_to": WP + "/wp-admin/"},
        stay,
    )
    check("signed in as the development administrator", status in (302, 303) and "wp-admin" in headers.get("Location", ""))


def main():
    start_stand_in()
    # The ownership file is answered by WordPress, which needs pretty permalinks (the readme says so);
    # wp-env's default site uses Plain, where Apache answers that address itself with its own 404.
    subprocess.run(["npx", "wp-env", "run", "cli", "wp", "rewrite", "structure", "/%postname%/", "--hard"], capture_output=True, check=False)
    fetch(API + "/__reset")
    # Start from a site that was never connected: what an uninstall leaves is what a fresh install has.
    subprocess.run(["npx", "wp-env", "run", "cli", "wp", "eval", "ReadinessIndex\\Uninstall::run();"], capture_output=True, check=False)
    clear_debug_log()

    print("1. Consent trace: an unconnected site")
    sign_in()
    status, page, text = screen()
    check("the unconnected screen has a Connect button", status == 200 and "Connect to Readiness Index" in text)
    for url in ("/wp-admin/", "/wp-admin/plugins.php", "/wp-admin/options-general.php", "/wp-admin/profile.php", "/", "/?s=test", "/.well-known/readinessindex-verify"):
        fetch(WP + url)
    fetch(PAGE)
    fetch(PAGE + "&readiness=subscribed")
    fetch(PAGE + "&ri_confirm=disconnect")
    check("0 requests reached the service before Connect", api_log() == [], str(api_log()))
    check("the ownership address is a plain 404 with no token", fetch(WP + "/.well-known/readinessindex-verify")[0] == 404)
    check("WP_DEBUG logged nothing", debug_log() == "", debug_log())

    print("2. Connect through the stand-in consent page")
    status, headers, _ = press(page, "readiness_index_connect")
    location = headers.get("Location", "")
    check("Connect redirects the browser to the service's consent page", location.startswith(SEEN_AS + "/connect/cms?platform=wordpress&site="), location)
    check("no key is in that address", "air_" not in location)
    check("still 0 requests from the plugin", plugin_requests() == [])
    _, _, consent = fetch(location.replace(SEEN_AS, API))
    fields = {m.group(1): html.unescape(m.group(2)) for m in re.finditer(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', consent)}
    callback = re.search(r'action="([^"]+)"', consent).group(1)
    # A cross-site POST carries no WordPress cookie, so it is sent without the jar.
    status, _, relay = fetch(html.unescape(callback), fields, anonymous)
    check("the cross-site return is relayed from this site to itself", status == 200 and 'name="ri_relayed"' in relay)
    relayed = {m.group(1): html.unescape(m.group(2)) for m in re.finditer(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', relay)}
    status, headers, _ = fetch(html.unescape(callback), relayed, stay)
    check("the relayed return, with the session, is accepted", status in (302, 303), str(status))
    status, page, text = screen()
    check("Connected, with the first scan offered", "Scan now" in text or "Scan again" in text, text[:200])
    check("the connection flash names the account", "Connected" in text or "connected" in text)
    check("the ownership file is served with the token", fetch(WP + "/.well-known/readinessindex-verify")[2] == "0123456789abcdef0123456789abcdef")
    seen = plugin_requests()
    check("after Connect the plugin asked for the site once", [e["path"] for e in seen] == ["/cms/v1/site"] and seen[0]["bearer"], str(seen))

    print("3. Every state of the screen")
    states = [
        ("never scanned", {"site": "site/never-scanned"}, ["Scan now"]),
        ("pending", {"site": "site/pending"}, ["Scan"]),
        ("running", {"site": "site/running"}, ["Scan"]),
        ("done, first scan", {"site": "site/done-first-scan"}, ["out of 100", "Reach", "Read", "Understand", "Act", "View the full report", "Index version 12.1"]),
        ("done, with change", {"site": "site/done-with-change"}, ["Up 6 since the last scan", "Exemplary"]),
        ("failed", {"site": "site/failed"}, ["did not finish", "Scan"]),
        ("no rescans left", {"site": "site/no-rescans-left"}, ["No rescans left"]),
        ("a paid plan", {"site": "site/basic-plan"}, ["rescans left this month"]),
        ("update required", {"site": "site/update-required"}, ["update"]),
        ("revoked key", {"site": "errors/unauthenticated"}, ["Connect"]),
        ("account disabled", {"site": "errors/account-disabled"}, []),
        ("rate limited", {"site": "errors/rate-limited"}, []),
        ("service error", {"site": "errors/internal-error"}, ["Try again"]),
    ]
    for name, choice, expects in states:
        # A rate limit is remembered for its Retry-After and an old answer is kept, so each state starts clean.
        subprocess.run(["npx", "wp-env", "run", "cli", "wp", "eval", 'delete_option( "readiness_index_site_cache" ); delete_transient( "ri_backoff" );'], capture_output=True, check=False)
        if name == "revoked key":
            pass
        state(**choice)
        status, page, text = screen()
        ok = status == 200 and all(e.lower() in text.lower() for e in expects) and "Fatal error" not in page and "Warning:" not in page and "Notice:" not in page
        check("%s draws" % name, ok, text[:240])
        if name == "revoked key":
            # Reconnect so the next states have a key again.
            status, headers, _ = press(page, "readiness_index_connect")
            _, _, consent = fetch(headers["Location"].replace(SEEN_AS, API))
            fields = {m.group(1): html.unescape(m.group(2)) for m in re.finditer(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', consent)}
            _, _, relay = fetch(html.unescape(callback), fields, anonymous)
            relayed = {m.group(1): html.unescape(m.group(2)) for m in re.finditer(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', relay)}
            fetch(html.unescape(callback), relayed, stay)

    print("4. The buttons")
    state(site="site/done-first-scan", scan="scan/started", status="scan-status/done", verify="verify/verified")
    subprocess.run(["npx", "wp-env", "run", "cli", "wp", "eval", 'delete_option( "readiness_index_site_cache" ); delete_transient( "ri_backoff" );'], capture_output=True, check=False)
    _, page, _ = screen()
    before = len(plugin_requests())
    status, headers, _ = press(page, "readiness_index_scan")
    check("Scan again posts and returns to the screen", status in (302, 303) and "page=readiness-index" in headers.get("Location", ""))
    new = [e["path"] for e in plugin_requests()[before:]]
    check("Scan again made the one POST /cms/v1/scan", new == ["/cms/v1/scan"], str(new))
    _, page, text = screen()
    before = len(plugin_requests())
    status, headers, _ = press(page, "readiness_index_verify")
    check("Check ownership posts", status in (302, 303))
    check("Check ownership made the one POST /cms/v1/verify", [e["path"] for e in plugin_requests()[before:]] == ["/cms/v1/verify"])
    _, page, text = screen()
    check("the verify result is said", "Verified" in text or "verified" in text)
    status, headers, _ = press(page, "readiness_index_dismiss")
    check("Not now is remembered", status in (302, 303))
    _, page, text = screen()
    check("the weekly-watch offer is gone for this user", "Watch this site every week" not in text)

    print("5. Disconnect")
    _, page, _ = screen()
    confirm = re.search(r'<form method="get"[^>]*>(.*?)</form>', page, flags=re.S).group(1)
    query = {m.group(1): html.unescape(m.group(2)) for m in re.finditer(r'<input type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"', confirm)}
    check("the confirm step is a link with its own nonce", "_wpnonce" in query and query.get("ri_confirm") == "disconnect")
    _, _, shown = fetch(WP + "/wp-admin/admin.php?" + urllib.parse.urlencode(query))
    check("the confirmation asks first", "readiness_index_disconnect" in shown)
    bare = text_of(fetch(PAGE + "&ri_confirm=disconnect")[2])
    check("the same address without the nonce shows the ordinary screen", "Are you sure" not in bare)
    status, headers, _ = press(shown, "readiness_index_disconnect")
    check("Disconnect posts", status in (302, 303))
    before = len(api_log())
    _, page, text = screen()
    check("after Disconnect the screen offers Connect again", "Connect to Readiness Index" in text)
    check("and made no request to do it", len(api_log()) == before)
    check("the ownership address is a 404 again", fetch(WP + "/.well-known/readinessindex-verify")[0] == 404)

    print("6. The service is unreachable")
    state(site="site/done-first-scan")
    _, headers, _ = press(screen()[1], "readiness_index_connect")
    _, _, consent = fetch(headers["Location"].replace(SEEN_AS, API))
    fields = {m.group(1): html.unescape(m.group(2)) for m in re.finditer(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', consent)}
    _, _, relay = fetch(html.unescape(callback), fields, anonymous)
    relayed = {m.group(1): html.unescape(m.group(2)) for m in re.finditer(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', relay)}
    fetch(html.unescape(callback), relayed, stay)
    subprocess.run(["npx", "wp-env", "run", "cli", "wp", "eval", 'delete_option( "readiness_index_site_cache" ); delete_transient( "ri_backoff" );'], capture_output=True, check=False)
    stop_stand_in()
    status, page, text = screen()
    check("with the service down the screen says so and offers Try again", status == 200 and "could not be reached" in text.lower() and "Try again" in text, text[:240])
    check("and WP_DEBUG still logged nothing", debug_log() == "", debug_log())
    print()
    print("FAILURES: %d" % len(FAILURES))
    for failure in FAILURES:
        print("  - " + failure)
    return 1 if FAILURES else 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    finally:
        stop_stand_in()
