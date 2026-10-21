<?php

declare(strict_types=1);

/**
 * Smoke test of a running CHR over the raw API socket.
 *
 *   php tools/chr/smoke.php               log in as admin/$CHR_PASSWORD, run /system/resource/print and check
 *                                         that =version= starts with $CHR_VERSION (default 7.24.5)
 *   php tools/chr/smoke.php --login-only  readiness probe for chr.sh wait: succeeds on any complete reply
 *                                         to /login (!done or !trap); a bare TCP connect does not count
 */

require __DIR__ . '/routeros.php';

$loginOnly = in_array('--login-only', array_slice($argv, 1), true);

if ($loginOnly) {
    try {
        $api = ChrApi::connect(CHR_HOST, CHR_API_PORT, 5.0);
        $trap = $api->login('admin', '', 15.0);
        $api->close();
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'not ready: ' . $e->getMessage() . "\n");
        exit(1);
    }
    echo $trap === null ? "login reply: !done\n" : "login reply: !trap ($trap)\n";
    exit(0);
}

$password = chr_env('CHR_PASSWORD');
$expected = chr_env('CHR_VERSION', '7.24.5');

try {
    $api = ChrApi::connect(CHR_HOST, CHR_API_PORT, 10.0);
    $trap = $api->login('admin', $password);
    if ($trap !== null) {
        fwrite(STDERR, "ERROR: login as admin failed: $trap\n");
        exit(1);
    }
    $reply = $api->call('/system/resource/print');
    $api->close();
} catch (RuntimeException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($reply['trap'] !== null || $reply['re'] === []) {
    fwrite(STDERR, 'ERROR: /system/resource/print returned no data' . ($reply['trap'] !== null ? ": {$reply['trap']}" : '') . "\n");
    exit(1);
}

$resource = $reply['re'][0];
$version = $resource['version'] ?? '';
// RouterOS appends the channel: "7.24.5 (stable)" matches CHR_VERSION=7.24.5, "7.24.50" does not.
if ($version !== $expected && !str_starts_with($version, $expected . ' ')) {
    fwrite(STDERR, "ERROR: router reports version '$version', expected $expected\n");
    exit(1);
}

printf(
    "smoke OK: version=%s, uptime=%s, cpu=%s, board=%s\n",
    $version,
    $resource['uptime'] ?? '?',
    $resource['cpu'] ?? '?',
    $resource['board-name'] ?? '?',
);
