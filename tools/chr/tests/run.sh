#!/bin/bash
# Harness tests without a router: OH1-OH5 of plan §7.4, OH6 (smoke.php --ssh-login), OH7 (upgrade.php) and OH8 (ipv6 package in provision.php).
# Called as the first line of the chr job script in CI.
set -euo pipefail

TESTS_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
CHR_DIR=$(cd "$TESTS_DIR/.." && pwd)
VERSIONS_FILE="$CHR_DIR/versions.txt"

# Source run-live.sh functions
# shellcheck source=../run-live.sh
source "$CHR_DIR/run-live.sh"

SHA=16f07222a3213352c9c4fbea4f4dec6a8ffb78fdc0fa5e398c23103ed357c6eb

# Fails unless the actual output ($3) equals the golden ($2) line by line.
assert_golden() {
	if [ "$2" != "$3" ]; then
		echo "$1 FAIL: output differs from the golden (< golden, > actual):" >&2
		diff <(printf '%s\n' "$2") <(printf '%s\n' "$3") >&2 || true
		return 1
	fi
}

# Fails when parse_version_row accepts version $2 of the rows in $3.
assert_row_rejected() {
	local tmp_file
	tmp_file=$(mktemp)
	printf '%s\n' "$3" >"$tmp_file"
	if (parse_version_row "$2" "$tmp_file") >/dev/null 2>&1; then
		rm -f "$tmp_file"
		echo "OH1 FAIL: $1 should fail" >&2
		return 1
	fi
	rm -f "$tmp_file"
}

test_oh1() {
	echo "--- OH1: versions.txt validation ---"
	local tmp_file

	if (CHR_VERSION="unknown-version" bash "$CHR_DIR/run-live.sh") >/dev/null 2>&1; then
		echo "OH1 FAIL: unknown version should fail" >&2
		return 1
	fi
	assert_row_rejected "row with 5 columns (no sha-source)" bad1 "bad1 stable $SHA json image"
	assert_row_rejected "unknown ssh-path" bad2 "bad2 stable $SHA foo image official"
	assert_row_rejected "unknown install" bad3 "bad3 stable $SHA json foo official"
	assert_row_rejected "unknown sha-source" bad4 "bad4 stable $SHA json image foo"
	assert_row_rejected "extra column" bad5 "bad5 stable $SHA json image official surplus"
	assert_row_rejected "upgrade without a base row" bad6 "bad6 stable $SHA text upgrade:0.1 official"
	assert_row_rejected "upgrade whose base row is not an image" bad7 "bad7 stable $SHA text upgrade:base7 official
base7 stable $SHA text upgrade:bad7 official"
	assert_row_rejected "upgrade with sha-source=computed" bad8 "bad8 stable $SHA text upgrade:base8 computed
base8 stable $SHA text image computed"

	tmp_file=$(mktemp)
	printf '%s\n' "# comment line" "ok1 stable $SHA text upgrade:base1 official  # trailing comment" \
		"base1 stable $SHA text image computed" >"$tmp_file"
	if ! (parse_version_row ok1 "$tmp_file") >/dev/null; then
		rm -f "$tmp_file"
		echo "OH1 FAIL: valid upgrade row with a trailing comment should pass" >&2
		return 1
	fi
	rm -f "$tmp_file"

	echo "OH1 OK"
}

test_oh2() {
	echo "--- OH2: chr.sh start refuses a bad zip and an upgrade row ---"
	local tmp_dir out
	tmp_dir=$(mktemp -d)
	trap "rm -rf '$tmp_dir'" RETURN

	echo "fake content" > "$tmp_dir/chr-6.49.18.img.zip"
	out=$(CHR_CACHE="$tmp_dir" CHR_VERSION="6.49.18" bash "$CHR_DIR/chr.sh" start 2>&1 || true)
	if ! echo "$out" | grep -Fq "SHA256 mismatch"; then
		echo "OH2 FAIL: expected SHA256 mismatch error for 6.49.18, got: $out" >&2
		return 1
	fi

	echo "fake content" > "$tmp_dir/chr-6.43.img.zip"
	out=$(CHR_CACHE="$tmp_dir" CHR_VERSION="6.43" bash "$CHR_DIR/chr.sh" start 2>&1 || true)
	if ! echo "$out" | grep -Fq "SHA256 mismatch"; then
		echo "OH2 FAIL: expected SHA256 mismatch error for 6.43, got: $out" >&2
		return 1
	fi

	# 6.49.23 is installed by upgrade: chr.sh must refuse before it looks for (or downloads) a zip
	echo "fake content" > "$tmp_dir/chr-6.49.23.img.zip"
	if out=$(CHR_CACHE="$tmp_dir" CHR_VERSION="6.49.23" bash "$CHR_DIR/chr.sh" start 2>&1); then
		echo "OH2 FAIL: chr.sh start of an install=upgrade row should fail" >&2
		return 1
	fi
	if ! echo "$out" | grep -Fq "has install=upgrade:6.49.20: there is no image to boot"; then
		echo "OH2 FAIL: expected the upgrade refusal for 6.49.23, got: $out" >&2
		return 1
	fi

	echo "OH2 OK"
}

