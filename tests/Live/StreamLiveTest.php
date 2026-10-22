<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests\Live;

use Lossik\DeviceCommunication\Client;
use Lossik\DeviceCommunication\ConnectionException;
use Lossik\DeviceCommunication\FatalException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * `listen` and `/cancel` against a real RouterOS (plan L8; U9, T20), and the router ending a session (`/quit`).
 */
#[Group('live')]
#[Medium]
final class StreamLiveTest extends LiveTestCase
{
    /**
     * A background job of the router (`/execute`) that changes the comment of the `lo` interface, adds a bridge and
     * removes it again, with a pause before each step so that `listen` is already running.
     */
    private const CHANGES = ':delay 2s; /interface set [find name=lo] comment=lossik-live-event; :delay 1s; '
        . '/interface bridge add name=lossik-live-bridge comment=lossik-live-test; :delay 1s; '
        . '/interface bridge remove [find name=lossik-live-bridge]';

    /**
     * L8: the rows of `listen` are whole items, a removed item comes as `.id` + `.dead=true` (U9), and cancelling
     * from the callback leaves the connection usable.
     */
    public function testListenDeliversChangesAndCancelKeepsTheConnectionUsable(): void
    {
        $raw = $this->raw();
        $loop = $raw->rows('/interface/print', ['?name=lo', '.proplist=.id,comment'])[0] ?? [];
        $client = new Client($this->transport());
        $rows = [];
        $bridgeId = null;

        try {
            $raw->call('/execute', ['=script=' . self::CHANGES]);
            $client->menu('/interface')->listen(
                static function (array $row) use (&$rows, &$bridgeId): bool {
                    $rows[] = $row;
                    $bridgeId = ($row['name'] ?? null) === 'lossik-live-bridge' ? $row['.id'] : $bridgeId;

                    return !(isset($row['.dead']) && $row['.id'] === $bridgeId);
                },
                idleTimeout: 15.0,
            );
            $version = $client->version();
        } finally {
            $raw->rows('/interface/set', ['=.id=' . ($loop['.id'] ?? 'lo'), '=comment=' . ($loop['comment'] ?? '')]);
            $raw->close();
        }

        self::assertNotNull($bridgeId);
        self::assertContains(['.id' => $bridgeId, '.dead' => 'true'], $rows);
        $commentChanged = array_filter($rows, static fn (array $row): bool => ($row['name'] ?? null) === 'lo');
        self::assertSame(['lossik-live-event'], array_column($commentChanged, 'comment'));
        $last = array_pop($rows);
        self::assertSame($bridgeId, $last['.id'] ?? null);
        self::assertNotNull($version);
    }

    /**
     * Outside the plan: the router ends a session with `!fatal` (`/quit`) - the only way to get FatalException from a
     * real router; the reboot of L6 ends `listen` with `!done` instead.
     */
    public function testQuitEndsTheSessionWithFatalAndClosesTheTransport(): void
    {
        $transport = $this->transport();
        $client = new Client($transport);

        try {
            $client->menu('/')->run('quit');
            self::fail('The router did not end the session');
        } catch (FatalException $e) {
            self::assertNotSame('', $e->reason);
        }

        self::assertFalse($transport->isOpen());
        $this->expectException(ConnectionException::class);
        $client->version();
    }
}
