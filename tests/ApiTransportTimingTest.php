<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests;

use Lossik\DeviceCommunication\Command;
use Lossik\DeviceCommunication\ConnectionException;
use Lossik\DeviceCommunication\FatalException;
use Lossik\DeviceCommunication\TimeoutException;
use Lossik\Mikrotik\Api\ApiOptions;
use Lossik\Mikrotik\Api\ApiTransport;
use Lossik\Mikrotik\Api\Tests\Support\FakeRouter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * ApiTransport scenarios that wait, or that a broken transport would hang in: under the time limit of medium tests
 * (plan §7.1, F3a). TLS rows of T7/T8 are in TlsTest.
 */
#[Medium]
final class ApiTransportTimingTest extends TestCase
{
    /**
     * T7
     *
     * @return iterable<string, array{key-of<FakeRouter::SCENARIOS>}>
     */
    public static function silences(): iterable
    {
        yield 'silence before the reply' => ['silence'];
        yield 'silence after the first byte of a two-byte length' => ['silence-in-length'];
    }

    /**
     * @param key-of<FakeRouter::SCENARIOS> $scenario
     */
    #[DataProvider('silences')]
    public function testSilenceEndsWithIdleTimeoutAndClosesTheTransport(string $scenario): void
    {
        $router = FakeRouter::start($scenario);
        $transport = self::connect($router, new ApiOptions(idleTimeout: 1.0));

        $started = hrtime(true);
        try {
            $transport->execute(new Command('/ip/address', 'print'));
            self::fail('TimeoutException expected');
        } catch (TimeoutException $e) {
            self::assertSame('idleTimeout', $e->limit);
        }
        $elapsed = self::secondsSince($started);
        self::assertGreaterThanOrEqual(1.0, $elapsed);
        self::assertLessThan(3.0, $elapsed);
        self::assertFalse($transport->isOpen());

        self::assertSecondCallFailsWithoutIo($transport, $router);
    }

    /**
     * T8
     *
     * @return iterable<string, array{key-of<FakeRouter::SCENARIOS>}>
     */
    public static function closedConnections(): iterable
    {
        yield 'closed before the first byte' => ['eof'];
        yield 'closed inside the content of a word' => ['eof-mid'];
        yield 'closed after the first byte of a two-byte length' => ['eof-in-length'];
    }

    /**
     * @param key-of<FakeRouter::SCENARIOS> $scenario
     */
    #[DataProvider('closedConnections')]
    public function testClosedConnectionIsAConnectionErrorAtOnce(string $scenario): void
    {
        $router = FakeRouter::start($scenario);
        $transport = self::connect($router);

        $started = hrtime(true);
        try {
            $transport->execute(new Command('/ip/address', 'print'));
            self::fail('ConnectionException expected');
        } catch (ConnectionException $e) {
            self::assertNotInstanceOf(TimeoutException::class, $e);
        }
        self::assertLessThan(1.0, self::secondsSince($started));
        self::assertFalse($transport->isOpen());
    }

    /**
     * T9: pauses of 1.3 s, longer than the 1 s read tick and shorter than idleTimeout 2 s.
     *
     * @return iterable<string, array{key-of<FakeRouter::SCENARIOS>, list<array<string, string>>}>
     */
    public static function slowReplies(): iterable
    {
        yield 'pauses between sentences, 3.9 s in total' => [
            'slow-gaps',
            [['.id' => '*1', 'name' => 'a'], ['.id' => '*2', 'name' => 'b']],
        ];
        yield 'two pauses inside the content of one word' => ['slow-mid', [['.id' => '*1', 'name' => 'late-content']]];
        yield 'pause between the two bytes of a length' => ['slow-in-length', [['comment' => str_repeat('x', 191)]]];
    }

    /**
     * @param key-of<FakeRouter::SCENARIOS> $scenario
     * @param list<array<string, string>> $rows
     */
    #[DataProvider('slowReplies')]
    public function testSlowReplyWithGapsShorterThanIdleTimeoutIsReturned(string $scenario, array $rows): void
    {
        $router = FakeRouter::start($scenario);
        $transport = self::connect($router, new ApiOptions(idleTimeout: 2.0));

        self::assertSame($rows, $transport->execute(new Command('/ip/address', 'print'))->rows);
    }

    /** T10 */
    public function testResponseTimeoutEndsAReplyThatKeepsTrickling(): void
    {
        $router = FakeRouter::start('trickle');
        $transport = self::connect($router, new ApiOptions(responseTimeout: 1.5));

        $started = hrtime(true);
        try {
            $transport->execute(new Command('/ip/address', 'print'));
            self::fail('TimeoutException expected');
        } catch (TimeoutException $e) {
            self::assertSame('responseTimeout', $e->limit);
        }
        $elapsed = self::secondsSince($started);
        self::assertGreaterThanOrEqual(1.5, $elapsed);
        self::assertLessThan(3.0, $elapsed);
    }

    /** T11 */
    public function testFatalCarriesTheReasonAndClosesTheTransport(): void
    {
        $router = FakeRouter::start('fatal');
        $transport = self::connect($router);

        try {
            $transport->execute(new Command('/ip/address', 'print'));
            self::fail('FatalException expected');
        } catch (FatalException $e) {
            self::assertSame('session terminated on request', $e->reason);
        }
        self::assertFalse($transport->isOpen());

        self::assertSecondCallFailsWithoutIo($transport, $router);
    }

    /**
     * The second call fails at once and the router received nothing more than the login and the first command.
     */
    private static function assertSecondCallFailsWithoutIo(ApiTransport $transport, FakeRouter $router): void
    {
        $started = hrtime(true);
        try {
            $transport->execute(new Command('/ip/address', 'print'));
            self::fail('ConnectionException expected');
        } catch (ConnectionException) {
        }
        self::assertLessThan(0.1, self::secondsSince($started));
        self::assertCount(2, $router->received());
    }

    /**
     * Keep the router in a variable for the whole test: a destroyed FakeRouter stops its process.
     */
    private static function connect(FakeRouter $router, ApiOptions $options = new ApiOptions()): ApiTransport
    {
        return ApiTransport::connect('127.0.0.1', FakeRouter::USER, FakeRouter::PASSWORD, new ApiOptions(
            port: $router->port(),
            idleTimeout: $options->idleTimeout,
            responseTimeout: $options->responseTimeout,
        ));
    }

    private static function secondsSince(int $hrtime): float
    {
        return (hrtime(true) - $hrtime) / 1e9;
    }
}
