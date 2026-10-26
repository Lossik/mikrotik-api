<?php

declare(strict_types=1);

/**
 * Idempotent provisioning of a fresh CHR over the raw API socket (127.0.0.1:18728).
 *
 * - admin: empty password -> $CHR_PASSWORD (when the empty password is rejected, logs in with $CHR_PASSWORD)
 * - user "nopass" (group read, empty password), created only when missing
 * - services api, api-ssl and ssh enabled
 * - certificate "test" (common-name chr), signed, used by api-ssl
 *
 * Any !trap the logic does not expect ends the script with a non-zero exit code.
 */

require __DIR__ . '/routeros.php';

$password = chr_env('CHR_PASSWORD');

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
        must($api, '/ip/service/set', ['=.id=' . $apiSsl['.id'], '=certificate=test']);
        step('api-ssl certificate set to test');
    } else {
        step('api-ssl already uses certificate test');
    }
    $api->close();

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
