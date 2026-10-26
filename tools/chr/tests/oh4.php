<?php

declare(strict_types=1);

/**
 * OH4 without a router: smoke.php --ssh-banner holds ONE patient connection up to the banner.
 * A fake SSH port sends the banner 8 s after the connection and keeps accepting (and counting) connections
 * until the client exits.
 * - CHR_BOOT_TIMEOUT=30: exit 0, the banner printed, exactly 1 connection;
 * - CHR_BOOT_TIMEOUT=4: exit non-zero (the limit comes from CHR_BOOT_TIMEOUT), at most 1 connection.
 */

const BANNER_DELAY = 8.0;

/**
 * @return array{status: int, connections: int, stdout: string, stderr: string}
 */
function runProbe(string $bootTimeout): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        fwrite(STDERR, "OH4 FAIL: cannot create server: $errstr\n");
        exit(1);
    }
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    $env = array_merge(getenv(), ['CHR_SSH_PORT' => (string) $port, 'CHR_BOOT_TIMEOUT' => $bootTimeout]);
    $client = proc_open(
        [PHP_BINARY, __DIR__ . '/../smoke.php', '--ssh-banner'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $env,
    );

    $connections = 0;
    $open = [];
    $limit = microtime(true) + 60.0;
    // proc_get_status() reports the exit code only once, on the first call after the exit; proc_close() then gives -1.
    $status = -1;
    while (microtime(true) < $limit) {
        $state = proc_get_status($client);
        if (!$state['running']) {
            $status = $state['exitcode'];
            break;
        }
        $conn = @stream_socket_accept($server, 0.2);
        if ($conn !== false) {
            $connections++;
            $open[] = [$conn, microtime(true) + BANNER_DELAY];
        }
        foreach ($open as $i => [$c, $bannerAt]) {
            if ($bannerAt !== null && microtime(true) >= $bannerAt) {
                @fwrite($c, "SSH-2.0-RouterOS_Fake\r\n");
                $open[$i][1] = null;
            }
        }
    }
    foreach ($open as [$c]) {
        fclose($c);
    }
    fclose($server);
    if ($status === -1) {
        proc_terminate($client); // still running after 60 s: a hanging probe
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    proc_close($client);

    return ['status' => $status, 'connections' => $connections, 'stdout' => $stdout, 'stderr' => $stderr];
}

$failures = 0;

$run = runProbe('30');
if ($run['status'] !== 0) {
    fwrite(STDERR, "OH4 FAIL: CHR_BOOT_TIMEOUT=30: client exited with status {$run['status']}, stderr: {$run['stderr']}\n");
    $failures++;
}
if ($run['connections'] !== 1) {
    fwrite(STDERR, "OH4 FAIL: CHR_BOOT_TIMEOUT=30: expected exactly 1 connection, got {$run['connections']}\n");
    $failures++;
}
if (!str_contains($run['stdout'], 'SSH-2.0-RouterOS_Fake')) {
    fwrite(STDERR, "OH4 FAIL: CHR_BOOT_TIMEOUT=30: banner missing from output: {$run['stdout']}\n");
    $failures++;
}

$run = runProbe('4');
if ($run['status'] === 0) {
    fwrite(STDERR, "OH4 FAIL: CHR_BOOT_TIMEOUT=4 with the banner at 8 s must fail, got exit 0: {$run['stdout']}\n");
    $failures++;
}
if ($run['connections'] > 1) {
    fwrite(STDERR, "OH4 FAIL: CHR_BOOT_TIMEOUT=4: expected at most 1 connection, got {$run['connections']}\n");
    $failures++;
}

if ($failures > 0) {
    exit(1);
}
echo "OH4 OK\n";
