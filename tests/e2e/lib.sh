#!/usr/bin/env bash
# Helpers for driving wp-admin over HTTP in a local wp-env run: sign in, read a preview, press
# Apply or Undo exactly as a browser would (the nonce and the preview hash come from the page).
#
# Local development credentials only: wp-env's own default administrator on localhost.

BASE="${BASE:-http://localhost:8888}"
JAR="${JAR:-/tmp/claude-501/ri-e2e.jar}"

ri_login() {
	rm -f "${JAR}"
	curl -s -c "${JAR}" -b "${JAR}" -o /dev/null "${BASE}/wp-login.php"
	curl -s -c "${JAR}" -b "${JAR}" -o /dev/null -w '%{http_code}' \
		--data-urlencode 'log=admin' --data-urlencode 'pwd=password' --data-urlencode 'wp-submit=Log In' \
		--data-urlencode "redirect_to=${BASE}/wp-admin/" -H 'Cookie: wordpress_test_cookie=WP Cookie check' \
		"${BASE}/wp-login.php"
	echo
}

ri_get() {
	curl -s -b "${JAR}" "${BASE}/wp-admin/$1"
}

# Prints the hidden fields of the Apply form on a preview page as curl --data-urlencode arguments.
ri_apply_fields() {
	python3 - "$1" <<'PY'
import html, re, sys
page = open(sys.argv[1], encoding='utf-8').read()
form = re.search(r'<form method="post" class="ri-apply".*?</form>', page, re.S)
if not form:
    sys.exit('no apply form')
for tag in re.finditer(r'<input type="hidden"[^>]*>', form.group(0)):
    name = re.search(r'name="([^"]+)"', tag.group(0))
    value = re.search(r'value="([^"]*)"', tag.group(0))
    if name and value:
        print(f"{html.unescape(name.group(1))}={html.unescape(value.group(1))}")
disabled = 'disabled="disabled"' in re.search(r'<button type="submit" class="button button-primary"[^>]*>', form.group(0)).group(0)
print(f"__disabled={'1' if disabled else '0'}")
PY
}

ri_post_apply() {
	local file="$1"; shift
	local args=()
	while IFS= read -r line; do
		[ "${line%%=*}" = "__disabled" ] && continue
		args+=(--data-urlencode "${line}")
	done < <(ri_apply_fields "${file}")
	curl -s -b "${JAR}" -c "${JAR}" -o /dev/null -w '%{http_code} %{redirect_url}\n' "${args[@]}" "${BASE}/wp-admin/admin-post.php"
}

# Prints the notices, the changes table and whether an Undo form is on a page read from stdin.
ri_show() {
	python3 -c "
import re,html,sys
p=sys.stdin.read()
for m in re.finditer(r'<div class=\"notice[^>]*>.*?</div>',p,re.S):
    t=html.unescape(re.sub(r'\s+',' ',re.sub(r'<[^>]+>',' ',m.group(0)))).strip()
    if 'nonce' not in t.lower(): print('NOTICE:',t)
m=re.search(r'<table class=\"widefat striped ri-changes\".*?</table>',p,re.S)
if m: print('TABLE:',html.unescape(re.sub(r'\s+',' ',re.sub(r'<[^>]+>',' ',m.group(0)))))
print('UNDO FORM' if 'value=\"readiness_index_undo\"' in p else 'no undo form')
"
}

# Posts the first Undo form on a Changes page read from a file.
ri_post_undo() {
	python3 - "$1" > /tmp/claude-501/undo-fields.txt <<'PY'
import html, re, sys
page = open(sys.argv[1], encoding='utf-8').read()
form = re.search(r'<form method="post" class="ri-form"[^>]*>(?:(?!</form>).)*readiness_index_undo.*?</form>', page, re.S)
if not form:
    sys.exit('no undo form')
for tag in re.finditer(r'<input type="hidden"[^>]*>', form.group(0)):
    name = re.search(r'name="([^"]+)"', tag.group(0))
    value = re.search(r'value="([^"]*)"', tag.group(0))
    if name and value:
        print(f"{html.unescape(name.group(1))}={html.unescape(value.group(1))}")
PY
	local args=()
	while IFS= read -r line; do args+=(--data-urlencode "${line}"); done < /tmp/claude-501/undo-fields.txt
	curl -s -b "${JAR}" -c "${JAR}" -o /dev/null -w '%{http_code} %{redirect_url}\n' "${args[@]}" "${BASE}/wp-admin/admin-post.php"
}
