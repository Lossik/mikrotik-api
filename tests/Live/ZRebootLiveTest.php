<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests\Live;

use Lossik\DeviceCommunication\Client;
use Lossik\DeviceCommunication\ConnectionException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Large;

/**
 * The router closes the connection (plan L6, N1/N11 on a real router). It reboots the router, so it must run after
 * every other live test: the name sorts last, and LiveTestCase fails any test that runs after the reboot, so a changed
 * order is red instead of silently testing a dead router.
 *
 * Large, not Medium: after `listen` the test waits up to 10 s for a fresh login to fail, the proof that the router
 * really went away (a router that never reboots ends `listen` by the idle timeout too).
 */
#[Group('live')]
#[Large]
final class ZRebootLiveTest extends LiveTestCase
{
    /** The background job waits this long before the reboot, so that `listen` is already running. */
    private const REBOOT_DELAY = '2s';

    /** Within this time after `listen` a fresh login must fail at least once. */
    private const GONE_WITHIN = 10.0;

    /** Deadline of each login attempt (TCP connect and each login sentence). */
    private const LOGIN_TIMEOUT = 2.0;

    /**
     * What a client sees when the router goes away under a running `listen`, within the time the plan allows
     * (idleTimeout) and never as a hang. It depends on the RouterOS version, and the test accepts every outcome the
     * library can handle: CHR 7.24.5 closes the connection, usually after it finished the running commands with
     * `!done` (then `listen` returns normally, and the next call gets the ConnectionException), sometimes without
     * the `!done` (`listen` fails with ConnectionException); a router that goes silent ends `listen` with
     * TimeoutException, a ConnectionException. The plan expected an exception from `listen` in every case.
     *
     * None of those outcomes proves a reboot (a router that stays up ends `listen` by the idle timeout), so the test
     * also proves it independently of the library: the router accepts the job, and afterwards a fresh connection
     * cannot log in. A login, not a bare TCP connect: QEMU user networking accepts the connect to the forwarded port
     * while the guest is down.
     */
    public function testRouterRebootEndsListenWithinTheIdleTimeoutAndTheTransportIsClosed(): void
    {
        $idleTimeout = 4.0;
        $transport = $this->transport();
        $client = new Client($transport);
        $raw = $this->raw();
        // the reboot is requested by a background job of the router (`/execute` answers at once with the job ID, a
        // refusal with `!trap`), so that this connection is already listening, and nothing is left on the router to
        // clean. Not a script item that removes itself before the reboot: on CHR 7.23.8 such a script never rebooted.
        // The console path with a space: RouterOS 6.x refuses `/system/reboot` in a script ("expected command name").
        self::routerWillReboot();
        $raw->rows('/execute', ['=script=:delay ' . self::REBOOT_DELAY . '; /system reboot']);

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
        $versionTook = microtime(true) - $started;

        self::assertLessThan($idleTimeout + 2.0, $listenTook);
        self::assertLessThan(2.0, $versionTook);
        self::assertFalse($transport->isOpen());
        self::assertTrue(
            $this->loginFailsWithin(self::GONE_WITHIN),
            sprintf('A fresh login kept succeeding for %.0f s: the router did not reboot', self::GONE_WITHIN),
        );
    }

    /**
     * Logs in by fresh connections until one fails or $seconds pass; a router that stays up never refuses one.
     */
    private function loginFailsWithin(float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            try {
                RawApi::connect($this->env, self::LOGIN_TIMEOUT)->close();
            } catch (\RuntimeException) {
                return true;
            }
            usleep(500_000);
        } while (microtime(true) < $deadline);

        return false;
    }
}
