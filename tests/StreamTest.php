<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests;

use Lossik\DeviceCommunication\Command;
use Lossik\DeviceCommunication\ConnectionException;
use Lossik\DeviceCommunication\FatalException;
use Lossik\DeviceCommunication\ProtocolException;
use Lossik\DeviceCommunication\TimeoutException;
use Lossik\DeviceCommunication\TrapException;
use Lossik\Mikrotik\Api\ApiOptions;
use Lossik\Mikrotik\Api\ApiTransport;
use Lossik\Mikrotik\Api\Tests\Support\FakeRouter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * ApiTransport::stream() and its cancel by /cancel (plan T20, F3e). The fake router's `listen` sends 3 rows 0.3 s
 * apart; the callbacks here cancel on the 2nd row. Medium: a broken cancel hangs until the time limit.
 */
#[Medium]
final class StreamTest extends TestCase
{
    private const OK_ROWS = [['.id' => '*1', 'name' => 'a'], ['.id' => '*2', 'name' => 'b']];

    private const EVENTS = [['.id' => '*1', 'name' => 'event1'], ['.id' => '*2', 'name' => 'event2']];

    /**
     * T20: the orders in which the device may finish the cancelled command S and /cancel C.
     *
     * @return iterable<string, array{list<'trap-s'|'done-s'|'trap-c'|'done-c'>, bool}>
     */
    public static function cancelReplies(): iterable
    {
        yield 'documented order trap(S), done(C), done(S)' => [FakeRouter::CANCEL_DOCUMENTED, false];
        yield 'done(S) before done(C)' => [['trap-s', 'done-s', 'done-c'], false];
        yield 'S done before /cancel is sent, /cancel trapped' => [['trap-c', 'done-c'], true];
    }

    /**
     * @param list<'trap-s'|'done-s'|'trap-c'|'done-c'> $cancelReply
     */
    #[DataProvider('cancelReplies')]
    public function testFalseFromTheCallbackCancelsAndTheConnectionStaysUsable(
        array $cancelReply,
        bool $doneBeforeCancel,
    ): void {
        $router = FakeRouter::start('listen', $cancelReply, $doneBeforeCancel);
        $transport = self::connect($router);

        $rows = [];
        $transport->stream(new Command('/interface', 'listen'), static function (array $row) use (&$rows): bool {
            $rows[] = $row;

            return count($rows) < 2;
        });
        $next = $transport->execute(new Command('/ip/address', 'print'))->rows;
        $transport->close();

        self::assertSame(self::EVENTS, $rows);
        self::assertSame(self::OK_ROWS, $next);
        self::assertCancelSent($router->received());
    }

    public function testStreamEndedByTheDeviceDeliversAllRowsAndTheConnectionStaysUsable(): void
    {
        $router = FakeRouter::start('ok');
        $transport = self::connect($router);

        $rows = [];
        $transport->stream(new Command('/ip/address', 'print'), static function (array $row) use (&$rows): bool {
            $rows[] = $row;

            return true;
        });

        self::assertSame(self::OK_ROWS, $rows);
        self::assertSame(self::OK_ROWS, $transport->execute(new Command('/ip/address', 'print'))->rows);
    }

    /** T20: rows reach the callback as they arrive, not after the stream ends. */
    public function testRowsAreDeliveredAsTheyArrive(): void
    {
        $router = FakeRouter::start('listen');
        $transport = self::connect($router);

        $times = [];
        $transport->stream(new Command('/interface', 'listen'), static function () use (&$times): bool {
            $times[] = hrtime(true);

            return count($times) < 2;
        });

        self::assertCount(2, $times);
        // the fake router sends the rows 0.3 s apart
        self::assertGreaterThanOrEqual(0.25, ($times[1] - $times[0]) / 1e9);
    }

    /** T20: the device never finishes /cancel. */
    public function testCancelNotFinishedWithinCancelTimeoutClosesTheTransport(): void
    {
        $router = FakeRouter::start('listen', ['trap-s', 'done-s']);
        $transport = self::connect($router, cancelTimeout: 1.0);

        $calls = 0;
        $cancelledAt = 0;
        try {
            $transport->stream(
                new Command('/interface', 'listen'),
                static function () use (&$calls, &$cancelledAt): bool {
                    $cancelledAt = hrtime(true);

                    return ++$calls < 2;
                },
            );
            self::fail('TimeoutException expected');
        } catch (TimeoutException $e) {
            self::assertSame('cancelTimeout', $e->limit);
        }
        $elapsed = (hrtime(true) - $cancelledAt) / 1e9;
        self::assertSame(2, $calls);
        self::assertGreaterThanOrEqual(1.0, $elapsed);
        self::assertLessThan(3.0, $elapsed);
        self::assertFalse($transport->isOpen());

        $started = hrtime(true);
        try {
            $transport->execute(new Command('/ip/address', 'print'));
            self::fail('ConnectionException expected');
        } catch (ConnectionException) {
        }
        self::assertLessThan(0.1, (hrtime(true) - $started) / 1e9);
        $received = $router->received();
        self::assertCount(3, $received);
        self::assertCancelSent($received);
    }

