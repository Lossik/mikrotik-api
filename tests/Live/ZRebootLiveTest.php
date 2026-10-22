<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests\Live;

use Lossik\DeviceCommunication\Client;
use Lossik\DeviceCommunication\ConnectionException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * The router closes the connection (plan L6, N1/N11 on a real router). It reboots the router, so it must run after
 * every other live test: the name sorts last, and LiveTestCase fails any test that runs after the reboot, so a changed
 * order is red instead of silently testing a dead router.
 */
#[Group('live')]
#[Medium]
final class ZRebootLiveTest extends LiveTestCase
{
    /**
     * What a client sees when the router goes away under a running `listen`, within the time the plan allows
     * (idleTimeout) and never as a hang. It depends on the RouterOS version, and the test accepts every outcome the
     * library can handle: CHR 7.24.5 closes the connection, usually after it finished the running commands with
     * `!done` (then `listen` returns normally, and the next call gets the ConnectionException), sometimes without
     * the `!done` (`listen` fails with ConnectionException); CHR 7.23.8 goes silent (`listen` fails with
     * TimeoutException, a ConnectionException). The plan expected an exception from `listen` in every case.
     */
    public function testRouterRebootEndsListenWithinTheIdleTimeoutAndTheTransportIsClosed(): void
    {
        $idleTimeout = 4.0;
        $transport = $this->transport();
        $client = new Client($transport);
        $raw = $this->raw();
        // the reboot is requested by a second connection a second later, so that this one is already listening; the
        // script removes itself first, because nothing can clean the router after the reboot
        $raw->rows('/system/script/add', [
            '=name=lossik-live-reboot',
            '=source=:delay 1s; /system/script remove [find where name=lossik-live-reboot]; /system/reboot',
            '=comment=' . self::TAG,
        ]);
        self::routerWillReboot();
        $raw->send('/system/script/run', ['=number=lossik-live-reboot']);

        $started = microtime(true);
        try {
            $client->menu('/interface')->listen(static fn (array $row): bool => true, $idleTimeout);
        } catch (ConnectionException) {
            // fine: the library noticed the router was gone
        }
        $listenTook = microtime(true) - $started;

        $started = microtime(true);
        try {
            $client->version();
            self::fail('A call on the connection of the rebooted router did not fail');
        } catch (ConnectionException) {
            // fine: EOF, or the transport was already closed
        }

        self::assertLessThan($idleTimeout + 2.0, $listenTook);
        self::assertLessThan(2.0, microtime(true) - $started);
        self::assertFalse($transport->isOpen());
    }
}
