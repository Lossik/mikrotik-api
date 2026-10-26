#!/bin/bash
# Full live run against a MikroTik CHR booted in this container:
# PHP version check -> dependencies (tools/ci.sh --deps-only) -> the planned steps of the version row: install QEMU ->
# start CHR -> wait for /login -> provision [-> upgrade by NPK -> provision] -> wait for the SSH banner
# -> smoke (on failure one more provision and smoke) -> smoke --ssh-login -> phpunit --group live -> stop (always).
#
#   bash tools/chr/run-live.sh   (from the repository root)
#
# Environment: CHR_VERSION, CHR_CACHE, CHR_BOOT_TIMEOUT (see chr.sh); CHR_PASSWORD (admin password to set,
# default: random per run), CHR_LIVE_TIMEOUT (timeout for the phpunit live phase; default by ssh-path),
# CHR_REBOOT_TIMEOUT (provision.php: wait for the router after the reboot that enables the ipv6 package; default 600).
#
# main() runs exactly the steps plan_live_run() plans (execute_plan); print_plan prints the same steps, and the
# harness tests (tests/run.sh, OH5) compare that output and an execution trace with goldens.
set -euo pipefail

DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
VERSIONS_FILE="$DIR/versions.txt"
T_START=$(date +%s)
T_STEP=$T_START

step() {
	local now
	now=$(date +%s)
	echo "=== [$(date -u +%H:%M:%S) +$(( now - T_START ))s] $*"
	T_STEP=$now
}

step_done() {
	local now
	now=$(date +%s)
	echo "=== $1 took $(( now - T_STEP ))s"
}

die() {
	echo "run-live.sh: ERROR: $*" >&2
	exit 1
}

# Reads the versions.txt row of version $1 from file $2 and validates every column.
# A line starting with '#' and a trailing '# ...' on a row are comments; any other extra column is an error.
# Sets globals: ROW_VERSION ROW_CHANNEL ROW_SHA ROW_SSH_PATH ROW_INSTALL ROW_SHA_SOURCE
read_version_row() {
	local target="$1"
	local file="$2"
	local line
	local -a words
	[ -n "$target" ] || die "no version given (CHR_VERSION is empty)"
	[ -f "$file" ] || die "versions file '$file' not found"
	line=$(awk -v v="$target" '$1 == v { print; exit }' "$file")
	[ -n "$line" ] || die "version '$target' is not listed in $file"

	line="${line%%#*}"
	read -r -a words <<<"$line"
	if [ "${#words[@]}" -ne 6 ]; then
		die "row for version '$target' in $file must have 6 columns, ${#words[@]} found"
	fi

	ROW_VERSION="${words[0]}"
	ROW_CHANNEL="${words[1]}"
	ROW_SHA="${words[2]}"
	ROW_SSH_PATH="${words[3]}"
	ROW_INSTALL="${words[4]}"
	ROW_SHA_SOURCE="${words[5]}"

	if [ "$ROW_SSH_PATH" != "json" ] && [ "$ROW_SSH_PATH" != "text" ]; then
		die "unknown ssh-path '$ROW_SSH_PATH' for version '$target'"
	fi
	if [ "$ROW_INSTALL" != "image" ] && [[ ! "$ROW_INSTALL" =~ ^upgrade:[^:]+$ ]]; then
		die "unknown install '$ROW_INSTALL' for version '$target'"
	fi
	if [ "$ROW_SHA_SOURCE" != "official" ] && [ "$ROW_SHA_SOURCE" != "computed" ]; then
		die "unknown sha-source '$ROW_SHA_SOURCE' for version '$target'"
	fi
}

# Reads and validates the row of version $1; for install=upgrade:<base> also the base image row
# (it must exist and have install=image) and sha-source=official (the NPK is the officially published one).
# Sets globals: ROW_* (see read_version_row) and ROW_BASE (base image version, empty for install=image)
parse_version_row() {
	local target="$1"
	local file="${2:-$VERSIONS_FILE}"
	local base_install
	read_version_row "$target" "$file"
	ROW_BASE=""
	if [[ "$ROW_INSTALL" == upgrade:* ]]; then
		[ "$ROW_SHA_SOURCE" = "official" ] \
			|| die "version '$target' has install=$ROW_INSTALL, which needs sha-source=official (found '$ROW_SHA_SOURCE')"
		ROW_BASE="${ROW_INSTALL#upgrade:}"
		base_install=$(read_version_row "$ROW_BASE" "$file" && echo "$ROW_INSTALL") \
			|| die "base image row '$ROW_BASE' of version '$target' is missing or invalid"
		[ "$base_install" = "image" ] \
			|| die "base row '$ROW_BASE' of version '$target' must have install=image, found '$base_install'"
	fi
}

plan_step() {
	PLAN_LABELS+=("$1")
	PLAN_COMMANDS+=("$2")
}

