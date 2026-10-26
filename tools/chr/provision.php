<?php

declare(strict_types=1);

/**
 * Idempotent provisioning of a fresh CHR over the raw API socket (127.0.0.1:18728).
 *
 * - admin: empty password -> $CHR_PASSWORD (when the empty password is rejected, logs in with $CHR_PASSWORD)
 * - user "nopass" (group read, empty password), created only when missing
 * - services api, api-ssl and ssh enabled
 * - certificate "test" (common-name chr), signed, used by api-ssl
 * - package ipv6 enabled when the router has it installed and disabled (CHR 6.x images ship it so): enable, reboot,
 *   wait up to $CHR_REBOOT_TIMEOUT seconds (default 600) for the API login and the package enabled.
 *   With --defer-reboot the enable is only scheduled: the base image of an upgrade: row is rebooted by upgrade.php.
 *
 * Any !trap the logic does not expect ends the script with a non-zero exit code.
 *
 * The functions are also used by the harness tests (tests/oh8.php); the script part runs only when this file is
 * executed directly.
 */

require __DIR__ . '/routeros.php';

function step(string $message): void
{
    echo "provision: $message\n";
}

function fail(string $message): never
{
    fwrite(STDERR, "provision: ERROR: $message\n");
    exit(1);
}

/**
 * @param list<string> $words
 * @return array{done: array<string, string>, re: list<array<string, string>>, trap: ?string}
 */
function must(ChrApi $api, string $command, array $words = [], float $timeout = 30.0): array
{
    $reply = $api->call($command, $words, $timeout);
    if ($reply['trap'] !== null) {
        fail("$command " . implode(' ', $words) . ": {$reply['trap']}");
    }

    return $reply;
}

/**
 * @return ?array<string, string>
 */
function findByName(ChrApi $api, string $menu, string $name): ?array
{
    foreach (must($api, "$menu/print")['re'] as $item) {
        if (($item['name'] ?? null) === $name && ($item['dynamic'] ?? 'false') !== 'true') {
            return $item;
        }
    }

    return null;
}

function connect(): ChrApi
{
    return ChrApi::connect(CHR_HOST, CHR_API_PORT, 10.0);
}

/**
 * The /system/package/print row of the package ipv6; null = not installed (RouterOS 7.x has no separate package).
 *
 * @return ?array<string, string>
 * @throws RuntimeException the router refused the print
 */
function ipv6_package(ChrApi $api): ?array
{
    $reply = $api->call('/system/package/print');
    if ($reply['trap'] !== null) {
        throw new RuntimeException("/system/package/print: {$reply['trap']}");
    }
    foreach ($reply['re'] as $package) {
        if (($package['name'] ?? null) === 'ipv6') {
            return $package;
        }
    }

    return null;
}

/**
 * Enables the package ipv6 when the router has it installed and disabled; the condition is the router's package
 * state, never the version. Without $deferReboot it reboots the router and waits until admin logs in with $password
 * and the package reports disabled=false (the router may still answer for a moment after /system/reboot, so a login
 * alone does not prove the reboot). With $deferReboot the enable stays scheduled for the next reboot.
 *
 * @return string what happened, for the run log
 * @throws RuntimeException a refused command, or the package not enabled within $rebootTimeout seconds
 */
