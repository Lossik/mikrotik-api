<?php

declare(strict_types=1);

/**
 * OH8 without a router: provision_ipv6() of provision.php against a fake router in a child process
 * (`php oh8.php --serve <ipv6 state>`), which prints every command it receives.
 * The fake applies a scheduled package enable only at a reboot, and the reboot itself takes effect only after one more
 * connection with the old state (a router still answering for a moment after /system/reboot).
 * - package disabled: enable, reboot, wait until the package reports enabled;
 * - package disabled, --defer-reboot: enable, no reboot (the upgrade reboots);
 * - package enabled, or not installed (7.x): no enable, no reboot.
 */

require __DIR__ . '/../provision.php';

const PASSWORD = 'pw';

/**
 * @return ?list<string> the words of the next sentence; null = the client closed the connection
 */
function fakeRead($conn): ?array
{
    $words = [];
    while (true) {
        $byte = fread($conn, 1);
        if ($byte === false || $byte === '') {
            return null;
        }
        $length = ord($byte);
        if (($length & 0x80) !== 0) {
            $length = (($length & 0x3F) << 8) | ord((string) fread($conn, 1));
        }
        if ($length === 0) {
            return $words;
        }
        $word = '';
        while (strlen($word) < $length) {
            $chunk = fread($conn, $length - strlen($word));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $word .= $chunk;
        }
        $words[] = $word;
    }
}

/**
 * @param list<list<string>> $sentences
 */
function fakeWrite($conn, array $sentences): void
{
    $data = '';
    foreach ($sentences as $words) {
        foreach ($words as $word) {
            $data .= chr(strlen($word)) . $word; // the fake only sends words shorter than 0x80 bytes
        }
        $data .= "\0";
    }
    fwrite($conn, $data);
}

/**
 * The fake router: serves one connection at a time until it is killed.
 */
function serve(string $ipv6): never
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        fwrite(STDERR, "fake router: $errstr\n");
        exit(1);
    }
    echo substr(strrchr(stream_socket_get_name($server, false), ':'), 1), "\n";
    $scheduled = false;
    $rebootAfter = null; // connections still served with the old state after /system/reboot
    while (($conn = stream_socket_accept($server, -1)) !== false) {
        if ($rebootAfter === 0) {
            $ipv6 = $scheduled ? 'enabled' : $ipv6;
            $scheduled = false;
            $rebootAfter = null;
            echo "(rebooted)\n";
        } elseif ($rebootAfter !== null) {
            $rebootAfter--;
        }
        while (($words = fakeRead($conn)) !== null) {
            $command = array_shift($words);
            echo $command, $command === '/system/package/enable' ? ' ' . implode(' ', $words) : '', "\n";
            $reply = match ($command) {
                '/login' => in_array('=password=' . PASSWORD, $words, true)
                    ? [['!done']]
                    : [['!trap', '=message=invalid user name or password (6)'], ['!done']],
                '/system/package/print' => [
                    ['!re', '=.id=*1', '=name=routeros-x86', '=version=6.49.18', '=disabled=false'],
                    ...($ipv6 === 'absent' ? [] : [[
                        '!re', '=.id=*3', '=name=ipv6', '=version=6.49.18',
                        '=disabled=' . ($ipv6 === 'disabled' ? 'true' : 'false'),
                    ]]),
                    ['!done'],
                ],
                '/system/package/enable' => $ipv6 !== 'absent' && in_array('=numbers=*3', $words, true)
                    ? [['!done']]
                    : [['!trap', '=message=no such item'], ['!done']],
                '/system/reboot' => null,
                default => [['!trap', '=message=no such command'], ['!done']],
            };
            if ($command === '/system/package/enable' && $reply === [['!done']]) {
                $scheduled = true;
            }
            if ($reply === null) {
                $rebootAfter = 1;
                break;
            }
            fakeWrite($conn, $reply);
        }
        fclose($conn);
    }
    exit(0);
}

if (($argv[1] ?? '') === '--serve') {
    serve($argv[2]);
}

/**
 * @return array{result: string, commands: list<string>}
 */
function runCase(string $ipv6, bool $deferReboot): array
{
    $router = proc_open([PHP_BINARY, __FILE__, '--serve', $ipv6], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $port = (int) fgets($pipes[1]);
    try {
        $result = provision_ipv6('127.0.0.1', $port, PASSWORD, $deferReboot, 3.0, 0.1);
    } catch (RuntimeException $e) {
        $result = 'exception: ' . $e->getMessage();
    }
    proc_terminate($router);
    $commands = array_values(array_filter(explode("\n", (string) stream_get_contents($pipes[1]))));
    proc_close($router);

    return ['result' => $result, 'commands' => $commands];
}

// name => [ipv6 state, --defer-reboot, text the result contains, commands that change the router in their order;
// '(rebooted)' = the client logged in again after the reboot took effect]
$cases = [
    'disabled: enable, reboot, wait for it' => [
        'disabled', false, 'enabled, rebooted in',
        ['/system/package/enable =numbers=*3', '/system/reboot', '(rebooted)'],
    ],
    'disabled, --defer-reboot: enable only' => [
        'disabled', true, 'takes effect at the next reboot', ['/system/package/enable =numbers=*3'],
    ],
    'enabled: nothing' => ['enabled', false, 'disabled=false: nothing to enable', []],
    'not installed: nothing' => ['absent', false, 'not installed', []],
];

$failures = 0;
foreach ($cases as $name => [$ipv6, $deferReboot, $expected, $changes]) {
    $run = runCase($ipv6, $deferReboot);
    $actual = array_values(array_filter(
        $run['commands'],
        static fn (string $c): bool => str_starts_with($c, '/system/package/enable')
            || in_array($c, ['/system/reboot', '(rebooted)'], true),
    ));
    if (!str_contains($run['result'], $expected)) {
        fwrite(STDERR, "OH8 FAIL [$name]: got '{$run['result']}', expected it to contain '$expected'\n");
        $failures++;
    }
    if ($actual !== $changes) {
        fwrite(STDERR, "OH8 FAIL [$name]: commands " . json_encode($actual) . ', expected ' . json_encode($changes) . "\n");
        $failures++;
    }
}

if ($failures > 0) {
    exit(1);
}
echo "OH8 OK\n";