test_oh3() {
	echo "--- OH3: legacy MD5 login and challenge handling ---"
	php "$TESTS_DIR/oh3.php"
}

test_oh4() {
	echo "--- OH4: patient SSH banner probe ---"
	php "$TESTS_DIR/oh4.php"
}

GOLDEN_JSON_IMAGE=$(cat <<'EOF'
env | export MIKROTIK_LIVE_SSH_PATH=json
env | export CHR_LIVE_TIMEOUT=300
env | export MIKROTIK_LIVE_HOST=127.0.0.1
env | export MIKROTIK_LIVE_PORT_API=18728
env | export MIKROTIK_LIVE_PORT_TLS=18729
env | export MIKROTIK_LIVE_PORT_SSH=18722
env | export MIKROTIK_LIVE_USER=admin
env | export MIKROTIK_LIVE_PASS="$CHR_PASSWORD"
install | bash "$DIR/chr.sh" install
start (download/verify/unzip/boot CHR 7.24.5) | CHR_VERSION=7.24.5 bash "$DIR/chr.sh" start
wait for /login | CHR_VERSION=7.24.5 bash "$DIR/chr.sh" wait
provision | php "$DIR/provision.php"
wait for SSH banner | bash "$DIR/chr.sh" wait-ssh
smoke (on failure: provision once, smoke once more) | php "$DIR/smoke.php" || { echo "=== smoke failed: running provision.php once more"; php "$DIR/provision.php" && php "$DIR/smoke.php"; }
smoke ssh-login | php "$DIR/smoke.php" --ssh-login
live tests | timeout 300 vendor/bin/phpunit --group live --exclude-group live-text "$@"
EOF
)

GOLDEN_TEXT_IMAGE=$(cat <<'EOF'
env | export MIKROTIK_LIVE_SSH_PATH=text
env | export CHR_LIVE_TIMEOUT=600
env | export MIKROTIK_LIVE_HOST=127.0.0.1
env | export MIKROTIK_LIVE_PORT_API=18728
env | export MIKROTIK_LIVE_PORT_TLS=18729
env | export MIKROTIK_LIVE_PORT_SSH=18722
env | export MIKROTIK_LIVE_USER=admin
env | export MIKROTIK_LIVE_PASS="$CHR_PASSWORD"
install | bash "$DIR/chr.sh" install
start (download/verify/unzip/boot CHR 6.49.18) | CHR_VERSION=6.49.18 bash "$DIR/chr.sh" start
wait for /login | CHR_VERSION=6.49.18 bash "$DIR/chr.sh" wait
provision | php "$DIR/provision.php"
wait for SSH banner | bash "$DIR/chr.sh" wait-ssh
smoke (on failure: provision once, smoke once more) | php "$DIR/smoke.php" || { echo "=== smoke failed: running provision.php once more"; php "$DIR/provision.php" && php "$DIR/smoke.php"; }
smoke ssh-login | php "$DIR/smoke.php" --ssh-login
live tests | timeout 600 vendor/bin/phpunit --group live --exclude-group live-json "$@"
EOF
)

GOLDEN_TEXT_UPGRADE=$(cat <<'EOF'
env | export MIKROTIK_LIVE_SSH_PATH=text
env | export CHR_LIVE_TIMEOUT=600
env | export MIKROTIK_LIVE_HOST=127.0.0.1
env | export MIKROTIK_LIVE_PORT_API=18728
env | export MIKROTIK_LIVE_PORT_TLS=18729
env | export MIKROTIK_LIVE_PORT_SSH=18722
env | export MIKROTIK_LIVE_USER=admin
env | export MIKROTIK_LIVE_PASS="$CHR_PASSWORD"
install | bash "$DIR/chr.sh" install
verify NPK routeros-x86-6.49.23.npk | verify_npk 6.49.23 "$CHR_CACHE" "$VERSIONS_FILE"
start base image 6.49.20 | CHR_VERSION=6.49.20 bash "$DIR/chr.sh" start
wait for /login (base 6.49.20) | CHR_VERSION=6.49.20 bash "$DIR/chr.sh" wait
provision (base 6.49.20) | php "$DIR/provision.php" --defer-reboot
wait for SSH banner (base 6.49.20) | bash "$DIR/chr.sh" wait-ssh
upgrade 6.49.20 to 6.49.23 by NPK routeros-x86-6.49.23.npk | php "$DIR/upgrade.php" 6.49.23 "$CHR_CACHE/routeros-x86-6.49.23.npk"
provision (upgraded 6.49.23) | php "$DIR/provision.php"
wait for SSH banner | bash "$DIR/chr.sh" wait-ssh
smoke (on failure: provision once, smoke once more) | php "$DIR/smoke.php" || { echo "=== smoke failed: running provision.php once more"; php "$DIR/provision.php" && php "$DIR/smoke.php"; }
smoke ssh-login | php "$DIR/smoke.php" --ssh-login
live tests | timeout 600 vendor/bin/phpunit --group live --exclude-group live-json "$@"
EOF
)