# Plans the live run of version $1: groups, timeout, install mode and every step after the dependencies.
# Commands are kept unexpanded ("$DIR", "$CHR_CACHE", "$CHR_PASSWORD", "$@" expand when execute_plan runs them).
# Sets globals: PLAN_LABELS PLAN_COMMANDS PLAN_BOOT_VERSION (the image chr.sh boots) and ROW_* (parse_version_row)
plan_live_run() {
	local target="$1"
	local file="${2:-$VERSIONS_FILE}"
	parse_version_row "$target" "$file"

	local exclude_group default_timeout live_timeout
	if [ "$ROW_SSH_PATH" = "json" ]; then
		exclude_group="live-text"
		default_timeout=300
	else
		exclude_group="live-json"
		default_timeout=600
	fi
	live_timeout="${CHR_LIVE_TIMEOUT:-$default_timeout}"
	[[ "$live_timeout" =~ ^[1-9][0-9]*$ ]] || die "CHR_LIVE_TIMEOUT must be a number of seconds, got '$live_timeout'"

	PLAN_LABELS=()
	PLAN_COMMANDS=()
	plan_step "env" "export MIKROTIK_LIVE_SSH_PATH=$ROW_SSH_PATH"
	plan_step "env" "export CHR_LIVE_TIMEOUT=$live_timeout"
	plan_step "env" "export MIKROTIK_LIVE_HOST=127.0.0.1"
	plan_step "env" "export MIKROTIK_LIVE_PORT_API=18728"
	plan_step "env" "export MIKROTIK_LIVE_PORT_TLS=18729"
	plan_step "env" "export MIKROTIK_LIVE_PORT_SSH=18722"
	plan_step "env" "export MIKROTIK_LIVE_USER=admin"
	plan_step "env" 'export MIKROTIK_LIVE_PASS="$CHR_PASSWORD"'
	plan_step "install" 'bash "$DIR/chr.sh" install'

	if [ -z "$ROW_BASE" ]; then
		PLAN_BOOT_VERSION="$target"
		plan_step "start (download/verify/unzip/boot CHR $target)" "CHR_VERSION=$target bash \"\$DIR/chr.sh\" start"
		plan_step "wait for /login" "CHR_VERSION=$target bash \"\$DIR/chr.sh\" wait"
		plan_step "provision" 'php "$DIR/provision.php"'
	else
		local npk="routeros-x86-$target.npk"
		PLAN_BOOT_VERSION="$ROW_BASE"
		plan_step "verify NPK $npk" "verify_npk $target \"\$CHR_CACHE\" \"\$VERSIONS_FILE\""
		plan_step "start base image $ROW_BASE" "CHR_VERSION=$ROW_BASE bash \"\$DIR/chr.sh\" start"
		plan_step "wait for /login (base $ROW_BASE)" "CHR_VERSION=$ROW_BASE bash \"\$DIR/chr.sh\" wait"
		# --defer-reboot: the ipv6 package is enabled on the base image and the upgrade reboot applies it (one reboot)
		plan_step "provision (base $ROW_BASE)" 'php "$DIR/provision.php" --defer-reboot'
		plan_step "wait for SSH banner (base $ROW_BASE)" 'bash "$DIR/chr.sh" wait-ssh'
		plan_step "upgrade $ROW_BASE to $target by NPK $npk" "php \"\$DIR/upgrade.php\" $target \"\$CHR_CACHE/$npk\""
		plan_step "provision (upgraded $target)" 'php "$DIR/provision.php"'
	fi

	plan_step "wait for SSH banner" 'bash "$DIR/chr.sh" wait-ssh'
	# Gate before the tests: when smoke fails, one more (idempotent) provision.php and one more smoke, then fail.
	plan_step "smoke (on failure: provision once, smoke once more)" \
		'php "$DIR/smoke.php" || { echo "=== smoke failed: running provision.php once more"; php "$DIR/provision.php" && php "$DIR/smoke.php"; }'
	plan_step "smoke ssh-login" 'php "$DIR/smoke.php" --ssh-login'
	plan_step "live tests" "timeout $live_timeout vendor/bin/phpunit --group live --exclude-group $exclude_group --fail-on-empty-test-suite \"\$@\""
}

# Prints the planned steps, one "<label> | <command>" line each.
print_plan() {
	local i
	for i in "${!PLAN_COMMANDS[@]}"; do
		echo "${PLAN_LABELS[$i]} | ${PLAN_COMMANDS[$i]}"
	done
}

# Runs the planned steps in order; the arguments are passed on to phpunit ("$@" in the live tests step).
execute_plan() {
	local i
	for i in "${!PLAN_COMMANDS[@]}"; do
		if [ "${PLAN_LABELS[$i]}" = "env" ]; then
			eval "${PLAN_COMMANDS[$i]}"
			continue
		fi
		step "${PLAN_LABELS[$i]}"
		eval "${PLAN_COMMANDS[$i]}"
		step_done "${PLAN_LABELS[$i]}"
	done
}

