#!/bin/bash
# Full live run against a MikroTik CHR booted in this container:
# PHP version check -> dependencies (tools/ci.sh --deps-only) -> install QEMU -> start CHR -> wait for /login
# -> provision -> wait for the SSH banner -> smoke -> phpunit --group live -> stop (always).
#
#   bash tools/chr/run-live.sh   (from the repository root)
#
# Environment: CHR_VERSION, CHR_CACHE, CHR_BOOT_TIMEOUT (see chr.sh); CHR_PASSWORD (admin password to set,
# default: random per run).
set -euo pipefail

DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
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

on_exit() {
	local rc=$?
	trap - EXIT
	if [ "$rc" -ne 0 ]; then
		echo "=== FAILED (exit $rc)"
		bash "$DIR/chr.sh" serial-tail || true
	fi
	if [ "$CHR_STARTED" = "1" ]; then
		step "stop"
		bash "$DIR/chr.sh" stop || true
	fi
	echo "=== total $(( $(date +%s) - T_START ))s, exit $rc"
	exit "$rc"
}

CHR_STARTED=0
trap on_exit EXIT

# 1) PHP version: only patched releases are supported (8.2.35+, 8.3.36+, 8.4.27+, 8.5.12+).
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

export CHR_VERSION="${CHR_VERSION:-7.24.5}"
if [ -z "${CHR_PASSWORD:-}" ]; then
	CHR_PASSWORD=$(php -r 'echo bin2hex(random_bytes(12));')
fi
export CHR_PASSWORD

# Connection settings for the live test suite (phpunit --group live, F5).
export MIKROTIK_LIVE_HOST=127.0.0.1
export MIKROTIK_LIVE_PORT_API=18728
export MIKROTIK_LIVE_PORT_TLS=18729
export MIKROTIK_LIVE_PORT_SSH=18722
export MIKROTIK_LIVE_USER=admin
export MIKROTIK_LIVE_PASS="$CHR_PASSWORD"

step "install"
bash "$DIR/chr.sh" install
step_done "install"

step "start (download/verify/unzip/boot CHR $CHR_VERSION)"
CHR_STARTED=1
bash "$DIR/chr.sh" start
step_done "start"

step "wait for /login"
bash "$DIR/chr.sh" wait
step_done "boot until login"

step "provision"
php "$DIR/provision.php"
step_done "provisioning"

# After provisioning: provision.php may (re)enable the ssh service, and a fresh CHR sends no SSH banner for a while
# after the API is up.
step "wait for SSH banner"
bash "$DIR/chr.sh" wait-ssh
step_done "SSH wait"

step "smoke"
php "$DIR/smoke.php"
step_done "smoke"

# The live suite gets a hard limit: a silent router can hang a client forever.
step "phpunit --group live"
timeout 300 vendor/bin/phpunit --group live
step_done "live tests"
