#!/usr/bin/env bash
# Tests for bin/deploy-pantheon. Plain bash, no framework.
#
# Nothing here reaches Pantheon. `terminus`, `git` (for the plugin repo's status and for
# clone and push), `curl` and `ssh` are shims placed first on PATH, and each one records
# the calls it receives. The assertions are about which calls were made and which were
# not, because the dangerous failure is a command reaching a site it should not.
set -uo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
script="${here}/../bin/deploy-pantheon"
REAL_GIT="$(command -v git)"
export REAL_GIT

work="$(mktemp -d "${TMPDIR:-/tmp}/deploy-pantheon-test.XXXXXX")"
trap 'rm -rf "${work}"' EXIT
shims="${work}/shims"
mkdir -p "${shims}"

cat > "${shims}/terminus" <<'SHIM'
#!/usr/bin/env bash
echo "terminus $*" >> "${FAKE_LOG}"
case "$1" in
	env:info)
		printf '{"id":"dev","domain":"dev-fake.pantheonsite.io","connection_mode":"%s"}\n' "${FAKE_MODE:-sftp}" ;;
	env:diffstat)
		echo "${FAKE_DIFFSTAT:-[]}" ;;
	connection:info)
		echo '{"git_command":"git clone ssh://codeserver.dev.0000@codeserver.dev.0000.drush.in:2222/~/repository.git fake-site"}' ;;
	wp)
		case "$*" in *"plugin list"*) echo "readiness-index active 0.1.0" ;; *eval*) echo 1 ;; esac ;;
esac
exit 0
SHIM

cat > "${shims}/curl" <<'SHIM'
#!/usr/bin/env bash
echo "curl $*" >> "${FAKE_LOG}"
printf 200
SHIM

cat > "${shims}/git" <<'SHIM'
#!/usr/bin/env bash
# The plugin repository's status and hash are faked; the temporary Pantheon clone is a
# real repository so the commit the script makes is real. Clone and push are intercepted.
dir=""
if [ "${1:-}" = "-C" ]; then dir="$2"; shift 2; fi
echo "git ${dir:+-C <dir> }$*" >> "${FAKE_LOG}"
case "${dir}" in
	*pantheon-repo*)
		if [ "$1" = "push" ]; then
			# Keep what would have been pushed, since the script deletes its clone.
			cp "${dir}/wp-config.php" "${FAKE_OUT}/wp-config.php"
			( cd "${dir}" && find wp-content -type f | sort ) > "${FAKE_OUT}/files.txt"
			"${REAL_GIT}" -C "${dir}" log -1 --format=%B > "${FAKE_OUT}/message.txt"
			exit 0
		fi
		exec "${REAL_GIT}" -C "${dir}" "$@" ;;
	"")
		if [ "$1" = "clone" ]; then
			dest="${@: -1}"
			mkdir -p "${dest}/wp-content/plugins"
			{
				echo "<?php"
				if [ "${FAKE_PRESEED_BLOCK:-0}" = 1 ]; then
					echo "// BEGIN readiness-index api origin (written by bin/deploy-pantheon, do not edit)"
					echo "define( 'READINESS_INDEX_API_ORIGIN', 'https://old.example.com' );"
					echo "// END readiness-index api origin"
					echo
				fi
				echo "define( 'DB_NAME', 'x' );"
				echo "/* That's all, stop editing! Happy publishing. */"
				echo "require_once ABSPATH . 'wp-settings.php';"
			} > "${dest}/wp-config.php"
			"${REAL_GIT}" -C "${dest}" init -q
			"${REAL_GIT}" -C "${dest}" add -A
			"${REAL_GIT}" -C "${dest}" -c user.name=t -c user.email=t@example.com commit -q -m seed
			exit 0
		fi
		exec "${REAL_GIT}" "$@" ;;
	*)
		case "$1" in
			status) printf '%s' "${FAKE_STATUS:-}"; exit 0 ;;
			rev-parse) echo abc1234; exit 0 ;;
		esac
		exec "${REAL_GIT}" -C "${dir}" "$@" ;;