    /** T20 (outside the plan's table, from F3e): an exception of the callback cancels, then propagates. */
    public function testExceptionFromTheCallbackCancelsAndPropagates(): void
    {
        $router = FakeRouter::start('listen');
        $transport = self::connect($router);
        $thrown = new \LogicException('callback failed');

        $rows = [];
        try {
            $transport->stream(
                new Command('/interface', 'listen'),
                static function (array $row) use (&$rows, $thrown): bool {
                    $rows[] = $row;
                    if (count($rows) === 2) {
                        throw $thrown;
                    }

                    return true;
                },
            );
            self::fail('The exception of the callback expected');
        } catch (\LogicException $e) {
            self::assertSame($thrown, $e);
        }
        $next = $transport->execute(new Command('/ip/address', 'print'))->rows;
        $transport->close();

        self::assertSame(self::EVENTS, $rows);
        self::assertSame(self::OK_ROWS, $next);
        self::assertCancelSent($router->received());
    }

    public function testTrapOfTheStreamedCommandIsThrownAndTheConnectionStaysUsable(): void
    {
        $router = FakeRouter::start('trap-split');
        $transport = self::connect($router);

        try {
            $transport->stream(new Command('/interface', 'listen'), static fn (): bool => true);
            self::fail('TrapException expected');
        } catch (TrapException $e) {
            self::assertSame('no such item', $e->getMessage());
        }

        self::assertSame(
            [['.id' => '*7', 'name' => 'second']],
            $transport->execute(new Command('/ip/address', 'print'))->rows,
        );
    }

    /**
     * @return iterable<string, array{key-of<FakeRouter::SCENARIOS>, class-string<ConnectionException>, string|null}>
     */
    public static function brokenStreams(): iterable
    {
        yield 'silence longer than the idleTimeout of stream()' => ['silence', TimeoutException::class, 'idleTimeout'];
        yield 'connection closed' => ['eof', ConnectionException::class, null];
        yield '!fatal' => ['fatal', FatalException::class, null];
        yield 'reply with a foreign tag' => ['wrong-tag', ProtocolException::class, null];
    }

    /**
     * @param key-of<FakeRouter::SCENARIOS> $scenario
     * @param class-string<ConnectionException> $expected
     * @param string|null $limit the limit of a TimeoutException
     */
    #[DataProvider('brokenStreams')]
    public function testBrokenStreamIsAConnectionErrorAndClosesTheTransport(
        string $scenario,
        string $expected,
        ?string $limit,
    ): void {
        $router = FakeRouter::start($scenario);
        $transport = self::connect($router);

        try {
            $transport->stream(new Command('/interface', 'listen'), static fn (): bool => true, idleTimeout: 1.0);
            self::fail($expected . ' expected');
        } catch (ConnectionException $e) {
            self::assertSame($expected, $e::class);
            self::assertSame($limit, $e instanceof TimeoutException ? $e->limit : null);
        }
        self::assertFalse($transport->isOpen());
    }

    /**
     * The client sent, right after the streamed command S, `/cancel =tag=S` with its own tag.
     *
     * @param list<list<string>> $received
     */
    private static function assertCancelSent(array $received): void
    {
        $listenTag = self::tagOf($received[1]);
        $cancel = $received[2];
        $cancelTag = self::tagOf($cancel);

        self::assertSame(['/cancel', '=tag=' . $listenTag, '.tag=' . $cancelTag], $cancel);
        self::assertNotSame($listenTag, $cancelTag);
    }

    /**
     * @param list<string> $sentence
     */
    private static function tagOf(array $sentence): string
    {
        foreach ($sentence as $word) {
            if (str_starts_with($word, '.tag=')) {
                return substr($word, 5);
            }
        }
        self::fail('No .tag in ' . implode(' ', $sentence));
    }

    /**
     * Keep the router in a variable for the whole test: a destroyed FakeRouter stops its process.
     */
    private static function connect(FakeRouter $router, float $cancelTimeout = 10.0): ApiTransport
    {
        return ApiTransport::connect('127.0.0.1', FakeRouter::USER, FakeRouter::PASSWORD, new ApiOptions(
            port: $router->port(),
            cancelTimeout: $cancelTimeout,
        ));
    }
}
