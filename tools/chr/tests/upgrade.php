<?php

declare(strict_types=1);

/**
 * OH7 without a router: the decisions of upgrade.php.
 * - packages after the upgrade: every enabled package must have exactly the target version;
 * - a !trap reply to /system/reboot is a failure, a closed connection is the expected reboot;
 * - the wait for the target version reports the last error when its time runs out.
 */

require __DIR__ . '/../upgrade.php';

$failures = 0;
$fail = static function (string $message) use (&$failures): void {
    fwrite(STDERR, "OH7 FAIL: $message\n");
    $failures++;
};

$package = static fn (string $name, string $version, string $disabled = 'false'): array
    => ['name' => $name, 'version' => $version, 'disabled' => $disabled];

// name => [packages, expected: null = upgraded, otherwise a text the reason contains]
$packageCases = [
    'all enabled packages at the target' => [
        [$package('routeros-x86', '6.49.23'), $package('system', '6.49.23'), $package('ipv6', '6.49.23', 'true')],
        null,
    ],
    'an enabled package left at the base version' => [
        [$package('routeros-x86', '6.49.23'), $package('ppp', '6.49.20')],
        'ppp 6.49.20',
    ],
    'an enabled package whose version only starts with the target' => [
        [$package('routeros-x86', '6.49.23'), $package('dhcp', '6.49.230')],
        'dhcp 6.49.230',
    ],
    'a disabled package left at the base version' => [
        [$package('routeros-x86', '6.49.23'), $package('ipv6', '6.49.20', 'true')],
        null,
    ],
    'no package reported' => [
        [],
        'no enabled package',
    ],
];
foreach ($packageCases as $name => [$packages, $expected]) {
    $error = upgrade_package_error($packages, '6.49.23');
    if ($expected === null ? $error !== null : !str_contains((string) $error, $expected)) {
        $fail("packages [$name]: got " . var_export($error, true) . ', expected ' . var_export($expected, true));
    }
}

/**
 * @param list<list<string>> $replies sentences the fake router writes; null = it closes the connection
 */
function rebootAgainst(?array $replies): ?string
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    $api = ChrApi::connect('127.0.0.1', $port, 5.0);
    $conn = stream_socket_accept($server, 5.0);
    if ($replies === null) {
        fclose($conn);
    } else {
        foreach ($replies as $words) {
            $data = '';
            foreach ($words as $word) {
                $data .= chr(strlen($word)) . $word;
            }
            fwrite($conn, $data . "\0");
        }
    }
    $trap = upgrade_reboot($api);
    $api->close();
    if ($replies !== null) {
        fclose($conn);
    }
    fclose($server);

    return $trap;
}

$trap = rebootAgainst([['!trap', '=message=not enough permissions (9)'], ['!done']]);
if ($trap !== 'not enough permissions (9)') {
    $fail('reboot answered with !trap must be reported, got ' . var_export($trap, true));
}
$trap = rebootAgainst(null);
if ($trap !== null) {
    $fail('reboot that closes the connection must count as accepted, got ' . var_export($trap, true));
}

// A port nobody listens on: every attempt fails to connect, the error must name the last failure.
$server = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
fclose($server);
try {
    upgrade_wait('127.0.0.1', $port, 'x', '6.49.23', 1.0, 0.2);
    $fail('waiting on a closed port must fail');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), "last error: connect to 127.0.0.1:$port failed")) {
        $fail('the timeout error must carry the last error, got: ' . $e->getMessage());
    }
}

if ($failures > 0) {
    exit(1);
}
echo "OH7 OK\n";
