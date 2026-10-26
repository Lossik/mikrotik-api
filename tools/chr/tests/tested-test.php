<?php

declare(strict_types=1);

require __DIR__ . '/../tested.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $call, string $message): void
{
    try {
        $call();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
}

// Official NEWESTa7 snapshots captured 2026-10-27; beta and malformed responses are synthetic variants.
check(newestVersion(file_get_contents(__DIR__ . '/fixtures/newest/stable')) === '7.25', 'Captured stable response');
check(newestVersion(file_get_contents(__DIR__ . '/fixtures/newest/testing')) === '7.25', 'Captured testing response');
check(newestVersion(file_get_contents(__DIR__ . '/fixtures/newest/long-term')) === '7.23.8', 'Captured long-term response');
foreach (['7.25 1792678320\n' => '7.25', '7.23.8 1791452776' => '7.23.8',
    '7.26beta2 1792678320' => '7.26beta2'] as $response => $expected) {
    check(newestVersion(str_replace('\\n', "\n", $response)) === $expected, 'NEWEST version parsing');
}
foreach (['', '<html>error</html>', '7.25', '7.25 epoch', '7.25 123 surplus', '../7.25 123', "7.25 123\n7.26 456"] as $response) {
    rejects(fn() => newestVersion($response), 'Malformed NEWEST response accepted');
}

$sha = str_repeat('a', 64);
$record = ['version' => '7.25', 'channels' => ['stable', 'testing'], 'sha256' => $sha,
    'sha_source' => 'official', 'date' => '2026-10-27T00:00:00Z', 'result' => 'passed',
    'run' => 'https://github.com/owner/repo/actions/runs/123'];
$responses = ['stable' => '7.25 123', 'testing' => '7.25 123', 'long-term' => '7.23.8 456'];
check(discoverVersions("7.23.8 long-term $sha json image official\n", [], $responses)
    === [['version' => '7.25', 'channels' => ['stable', 'testing']]], 'New version must run once across channels');
foreach (['passed', 'failed', 'unavailable'] as $result) {
    check(discoverVersions("7.23.8 long-term $sha json image official\n", [array_replace($record, ['result' => $result])], $responses)
        === [], 'Recorded version must not be tested again');
}

$before = "# retained header\n7.17.2 stable $sha json image official # JSON boundary\n"
    . "7.24.5 stable $sha json image official\n7.23.8 long-term $sha json image official\n"
    . "7.25rc3 testing $sha json image official\n";
$expected = "# retained header\n7.17.2 stable $sha json image official # JSON boundary\n"
    . "7.23.8 long-term $sha json image official\n7.25  stable  $sha  json  image  official\n"
    . "7.25  testing  $sha  json  image  official\n";
$after = importTested($before, [$record]);
check($after === $expected, 'Import replaces only unannotated channel heads and preserves boundaries');
check(importTested($after, [$record]) === $after, 'Import must be idempotent');
check(importTested($after, [array_replace($record, ['version' => '7.24.7'])]) === $after, 'Import must not downgrade heads');
foreach (['failed', 'unavailable'] as $result) {
    check(importTested($before, [array_replace($record, ['result' => $result])]) === $before, 'Non-green results must not import');
}

// Promotion changes channel membership, not the tested artifact or its verification result.
$promoted = ['stable' => '7.25', 'testing' => '7.25', 'long-term' => '7.25'];
$testingRecord = array_replace($record, ['channels' => ['testing']]);
check(discoverVersions($before, [$testingRecord], ['stable' => '7.25 123', 'testing' => '7.25 123', 'long-term' => '7.25 123']) === [],
    'Channel promotion must not rerun a known version');
$promotedExpected = "# retained header\n7.17.2 stable $sha json image official # JSON boundary\n"
    . "7.25  testing  $sha  json  image  official\n7.25  stable  $sha  json  image  official\n"
    . "7.25  long-term  $sha  json  image  official\n";
check(importTested($before, [$testingRecord], $promoted) === $promotedExpected, 'Promoted green version must update all heads');
check(importTested($promotedExpected, [$testingRecord], $promoted) === $promotedExpected, 'Promotion import must be idempotent');
$pinExpected = "# retained header\n7.17.2 stable $sha json image official # JSON boundary\n"
    . "7.24.5 stable $sha json image official\n7.25rc3 testing $sha json image official\n"
    . "7.24.5  long-term  $sha  json  image  official\n";
check(importTested($before, [], ['long-term' => '7.24.5']) === $pinExpected,
    'An existing pin promoted to another channel must reuse its verified artifact');
check(importTested($before, [array_replace($record, ['result' => 'failed'])], $promoted) === $before,
    'Channel metadata alone must not verify a new version');

$file = tempnam(sys_get_temp_dir(), 'tested-');
try {
    foreach ([['sha_source' => 'computed'], ['sha256' => 'bad'], ['result' => 'unknown'],
        ['channels' => ['unknown']], ['run' => 'not a run']] as $change) {
        file_put_contents($file, json_encode(array_replace($record, $change)) . "\n");
        rejects(fn() => testedRecords($file), 'Unverified record accepted');
    }
    file_put_contents($file, json_encode($record) . "\n");
    check(testedRecords($file) === [$record], 'Verified record must round-trip');
} finally {
    unlink($file);
}
echo "tested: parsing, selection, import and record validation OK\n";
