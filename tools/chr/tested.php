<?php

declare(strict_types=1);

// Data protocol shared by discovery, the result writer and the release importer.
function validVersion(string $version): bool
{
    return preg_match('/^7\.[0-9]+(?:\.[0-9]+)?(?:(?:rc|beta)[0-9]+)?$/D', $version) === 1;
}

function newestVersion(string $response): string
{
    if (!preg_match('/^(\S+)\s+([0-9]+)\s*$/D', trim($response), $match) || !validVersion($match[1])) {
        throw new RuntimeException('Invalid NEWESTa7 response');
    }
    return $match[1];
}

function testedRecords(string $path): array
{
    $records = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('Cannot read tested records');
    }
    foreach ($lines as $line) {
        $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($record) || !validVersion($record['version'] ?? '')
            || !is_array($record['channels'] ?? null) || $record['channels'] === []
            || array_diff($record['channels'], ['stable', 'testing', 'long-term']) !== []
            || !in_array($record['result'] ?? '', ['passed', 'failed', 'unavailable'], true)
            || !preg_match('/^https:\/\/github\.com\/[^\/]+\/[^\/]+\/actions\/runs\/[0-9]+$/D', $record['run'] ?? '')
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $record['date'] ?? '')
        ) {
            throw new RuntimeException('Invalid tested record');
        }
        if ($record['result'] === 'passed' && (($record['sha_source'] ?? '') !== 'official'
            || !preg_match('/^[a-f0-9]{64}$/D', $record['sha256'] ?? ''))) {
            throw new RuntimeException('Passed record lacks an official SHA256');
        }
        $records[] = $record;
    }
    return $records;
}

function discoverVersions(string $versions, array $records, array $responses): array
{
    $known = [];
    foreach (preg_split('/\R/', $versions) as $line) {
        if (preg_match('/^\s*([^#\s]+)\s+/', $line, $m)) {
            $known[$m[1]] = true;
        }
    }
    foreach ($records as $record) {
        $known[$record['version']] = true;
    }
    $new = [];
    foreach ($responses as $channel => $response) {
        $version = newestVersion($response);
        if (!isset($known[$version])) {
            $new[$version]['version'] = $version;
            $new[$version]['channels'][] = $channel;
        }
    }
    return array_values($new);
}

function channelHeads(array $responses): array
{
    $heads = [];
    foreach (['stable', 'testing', 'long-term'] as $channel) {
        $heads[$channel] = newestVersion($responses[$channel]);
    }
    return $heads;
}

function readChannelHeads(string $file): array
{
    $heads = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($heads) || count($heads) !== 3) {
        throw new RuntimeException('Invalid channel heads');
    }
    foreach (['stable', 'testing', 'long-term'] as $channel) {
        if (!validVersion($heads[$channel] ?? '')) {
            throw new RuntimeException('Invalid channel head');
        }
    }
    return $heads;
}

function importTested(string $versions, array $records, array $channelHeads = []): string
{
    // Validate all input before changing anything. A failed/unavailable record is never an import candidate.
    $heads = [];
    foreach ($records as $record) {
        if ($record['result'] !== 'passed') {
            continue;
        }
        foreach ($record['channels'] as $channel) {
            if (!isset($heads[$channel]) || version_compare($record['version'], $heads[$channel]['version'], '>')) {
                $heads[$channel] = $record;
            }
        }
    }
    $lines = preg_split('/\R/', rtrim($versions, "\r\n"));
    // Channel membership changes independently of verification. Reuse only a green result or an existing pin.
    foreach ($channelHeads as $channel => $version) {
        $verified = null;
        foreach ($records as $record) {
            if ($record['version'] === $version && $record['result'] === 'passed') {
                $verified = $record;
            }
        }
        foreach ($lines as $line) {
            $cols = preg_split('/\s+/', trim(explode('#', $line, 2)[0]));
            if (count($cols) === 6 && $cols[0] === $version) {
                $verified = ['version' => $version, 'sha256' => $cols[2],
                    'ssh_path' => $cols[3], 'install' => $cols[4], 'sha_source' => $cols[5]];
            }
        }
        if ($verified !== null && (!isset($heads[$channel])
            || version_compare($version, $heads[$channel]['version'], '>'))) {
            $heads[$channel] = $verified;
        }
    }
    $changed = false;
    foreach ($heads as $channel => $record) {
        $version = $record['version'];
        $exists = false;
        $newest = null;
        foreach ($lines as $line) {
            $cols = preg_split('/\s+/', trim(explode('#', $line, 2)[0]));
            if (count($cols) < 6 || $cols[1] !== $channel) {
                continue;
            }
            $exists = $exists || $cols[0] === $version;
            if ($newest === null || version_compare($cols[0], $newest, '>')) {
                $newest = $cols[0];
            }
        }
        if ($exists || ($newest !== null && version_compare($version, $newest, '<='))) {
            continue;
        }
        // Commented boundaries and upgrade/base rows are retained byte for byte.
        $lines = array_values(array_filter($lines, static function (string $line) use ($channel): bool {
            $cols = preg_split('/\s+/', trim($line));
            return str_contains($line, '#') || count($cols) < 6 || $cols[1] !== $channel;
        }));
        $path = $record['ssh_path'] ?? (version_compare($version, '7.17', '>=') ? 'json' : 'text');
        $install = $record['install'] ?? 'image';
        $source = $record['sha_source'];
        $lines[] = "$version  $channel  {$record['sha256']}  $path  $install  $source";
        $changed = true;
    }
    return $changed ? implode("\n", $lines) . "\n" : $versions;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        switch ($argv[1] ?? '') {
            case 'record':
                $record = [
                    'version' => getenv('CHR_VERSION'),
                    'channels' => json_decode(getenv('CHR_CHANNELS'), true, 512, JSON_THROW_ON_ERROR),
                    'sha256' => getenv('CHR_OFFICIAL_SHA256') ?: null,
                    'sha_source' => 'official',
                    'date' => gmdate('Y-m-d\TH:i:s\Z'),
                    'result' => $argv[2],
                    'run' => getenv('CHR_RUN_URL'),
                ];
                $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
                if (file_put_contents($argv[3], $json) !== strlen($json)) {
                    throw new RuntimeException('Cannot write result record');
                }
                break;
            case 'matrix':
                $versions = [];
                $lines = file($argv[2] ?? __DIR__ . '/versions.txt');
                if ($lines === false) {
                    throw new RuntimeException('Cannot read matrix versions');
                }
                foreach ($lines as $line) {
                    if (preg_match('/^\s*([^#\s]+)\s+/', $line, $m)) {
                        $versions[] = $m[1];
                    }
                }
                if ($versions === []) {
                    throw new RuntimeException('Empty RouterOS matrix');
                }
                echo json_encode(['version' => array_values(array_unique($versions))], JSON_THROW_ON_ERROR) . "\n";
                break;
            case 'discover':
                $responses = [];
                foreach (['stable', 'testing', 'long-term'] as $channel) {
                    $responses[$channel] = file_get_contents($argv[4] . '/' . $channel);
                }
                echo json_encode(['include' => discoverVersions(file_get_contents($argv[2]), testedRecords($argv[3]), $responses),
                    'heads' => channelHeads($responses)], JSON_THROW_ON_ERROR) . "\n";
                break;
            default:
                throw new RuntimeException('Usage: php tested.php matrix [versions.txt] | discover versions.txt tested.jsonl responses-directory');
        }
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
