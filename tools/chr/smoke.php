<?php

declare(strict_types=1);

/**
 * Smoke test of a running CHR over the raw API socket.
 *
 *   php tools/chr/smoke.php               log in as admin/$CHR_PASSWORD, run /system/resource/print and check
 *                                         that =version= starts with $CHR_VERSION (default 7.24.5)
 *   php tools/chr/smoke.php --login-only  readiness probe for chr.sh wait: succeeds on any complete reply
 *                                         to /login (!done or !trap); a bare TCP connect does not count
 *   php tools/chr/smoke.php --ssh-banner  readiness probe for chr.sh wait-ssh: succeeds when the SSH port sends
 *                                         a line starting with "SSH-"; a bare TCP connect does not count (the QEMU
 *                                         port forward accepts connections before the router's SSH service is up)
 */

require __DIR__ . '/routeros.php';

$loginOnly = in_array('--login-only', array_slice($argv, 1), true);

if (in_array('--ssh-banner', array_slice($argv, 1), true)) {
    $socket = @stream_socket_client('tcp://' . CHR_HOST . ':' . CHR_SSH_PORT, $errno, $errstr, 5.0);
    if ($socket === false) {
        fwrite(STDERR, "not ready: connect failed: $errstr ($errno)\n");
        exit(1);
    }
    stream_set_timeout($socket, 5);
    // RFC 4253 allows other lines before the identification string.
    for ($i = 0; $i < 10; $i++) {
        $line = fgets($socket, 512);
        if ($line === false) {
            $meta = stream_get_meta_data($socket);
            fclose($socket);
            fwrite(STDERR, 'not ready: ' . ($meta['timed_out'] ? 'no banner within 5s' : 'connection closed without a banner') . "\n");
            exit(1);
        }
        if (str_starts_with($line, 'SSH-')) {
            fclose($socket);
            echo 'ssh banner: ' . rtrim($line) . "\n";
            exit(0);
        }
    }
    fclose($socket);
    fwrite(STDERR, "not ready: no SSH- line in the first 10 lines\n");
    exit(1);
}

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