# execute_plan of the upgrade row with stubbed commands; the first smoke fails, provision + smoke repeat once.
GOLDEN_TRACE_UPGRADE=$(cat <<'EOF'
step: install
bash $DIR/chr.sh install [CHR_VERSION=6.49.23]
step: verify NPK routeros-x86-6.49.23.npk
verify_npk 6.49.23 /cache $DIR/versions.txt
step: start base image 6.49.20
bash $DIR/chr.sh start [CHR_VERSION=6.49.20]
step: wait for /login (base 6.49.20)
bash $DIR/chr.sh wait [CHR_VERSION=6.49.20]
step: provision (base 6.49.20)
php $DIR/provision.php --defer-reboot
step: wait for SSH banner (base 6.49.20)
bash $DIR/chr.sh wait-ssh [CHR_VERSION=6.49.23]
step: upgrade 6.49.20 to 6.49.23 by NPK routeros-x86-6.49.23.npk
php $DIR/upgrade.php 6.49.23 /cache/routeros-x86-6.49.23.npk
step: provision (upgraded 6.49.23)
php $DIR/provision.php
step: wait for SSH banner
bash $DIR/chr.sh wait-ssh [CHR_VERSION=6.49.23]
step: smoke (on failure: provision once, smoke once more)
php $DIR/smoke.php
=== smoke failed: running provision.php once more
php $DIR/provision.php
php $DIR/smoke.php
step: smoke ssh-login
php $DIR/smoke.php --ssh-login
step: live tests
timeout 600 vendor/bin/phpunit --group live --exclude-group live-json --filter Foo
env: MIKROTIK_LIVE_SSH_PATH=text CHR_LIVE_TIMEOUT=600 MIKROTIK_LIVE_HOST=127.0.0.1 MIKROTIK_LIVE_PORT_API=18728 MIKROTIK_LIVE_PORT_TLS=18729 MIKROTIK_LIVE_PORT_SSH=18722 MIKROTIK_LIVE_USER=admin MIKROTIK_LIVE_PASS=pw
EOF
)

# Same, but the repeated smoke fails too: the run stops there with a non-zero exit.
GOLDEN_TRACE_SMOKE_FAILS=$(cat <<'EOF'
step: install
bash $DIR/chr.sh install [CHR_VERSION=7.24.5]
step: start (download/verify/unzip/boot CHR 7.24.5)
bash $DIR/chr.sh start [CHR_VERSION=7.24.5]
step: wait for /login
bash $DIR/chr.sh wait [CHR_VERSION=7.24.5]
step: provision
php $DIR/provision.php
step: wait for SSH banner
bash $DIR/chr.sh wait-ssh [CHR_VERSION=7.24.5]
step: smoke (on failure: provision once, smoke once more)
php $DIR/smoke.php
=== smoke failed: running provision.php once more
php $DIR/provision.php
php $DIR/smoke.php
EOF
)

