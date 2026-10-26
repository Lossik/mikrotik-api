#!/bin/bash
# CI of the 2.x branch, run inside a php:X-cli container from the repository root:
#
#   bash tools/ci.sh              all steps
#   bash tools/ci.sh --deps-only  steps 1-6 only (PHP check, tools, Composer, dependencies) - used by tools/chr/run-live.sh
#   bash tools/ci.sh --prefer-lowest  installs the lowest allowed version of every dependency (checks the lower
#                                     bounds in composer.json); combines with --deps-only
#
# Steps: (1) PHP version check (only patched releases), (2) git, unzip and a pinned, SHA256-verified Composer,
# (4) composer validate --strict, (5) composer update (no lock file is committed), (6) composer check-platform-reqs,
# (7) composer audit, (10) PHPUnit (default configuration; groups live and docker are excluded there).
set -euo pipefail

COMPOSER_VERSION=2.10.2
DEPS_ONLY=0
UPDATE_FLAGS=""
for arg in "$@"; do
	case "$arg" in
		--deps-only) DEPS_ONLY=1 ;;
		--prefer-lowest) UPDATE_FLAGS="--prefer-lowest --prefer-stable" ;;
		*) echo "ERROR: unknown argument: $arg" >&2; exit 2 ;;
	esac
done

step() {
	echo "=== ci: $*"
}

on_exit() {
	local rc=$?
	if [ "$rc" -ne 0 ]; then
		echo "=== ci: FAILED (exit $rc)" >&2
	fi
}
trap on_exit EXIT

step "(1) PHP version check"
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

step "(2) system packages: git, unzip"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq --no-install-recommends git unzip > /dev/null

step "(2) Composer $COMPOSER_VERSION (SHA256 verified)"
COMPOSER_DIR=$(mktemp -d)
php -r '
$version = $argv[1];
$target = $argv[2];
$base = "https://getcomposer.org/download/" . $version . "/";
$phar = file_get_contents($base . "composer.phar");
$sums = file_get_contents($base . "composer.phar.sha256sum");
if ($phar === false || $sums === false) {
    fwrite(STDERR, "ERROR: download of Composer " . $version . " failed\n");
    exit(1);
}
$expected = strtok(trim($sums), " ");
$actual = hash("sha256", $phar);
if (!preg_match("/^[0-9a-f]{64}$/", (string) $expected) || !hash_equals($expected, $actual)) {
    fwrite(STDERR, "ERROR: Composer " . $version . " SHA256 mismatch (expected " . $expected . ", got " . $actual . ")\n");
    exit(1);
}
file_put_contents($target, $phar);
echo "composer.phar " . $version . " sha256 " . $actual . " OK\n";
' "$COMPOSER_VERSION" "$COMPOSER_DIR/composer.phar"
composer() {
	php "$COMPOSER_DIR/composer.phar" --no-interaction "$@"
}
export COMPOSER_ALLOW_SUPERUSER=1
composer --version

step "(4) composer validate --strict"
composer validate --strict

step "(5) composer update"
composer update --no-progress --prefer-dist $UPDATE_FLAGS

step "(6) composer check-platform-reqs"
composer check-platform-reqs

if [ "$DEPS_ONLY" = "1" ]; then
	step "dependencies ready (--deps-only)"
	exit 0
fi

step "(7) composer audit"
composer audit

step "(10) phpunit"
vendor/bin/phpunit

step "OK"
