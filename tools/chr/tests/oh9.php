<?php

declare(strict_types=1);

/**
 * OH9 without a router: smoke.php compares the version the router reports with CHR_VERSION and, when CHR_CHANNEL
 * is set (run-live.sh sets it from the channel column of versions.txt), with the channel as well.
 * A fake API server answers /login with !done and /system/resource/print with the given version.
 */

/**
 * @param list<string> $words
 */
function encodeSentence(array $words): string
{
    $data = '';
    foreach ($words as $word) {
        $data .= chr(strlen($word)) . $word; // the fake only sends words shorter than 0x80 bytes
    }

    return $data . "\0";
}

/**
 * @return array{status: int, stderr: string}
 */
function runSmoke(string $reportedVersion, string $chrVersion, ?string $chrChannel): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        fwrite(STDERR, "OH9 FAIL: cannot create the fake router: $errstr\n");
        exit(1);
    }
    $port = substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    $env = array_merge(getenv(), ['CHR_API_PORT' => $port, 'CHR_PASSWORD' => 'pw', 'CHR_VERSION' => $chrVersion]);
    unset($env['CHR_CHANNEL']);
    if ($chrChannel !== null) {
        $env['CHR_CHANNEL'] = $chrChannel;
    }
    $client = proc_open([PHP_BINARY, __DIR__ . '/../smoke.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $conn = stream_socket_accept($server, 10.0);
    if ($conn !== false) {
        fwrite($conn, encodeSentence(['!done']));
        fwrite($conn, encodeSentence(['!re', "=version=$reportedVersion", '=uptime=1s', '=cpu=x86', '=board-name=CHR']));
        fwrite($conn, encodeSentence(['!done']));
    }
    $stderr = stream_get_contents($pipes[2]);
    stream_get_contents($pipes[1]);
    $status = proc_close($client);
    if ($conn !== false) {
        fclose($conn);
    }
    fclose($server);

    return ['status' => $status, 'stderr' => $stderr];
}

// name => [version the router reports, CHR_VERSION, CHR_CHANNEL (null = unset), whether smoke must pass]
$cases = [
    'no channel given: the channel is not compared' => ['7.24.5 (stable)', '7.24.5', null, true],
    'channel matches' => ['7.24.5 (stable)', '7.24.5', 'stable', true],
    'long-term channel matches' => ['6.49.18 (long-term)', '6.49.18', 'long-term', true],
    'router reports another channel than the row' => ['7.24.5 (testing)', '7.24.5', 'stable', false],
    'router reports no channel' => ['7.24.5', '7.24.5', 'stable', false],
    'version only shares the prefix' => ['7.24.50 (stable)', '7.24.5', null, false],
];

$failures = 0;
foreach ($cases as $name => [$reported, $chrVersion, $chrChannel, $mustPass]) {
    $run = runSmoke($reported, $chrVersion, $chrChannel);
    if (($run['status'] === 0) !== $mustPass) {
        fwrite(STDERR, "OH9 FAIL [$name]: smoke.php exited with {$run['status']}, expected " . ($mustPass ? 'success' : 'failure') . ", stderr: {$run['stderr']}\n");
        $failures++;
    }
}

if ($failures > 0) {
    exit(1);
}
echo "OH9 OK\n";
