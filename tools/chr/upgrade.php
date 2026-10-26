<?php

declare(strict_types=1);

/**
 * Uploads an NPK package to the running CHR over SFTP, triggers /system/reboot,
 * waits for the API to return, verifies the upgraded version and that every enabled package
 * has the target version, and reports the ipv6 package status.
 *
 * Usage: php tools/chr/upgrade.php <target-version> <npk-path>
 *
 * The functions below are also used by the harness tests (tests/upgrade.php); the script part runs
 * only when this file is executed directly.
 */

require __DIR__ . '/routeros.php';

/**
 * Checks the /system/package/print rows after an upgrade: every enabled package must have exactly the target
 * version (package versions carry no channel). Returns null when they do, otherwise the reason.
 *
 * @param list<array<string, string>> $packages
 */
function upgrade_package_error(array $packages, string $target): ?string
{
    $enabled = 0;
    $wrong = [];
    foreach ($packages as $package) {
        if (($package['disabled'] ?? 'false') === 'true') {
            continue;
        }
        $enabled++;
        $version = $package['version'] ?? '';
        if ($version !== $target) {
            $wrong[] = ($package['name'] ?? '?') . " $version";
        }
    }
    if ($enabled === 0) {
        return 'no enabled package';
    }

    return $wrong === [] ? null : "enabled packages not at $target: " . implode(', ', $wrong);
}

/**
 * Sends /system/reboot. Returns the !trap message when the router refuses, null when it accepted
 * (RouterOS usually closes the connection while rebooting).
 */
function upgrade_reboot(ChrApi $api): ?string
{
    try {
        return $api->call('/system/reboot', [], 5.0)['trap'];
    } catch (RuntimeException) {
        return null;
    }
}

/**
 * Polls the API until the router reports $target, then returns [version, packages].
 *
 * @return array{0: string, 1: list<array<string, string>>}
 * @throws RuntimeException with the last error when $timeout seconds pass
 */
function upgrade_wait(string $host, int $port, string $password, string $target, float $timeout, float $pause = 3.0): array
{
    $deadline = microtime(true) + $timeout;
    $lastError = 'no attempt';
    while (true) {
        try {
            $api = ChrApi::connect($host, $port, 3.0);
            try {
                $trap = $api->login('admin', $password, 10.0);
                if ($trap !== null) {
                    $lastError = "login refused: $trap";
                } else {
                    $version = $api->call('/system/resource/print', [], 10.0)['re'][0]['version'] ?? '';
                    if (str_starts_with($version, $target . ' ')) {
                        $packages = $api->call('/system/package/print', [], 10.0);
                        if ($packages['trap'] !== null) {
                            throw new RuntimeException("/system/package/print: {$packages['trap']}");
                        }

                        return [$version, $packages['re']];
                    }
                    $lastError = "router reports version '$version'";
                }
            } finally {
                $api->close();
            }
        } catch (RuntimeException $e) {
            $lastError = $e->getMessage();
        }
        if (microtime(true) + $pause >= $deadline) {
            throw new RuntimeException("target version $target was not observed within {$timeout}s; last error: $lastError");
        }
        usleep((int) ($pause * 1e6));
    }
}

/**
 * Uploads $npkPath with curl (SFTP) when phpseclib is not installed; the password goes to curl in a
 * config file readable only by this user, never on the command line.
 */