test_oh5() {
	echo "--- OH5: planned steps (groups, timeout, install, smoke gate) and their execution ---"
	local out rc tmp_dir
	unset CHR_LIVE_TIMEOUT || true

	out=$(plan_live_run "7.24.5" "$VERSIONS_FILE" && print_plan)
	assert_golden "OH5 json/image (7.24.5)" "$GOLDEN_JSON_IMAGE" "$out"
	out=$(plan_live_run "6.49.18" "$VERSIONS_FILE" && print_plan)
	assert_golden "OH5 text/image (6.49.18)" "$GOLDEN_TEXT_IMAGE" "$out"
	out=$(plan_live_run "6.49.23" "$VERSIONS_FILE" && print_plan)
	assert_golden "OH5 text/upgrade:6.49.20 (6.49.23)" "$GOLDEN_TEXT_UPGRADE" "$out"

	# The environment variable CHR_LIVE_TIMEOUT overrides the default
	out=$(CHR_LIVE_TIMEOUT=1234 plan_live_run "7.24.5" "$VERSIONS_FILE" && print_plan)
	assert_golden "OH5 CHR_LIVE_TIMEOUT=1234" "${GOLDEN_JSON_IMAGE//300/1234}" "$out"

	# Without a version or with a broken CHR_LIVE_TIMEOUT the run fails instead of planning
	if (plan_live_run "" "$VERSIONS_FILE") >/dev/null 2>&1; then
		echo "OH5 FAIL: an empty CHR_VERSION should fail" >&2
		return 1
	fi
	if (CHR_LIVE_TIMEOUT=abc plan_live_run "7.24.5" "$VERSIONS_FILE") >/dev/null 2>&1; then
		echo "OH5 FAIL: a non-numeric CHR_LIVE_TIMEOUT should fail" >&2
		return 1
	fi

	# main() runs exactly the plan: execution trace with stubbed commands
	rc=0
	out=$(bash "$TESTS_DIR/oh5-trace.sh" 6.49.23 1 --filter Foo) || rc=$?
	[ "$rc" -eq 0 ] || { echo "OH5 FAIL: trace of 6.49.23 exited with $rc" >&2; return 1; }
	assert_golden "OH5 execution trace (6.49.23, first smoke fails)" "$GOLDEN_TRACE_UPGRADE" "$out"
	rc=0
	out=$(bash "$TESTS_DIR/oh5-trace.sh" 7.24.5 2) || rc=$?
	[ "$rc" -ne 0 ] || { echo "OH5 FAIL: two failed smokes must fail the run" >&2; return 1; }
	assert_golden "OH5 execution trace (7.24.5, both smokes fail)" "$GOLDEN_TRACE_SMOKE_FAILS" "$out"

	# NPK with a mismatching SHA256 fails
	tmp_dir=$(mktemp -d)
	trap "rm -rf '$tmp_dir'" RETURN
	echo "corrupt npk content" > "$tmp_dir/routeros-x86-6.49.23.npk"
	if (verify_npk "6.49.23" "$tmp_dir" "$VERSIONS_FILE") >/dev/null 2>&1; then
		echo "OH5 FAIL: verify_npk should fail on SHA mismatch" >&2
		return 1
	fi

	echo "OH5 OK"
}

test_oh6() {
	echo "--- OH6: smoke.php --ssh-login without phpseclib ---"
	local tmp_dir out
	tmp_dir=$(mktemp -d)
	trap "rm -rf '$tmp_dir'" RETURN
	# A copy without vendor/: phpseclib is missing, composer.json decides between skip and failure
	mkdir -p "$tmp_dir/tools/chr"
	cp "$CHR_DIR/smoke.php" "$CHR_DIR/routeros.php" "$tmp_dir/tools/chr/"

	printf '%s\n' '{"name": "lossik/no-ssh-here", "require": {"php": "^8.2"}}' >"$tmp_dir/composer.json"
	if ! out=$(CHR_PASSWORD=x php "$tmp_dir/tools/chr/smoke.php" --ssh-login 2>&1); then
		echo "OH6 FAIL: without an SSH dependency --ssh-login should skip with exit 0, got: $out" >&2
		return 1
	fi
	if [ "$out" != "ssh login skipped (phpseclib not installed - no-ssh-here has no SSH)" ]; then
		echo "OH6 FAIL: unexpected skip line: $out" >&2
		return 1
	fi

	printf '%s\n' '{"name": "lossik/ssh-here", "require": {"phpseclib/phpseclib": "^3.0"}}' >"$tmp_dir/composer.json"
	if out=$(CHR_PASSWORD=x php "$tmp_dir/tools/chr/smoke.php" --ssh-login 2>&1); then
		echo "OH6 FAIL: a repository requiring phpseclib must fail --ssh-login without it, got: $out" >&2
		return 1
	fi
	if ! echo "$out" | grep -Fq "composer.json requires phpseclib/phpseclib but it is not installed"; then
		echo "OH6 FAIL: unexpected error: $out" >&2
		return 1
	fi

	echo "OH6 OK"
}

test_oh7() {
	echo "--- OH7: upgrade.php checks (packages, reboot reply, wait error) ---"
	php "$TESTS_DIR/upgrade.php"
}

test_oh8() {
	echo "--- OH8: provision.php enables a disabled ipv6 package (reboot unless deferred), leaves it alone otherwise ---"
	php "$TESTS_DIR/oh8.php"
}

main() {
	echo "=== Running harness tests OH1-OH8 ==="
	test_oh1
	test_oh2
	test_oh3
	test_oh4
	test_oh5
	test_oh6
	test_oh7
	test_oh8
	echo "=== All harness tests OH1-OH8 PASSED ==="
}

main "$@"