# Verifies or downloads the NPK for an upgrade install against the row SHA256 (taken from the official .sha256).
verify_npk() {
	local target="$1"
	local cache_dir="${2:-$CHR_CACHE}"
	local file="${3:-$VERSIONS_FILE}"
	parse_version_row "$target" "$file"

	mkdir -p "$cache_dir"
	local npk_file="$cache_dir/routeros-x86-$target.npk"
	local sha="$ROW_SHA"
	local actual

	if [ -f "$npk_file" ]; then
		actual=$(sha256sum "$npk_file" | awk '{print $1}')
		if [ "$actual" != "$sha" ]; then
			die "SHA256 mismatch for cached $npk_file: expected $sha, got $actual (delete the file to download it again)"
		fi
	else
		local download_url="https://download.mikrotik.com/routeros/$target"
		echo "run-live.sh: downloading $download_url/routeros-x86-$target.npk"
		rm -f "$npk_file.part"
		bash "$DIR/download.sh" "$download_url/routeros-x86-$target.npk" "$npk_file.part"
		actual=$(sha256sum "$npk_file.part" | awk '{print $1}')
		if [ "$actual" != "$sha" ]; then
			rm -f "$npk_file.part"
			die "SHA256 mismatch for downloaded routeros-x86-$target.npk: expected $sha, got $actual"
		fi
		mv "$npk_file.part" "$npk_file"
	fi
	echo "run-live.sh: NPK SHA256 OK ($sha)"
}

main() {
	# no default: a job whose variable got lost must not test another version under its own name
	[ -n "${CHR_VERSION:-}" ] || die "CHR_VERSION is not set: name the RouterOS version to test (a row of versions.txt)"
	export CHR_VERSION
	export CHR_CACHE="${CHR_CACHE:-$HOME/.cache/lossik-chr}"
	if ! awk -v v="$CHR_VERSION" '$1 == v { found=1 } END { exit !found }' "$VERSIONS_FILE"; then
		[[ "$CHR_VERSION" =~ ^7\.[0-9]+(\.[0-9]+)?((rc|beta)[0-9]+)?$ ]] || die "invalid unpinned version"
		[[ "${CHR_OFFICIAL_SHA256:-}" =~ ^[a-f0-9]{64}$ ]] || die "unpinned version requires CHR_OFFICIAL_SHA256"
		VERSIONS_FILE=$(mktemp)
		local ssh_path
		ssh_path=$(php -r 'echo version_compare($argv[1], "7.17", ">=") ? "json" : "text";' "$CHR_VERSION")
		printf '%s stable %s %s image official\n' "$CHR_VERSION" "$CHR_OFFICIAL_SHA256" "$ssh_path" >"$VERSIONS_FILE"
		export CHR_TEMP_VERSIONS="$VERSIONS_FILE"
	fi
	plan_live_run "$CHR_VERSION" "$VERSIONS_FILE"

	CHR_STARTED=0
	BOOTED_VERSION=""

	on_exit() {
		local rc=$?
		trap - EXIT
		if [ "$rc" -ne 0 ]; then
			echo "=== FAILED (exit $rc)"
			bash "$DIR/chr.sh" serial-tail || true
		fi
		if [ "$CHR_STARTED" = "1" ]; then
			step "stop"
			CHR_VERSION="$BOOTED_VERSION" bash "$DIR/chr.sh" stop || true
		fi
		[ -z "${CHR_TEMP_VERSIONS:-}" ] || rm -f "$CHR_TEMP_VERSIONS"
		echo "=== total $(( $(date +%s) - T_START ))s, exit $rc"
		exit "$rc"
	}
	trap on_exit EXIT

	step "PHP version check"
	php -r '
	$minima = ["8.2" => "8.2.35", "8.3" => "8.3.36", "8.4" => "8.4.27", "8.5" => "8.5.12"];
	$branch = PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;
	if (!isset($minima[$branch])) {
		fwrite(STDERR, "ERROR: PHP " . PHP_VERSION . " is not supported (supported: 8.2 to 8.5)\n");
		exit(1);
	}
	if (version_compare(PHP_VERSION, $minima[$branch], "<")) {
		fwrite(STDERR, "ERROR: PHP " . PHP_VERSION . " is below the minimum patched release " . $minima[$branch] . "\n");
		exit(1);
	}
	echo "PHP " . PHP_VERSION . " OK\n";
	'

	step "dependencies (tools/ci.sh --deps-only)"
	bash "$DIR/../ci.sh" --deps-only
	step_done "dependencies"

	if [ -z "${CHR_PASSWORD:-}" ]; then
		CHR_PASSWORD=$(php -r 'echo bin2hex(random_bytes(12));')
	fi
	export CHR_PASSWORD

	echo "=== plan for CHR $CHR_VERSION:"
	print_plan
	BOOTED_VERSION="$PLAN_BOOT_VERSION"
	CHR_STARTED=1
	execute_plan "$@"
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
	main "$@"
fi
