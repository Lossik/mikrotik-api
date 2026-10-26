#!/bin/bash
# CI of the 3.x line, run inside a php:X-cli container from the repository root. The same script is used by
# lossik/device-communication, lossik/mikrotik-api and lossik/mikrotik-ssh:
#
#   bash tools/ci.sh              all steps
#   bash tools/ci.sh --deps-only  steps 1-6 only (PHP check, tools, Composer, dependencies) - used by tools/chr/run-live.sh
#
# Steps: (1) PHP version check (only patched releases), (2) git, unzip, ext-pcntl (PHPUnit enforces time limits only
# with it) and a pinned, SHA256-verified Composer, (3) clone of lossik/device-communication, (4) composer validate
# --strict, (5) composer update (no lock file is committed), (6) composer check-platform-reqs, (7) composer audit,
# (8) phpcs, (9) phpstan, (10) PHPUnit (default configuration; groups live and docker are excluded there).
#
# Environment (all optional; unset in lossik/device-communication itself):
#   DC_REF, DC_URL  step (3) clones branch DC_REF of the repository DC_URL to /tmp/dc and sets DC_PATH=/tmp/dc
#   DC_PATH         step (5) installs lossik/device-communication from this directory (a Composer path repository
#                   written to composer.dev.json, which is gitignored together with its composer.dev.lock); the
#                   committed composer.json never carries a repository
set -euo pipefail

COMPOSER_VERSION=2.10.2
DEPS_ONLY=0
for arg in "$@"; do
	case "$arg" in
		--deps-only) DEPS_ONLY=1 ;;
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

step "(2) ext-pcntl"
pcntl_loaded() {
	php -r 'exit(extension_loaded("pcntl") ? 0 : 1);'
}
if ! pcntl_loaded; then
	docker-php-ext-install pcntl > /dev/null
fi
if ! pcntl_loaded; then
	echo "ERROR: ext-pcntl is not loaded; PHPUnit would not enforce the test time limits" >&2
	exit 1
fi
echo "ext-pcntl OK"

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

if [ -n "${DC_REF:-}" ]; then
	step "(3) clone lossik/device-communication, branch $DC_REF"
	if [ -z "${DC_URL:-}" ]; then
		echo "ERROR: DC_REF is set but DC_URL is not" >&2
		exit 1
	fi
	rm -rf /tmp/dc
	git clone --quiet --depth 1 --branch "$DC_REF" "$DC_URL" /tmp/dc
	DC_PATH=/tmp/dc
	echo "lossik/device-communication $(git -C /tmp/dc rev-parse --short HEAD)"
fi

step "(4) composer validate --strict"
composer validate --strict

if [ -n "${DC_PATH:-}" ]; then
	step "(5) composer update (lossik/device-communication from $DC_PATH)"
	php -r '
$config = json_decode((string) file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR);
$config["repositories"] = [[
    "type" => "path",
    "url" => $argv[1],
    "options" => ["symlink" => false, "versions" => ["lossik/device-communication" => "3.0.0"]],
]];
$json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
file_put_contents("composer.dev.json", $json . "\n");
' "$DC_PATH"
	# composer.dev.json and composer.dev.lock are used by steps 5-7
	export COMPOSER=composer.dev.json
else
	step "(5) composer update"
fi
composer update --no-progress --prefer-dist

step "(6) composer check-platform-reqs"
composer check-platform-reqs

if [ "$DEPS_ONLY" = "1" ]; then
	step "dependencies ready (--deps-only)"
	exit 0
fi

step "(7) composer audit"
composer audit

step "(8) phpcs"
vendor/bin/phpcs

step "(9) phpstan"
vendor/bin/phpstan analyse --no-progress --memory-limit=1G

step "(10) phpunit"
vendor/bin/phpunit

step "OK"