function provision_ipv6(
    string $host,
    int $port,
    string $password,
    bool $deferReboot,
    float $rebootTimeout,
    float $pause = 3.0,
): string {
    $api = ChrApi::connect($host, $port, 10.0);
    try {
        $trap = $api->login('admin', $password);
        if ($trap !== null) {
            throw new RuntimeException("login as admin: $trap");
        }
        $package = ipv6_package($api);
        if ($package === null) {
            return 'package ipv6 not installed: nothing to enable';
        }
        $state = sprintf(
            'package ipv6 %s disabled=%s',
            $package['version'] ?? '?',
            $package['disabled'] ?? '?',
        );
        if (($package['disabled'] ?? 'false') !== 'true') {
            return "$state: nothing to enable";
        }
        $reply = $api->call('/system/package/enable', ['=numbers=' . ($package['.id'] ?? '')]);
        if ($reply['trap'] !== null) {
            throw new RuntimeException("/system/package/enable ipv6: {$reply['trap']}");
        }
        if ($deferReboot) {
            return "$state: enabled, takes effect at the next reboot (--defer-reboot)";
        }
        $started = microtime(true);
        try {
            $trap = $api->call('/system/reboot', [], 5.0)['trap'];
        } catch (RuntimeException) {
            $trap = null; // RouterOS usually closes the connection while rebooting
        }
        if ($trap !== null) {
            throw new RuntimeException("/system/reboot: $trap");
        }
    } finally {
        $api->close();
    }

    $deadline = $started + $rebootTimeout;
    $lastError = 'no attempt';
    while (true) {
        usleep((int) ($pause * 1e6));
        try {
            $api = ChrApi::connect($host, $port, 3.0);
            try {
                $trap = $api->login('admin', $password, 10.0);
                if ($trap !== null) {
                    $lastError = "login refused: $trap";
                } else {
                    $package = ipv6_package($api);
                    if ($package !== null && ($package['disabled'] ?? 'false') !== 'true') {
                        return sprintf('%s: enabled, rebooted in %.1fs', $state, microtime(true) - $started);
                    }
                    $lastError = 'package ipv6 still disabled=' . ($package['disabled'] ?? '(not installed)');
                }
            } finally {
                $api->close();
            }
        } catch (RuntimeException $e) {
            $lastError = $e->getMessage();
        }
        if (microtime(true) + $pause >= $deadline) {
            throw new RuntimeException(
                "package ipv6 not enabled within {$rebootTimeout}s of /system/reboot; last error: $lastError",
            );
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$password = chr_env('CHR_PASSWORD');
$deferReboot = in_array('--defer-reboot', array_slice($argv, 1), true);

try {
    // admin password
    $api = connect();
    $trap = $api->login('admin', '');
    if ($trap === null) {
        $admin = findByName($api, '/user', 'admin') ?? fail('user admin not found');
        must($api, '/user/set', ['=.id=' . $admin['.id'], "=password=$password"]);
        $api->close();
        step('admin password set (was empty)');
    } else {
        $api->close();
        step("admin password already set (empty password rejected: $trap)");
    }

    $api = connect();
    $trap = $api->login('admin', $password);
    if ($trap !== null) {
        fail("login as admin with CHR_PASSWORD failed: $trap");
    }

    // user nopass
    if (findByName($api, '/user', 'nopass') === null) {
        must($api, '/user/add', ['=name=nopass', '=group=read', '=password=']);
        step('user nopass created (group read, empty password)');
    } else {
        step('user nopass already exists');
    }

    // services
    foreach (['api', 'api-ssl', 'ssh'] as $name) {
        $service = findByName($api, '/ip/service', $name) ?? fail("service $name not found");
        if (($service['disabled'] ?? 'false') === 'true') {
            must($api, '/ip/service/set', ['=.id=' . $service['.id'], '=disabled=no']);
            step("service $name enabled");
        } else {
            step("service $name already enabled (port " . ($service['port'] ?? '?') . ')');
        }
    }

    // certificate
    $cert = findByName($api, '/certificate', 'test');
    if ($cert === null) {
        must($api, '/certificate/add', ['=name=test', '=common-name=chr']);
        $cert = findByName($api, '/certificate', 'test') ?? fail('certificate test not found after add');
        step('certificate test added');
    }
    if (($cert['private-key'] ?? 'false') !== 'true') {
        $t0 = microtime(true);
        must($api, '/certificate/sign', ['=.id=' . $cert['.id']], 600.0);
        step(sprintf('certificate test signed in %.1fs', microtime(true) - $t0));
        $cert = findByName($api, '/certificate', 'test') ?? fail('certificate test not found after sign');
        if (($cert['private-key'] ?? 'false') !== 'true') {
            fail('certificate test has no private key after sign');
        }
    } else {
        step('certificate test already signed');
    }

    $apiSsl = findByName($api, '/ip/service', 'api-ssl') ?? fail('service api-ssl not found');
    if (($apiSsl['certificate'] ?? '') !== 'test') {
        $deadline = microtime(true) + 15.0;
        $lastTrap = null;
        while (microtime(true) < $deadline) {
            $reply = $api->call('/ip/service/set', ['=.id=' . $apiSsl['.id'], '=certificate=test']);
            if ($reply['trap'] === null) {
                $lastTrap = null;
                break;
            }
            $lastTrap = $reply['trap'];
            usleep(500000);
        }
        if ($lastTrap !== null) {
            fail("/ip/service/set certificate=test: $lastTrap");
        }
        step('api-ssl certificate set to test');
    } else {
        step('api-ssl already uses certificate test');
    }
    $api->close();

    // package ipv6 (plan-stare-ros §8.7 B1: disabled on every CHR 6.x image; the live tests use /ipv6 menus)
    step(provision_ipv6(
        CHR_HOST,
        CHR_API_PORT,
        $password,
        $deferReboot,
        (float) chr_env('CHR_REBOOT_TIMEOUT', '600'),
    ));

    // Record whether RouterOS accepts the empty password of nopass.
    $api = connect();
    $trap = $api->login('nopass', '');
    $api->close();
    step($trap === null
        ? 'login as nopass with empty password: ACCEPTED'
        : "login as nopass with empty password: REJECTED ($trap)");
} catch (RuntimeException $e) {
    fail($e->getMessage());
}

step('done');