function upgrade_upload_with_curl(string $npkPath, string $npkName, string $password): void
{
    $config = tempnam(sys_get_temp_dir(), 'chr-curl-');
    if ($config === false) {
        throw new RuntimeException('cannot create the curl config file');
    }
    try {
        chmod($config, 0600);
        file_put_contents($config, 'user = "' . addcslashes("admin:$password", "\"\\") . "\"\n");
        $cmd = sprintf(
            'curl -fsSL -k -K %s -T %s %s',
            escapeshellarg($config),
            escapeshellarg($npkPath),
            escapeshellarg('sftp://' . CHR_HOST . ':' . CHR_SSH_PORT . '/' . $npkName),
        );
        exec($cmd, $output, $rc);
        if ($rc !== 0) {
            throw new RuntimeException("curl SFTP upload failed (exit $rc)");
        }
    } finally {
        unlink($config);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

function upgrade_fail(string $message): never
{
    fwrite(STDERR, "upgrade: ERROR: $message\n");
    exit(1);
}

$targetVersion = $argv[1] ?? chr_env('CHR_VERSION');
$npkPath = $argv[2] ?? null;

if ($npkPath === null || !file_exists($npkPath)) {
    upgrade_fail("NPK file '$npkPath' does not exist");
}

$password = chr_env('CHR_PASSWORD');
$npkSize = filesize($npkPath);
$npkName = basename($npkPath);

echo "upgrade: target=$targetVersion, package=$npkName ($npkSize bytes)\n";

try {
    // 1. Verify current router is accessible via API
    $api = ChrApi::connect(CHR_HOST, CHR_API_PORT, 10.0);
    $trap = $api->login('admin', $password);
    if ($trap !== null) {
        upgrade_fail("login before upgrade failed: $trap");
    }
    $sourceVersion = $api->call('/system/resource/print')['re'][0]['version'] ?? 'unknown';
    echo "upgrade: source version is $sourceVersion\n";
    $api->close();

    // 2. Upload NPK via SFTP
    echo "upgrade: uploading $npkName via SFTP...\n";
    if (class_exists(\phpseclib3\Net\SFTP::class)) {
        $sftp = new \phpseclib3\Net\SFTP(CHR_HOST, CHR_SSH_PORT, 120);
        if (!$sftp->login('admin', $password)) {
            upgrade_fail('SFTP login failed');
        }
        if (!$sftp->put($npkName, $npkPath, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
            upgrade_fail('SFTP put failed');
        }
        $sftp->disconnect();
    } else {
        upgrade_upload_with_curl($npkPath, $npkName, $password);
    }

    // Check the uploaded file via API
    $api = ChrApi::connect(CHR_HOST, CHR_API_PORT, 10.0);
    $trap = $api->login('admin', $password);
    if ($trap !== null) {
        upgrade_fail("login after SFTP failed: $trap");
    }
    $size = null;
    foreach ($api->call('/file/print')['re'] as $file) {
        if (($file['name'] ?? '') === $npkName) {
            $size = (int) ($file['size'] ?? -1);
            break;
        }
    }
    if ($size === null) {
        upgrade_fail("uploaded package $npkName not found in /file/print");
    }
    if ($size !== $npkSize) {
        upgrade_fail("uploaded file size mismatch: expected $npkSize, got $size");
    }
    echo "upgrade: package verified on router ($npkSize bytes)\n";

    // 3. Trigger reboot
    echo "upgrade: triggering /system/reboot...\n";
    $trap = upgrade_reboot($api);
    if ($trap !== null) {
        upgrade_fail("/system/reboot refused: $trap");
    }
    $api->close();

    // 4. Wait for the router to come back with the target version
    echo "upgrade: waiting for router to reboot into $targetVersion...\n";
    sleep(15);
    [$version, $packages] = upgrade_wait(
        CHR_HOST,
        CHR_API_PORT,
        $password,
        $targetVersion,
        (float) chr_env('CHR_BOOT_TIMEOUT', '300'),
    );
} catch (RuntimeException $e) {
    upgrade_fail($e->getMessage());
}

echo "upgrade: router version is $version\n";
echo "upgrade: installed packages:\n";
$ipv6Disabled = null;
foreach ($packages as $package) {
    $name = $package['name'] ?? '?';
    $disabled = $package['disabled'] ?? 'false';
    echo "  - $name " . ($package['version'] ?? '?') . " (disabled=$disabled)\n";
    if ($name === 'ipv6') {
        $ipv6Disabled = ($disabled === 'true');
    }
}
// Report only: provision.php, run after the upgrade, enables (and reboots) when the upgrade left ipv6 disabled.
echo 'upgrade: NOTE: package ipv6 is ' . match ($ipv6Disabled) {
    true => 'disabled',
    false => 'enabled',
    null => 'not present',
} . "\n";

$error = upgrade_package_error($packages, $targetVersion);
if ($error !== null) {
    upgrade_fail($error);
}

echo "upgrade: done\n";