esac
SHIM
chmod +x "${shims}"/*

failures=0
passes=0
check() { # check <description> <command...>
	local description="$1"; shift
	if "$@"; then
		passes=$((passes + 1)); echo "ok   ${description}"
	else
		failures=$((failures + 1)); echo "FAIL ${description}"
	fi
}
has() { grep -q -- "$1" "$2"; }
lacks() { ! grep -q -- "$1" "$2"; }

# Run the script under the shims; sets rc and leaves output in out.txt and calls in log.txt.
run() {
	: > "${work}/log.txt"
	rm -rf "${work}/out"; mkdir -p "${work}/out"
	PATH="${shims}:${PATH}" FAKE_LOG="${work}/log.txt" FAKE_OUT="${work}/out" \
		GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@example.com GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@example.com \
		"${script}" "$@" > "${work}/out.txt" 2>&1
	rc=$?
}
reset_env() { unset FAKE_MODE FAKE_DIFFSTAT FAKE_STATUS FAKE_PRESEED_BLOCK; }

# A stand-in for the web repository's environments table.
cat > "${work}/EDGE.md" <<'DOC'
| Environment | Fly app | Host | Branch | Workflow |
|---|---|---|---|---|
| Production | `ten7-air` | `readinessindex.io` | `main` | production.yml |
| Edge | `ten7-air-stage` | `edge.readinessindex.io` | `edge` | edge.yml |
DOC
export READINESS_EDGE_DOC="${work}/EDGE.md"

echo "-- allowlist"
for args in "--site some-client-site" "--site readiness-index-wordpress-plugin.dev" "--env live" "--env test" "--env multidev-x" "--site readiness-index-wordpress-plugin --env live"; do
	reset_env
	# shellcheck disable=SC2086
	run ${args} --dry-run
	check "refuses: ${args}" test "${rc}" -ne 0
	check "  says why: ${args}" has "refusing" "${work}/out.txt"
	check "  no terminus or git call was made: ${args}" test ! -s "${work}/log.txt"
done

echo "-- dirty plugin tree"
reset_env; export FAKE_STATUS=" M src/Autoloader.php"
run --dry-run
check "refuses a dirty tree" test "${rc}" -ne 0
check "  says dirty" has "dirty" "${work}/out.txt"
check "  made no terminus call" lacks "^terminus" "${work}/log.txt"
run --dry-run --allow-dirty
check "--allow-dirty goes on" test "${rc}" -eq 0

echo "-- uncommitted SFTP changes"
reset_env; export FAKE_MODE=sftp FAKE_DIFFSTAT='{"wp-content/plugins/readiness-index/a.php":{"status":"M"}}'
run
check "refuses uncommitted SFTP changes" test "${rc}" -ne 0
check "  says SFTP" has "uncommitted SFTP changes" "${work}/out.txt"
check "  did not switch the mode" lacks "connection:set" "${work}/log.txt"
check "  did not clone or push" lacks "git clone\|push" "${work}/log.txt"

echo "-- dry run"
reset_env; export FAKE_MODE=sftp
run --dry-run
check "dry run succeeds" test "${rc}" -eq 0
check "  states the edge host it found" has "lists the edge host as edge.readinessindex.io" "${work}/out.txt"
check "  checked the env diffstat" has "terminus env:diffstat readiness-index-wordpress-plugin.dev" "${work}/log.txt"
check "  read the git URL" has "terminus connection:info readiness-index-wordpress-plugin.dev" "${work}/log.txt"
check "  cloned" has "git clone" "${work}/log.txt"
check "  committed locally" has "git -C <dir> commit" "${work}/log.txt"
check "  did not switch the mode" lacks "terminus connection:set" "${work}/log.txt"
check "  did not push" lacks "push" "${work}/log.txt"
check "  did not wait for a workflow" lacks "workflow:wait" "${work}/log.txt"
check "  did not activate" lacks "plugin activate" "${work}/log.txt"
check "  printed the writes it skipped" has "DRY RUN, not run: terminus connection:set readiness-index-wordpress-plugin.dev git" "${work}/out.txt"
check "  every terminus call targets the allowed environment" \
	test -z "$(grep '^terminus' "${work}/log.txt" | grep -v 'readiness-index-wordpress-plugin\.dev')"

echo "-- real run (shimmed)"
reset_env; export FAKE_MODE=sftp
run --origin https://edge.example.com
check "real run succeeds" test "${rc}" -eq 0
sequence="$(grep -o '^terminus [a-z:]*\|^git -C <dir> push\|^git clone' "${work}/log.txt" | tr '\n' ',')"
check "  order: info, diffstat, set git, connection info, clone, push, wait, activate" \
	test "${sequence}" = "terminus env:info,terminus env:diffstat,terminus connection:set,terminus connection:info,git clone,git -C <dir> push,terminus workflow:wait,terminus wp,terminus env:info,terminus wp,terminus wp,"
check "  switched to git mode" has "terminus connection:set readiness-index-wordpress-plugin.dev git" "${work}/log.txt"
check "  activated the plugin" has "terminus wp readiness-index-wordpress-plugin.dev -- plugin activate readiness-index" "${work}/log.txt"
check "  commit message names the hash" has "abc1234" "${work}/out/message.txt"
check "  commit message says whether the tree was clean" has "clean working tree" "${work}/out/message.txt"
check "  commit message has the co-author line" has "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>" "${work}/out/message.txt"
check "  wp-config has the origin" has "define( 'READINESS_INDEX_API_ORIGIN', 'https://edge.example.com' );" "${work}/out/wp-config.php"
block_line="$(grep -n 'BEGIN readiness-index' "${work}/out/wp-config.php" | cut -d: -f1)"
stop_line="$(grep -n "That's all, stop editing" "${work}/out/wp-config.php" | cut -d: -f1)"
check "  the block is above the stop-editing line" test -n "${block_line}" -a "${block_line}" -lt "${stop_line}"
check "  only the zip's files were shipped" has "wp-content/plugins/readiness-index/readiness-index.php" "${work}/out/files.txt"
check "  no tests, vendor, docs or CLAUDE.md shipped" \
	test -z "$(grep -E 'tests/|vendor/|docs/|CLAUDE.md|\.github' "${work}/out/files.txt")"

echo "-- idempotent origin block"
reset_env; export FAKE_MODE=git FAKE_PRESEED_BLOCK=1
run --origin https://edge.example.com
check "git-mode run succeeds" test "${rc}" -eq 0
check "  skipped diffstat and the mode switch in git mode" lacks "env:diffstat\|connection:set" "${work}/log.txt"
check "  one block only" test "$(grep -c 'BEGIN readiness-index' "${work}/out/wp-config.php")" -eq 1
check "  a rerun adds no blank lines" test "$(grep -c '^$' "${work}/out/wp-config.php")" -eq 1
check "  the old origin is gone" lacks "old.example.com" "${work}/out/wp-config.php"

echo "-- origin validation"
reset_env
run --origin "https://x.example.com'; system('id'); //" --dry-run
check "refuses an origin that could become code" test "${rc}" -ne 0
check "  made no terminus call" lacks "^terminus" "${work}/log.txt"

echo "-- the origin constant lives only in the Pantheon repository"
check "plugin source does not define it" \
	test -z "$(grep -rn "define( *'READINESS_INDEX_API_ORIGIN'" "${here}/../src" "${here}/../readiness-index.php" 2>/dev/null)"

echo
echo "${passes} passed, ${failures} failed"
[ "${failures}" -eq 0 ]
