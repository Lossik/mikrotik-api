<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests\Live;

use Lossik\DeviceCommunication\Client;
use Lossik\DeviceCommunication\ConnectionException;
use Lossik\DeviceCommunication\TimeoutException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * A router that keeps quiet (plan L11, N1): the only timing scenario that has a real router behind it; the
 * rest (T5 to T10) needs the fake router, which can split writes and fall silent on demand.
 */
#[Group('live')]
#[Medium]
final class SilenceLiveTest extends LiveTestCase
{
    public function testCommandThatTakesLongerThanTheIdleTimeoutIsATimeoutAndClosesTheTransport(): void
    {
        $this->client()->menu('/system/script')->add([
            'name' => 'lossik-live-delay',
            'source' => ':delay 5s',
            'comment' => self::TAG,
        ]);
        $transport = $this->transport($this->env->options(idleTimeout: 2.0));
        $client = new Client($transport);

        $started = microtime(true);
        try {
            $client->menu('/system/script')->run('run', ['number' => 'lossik-live-delay']);
            self::fail('The script that sleeps 5 s answered within the 2 s idle timeout');
        } catch (TimeoutException $e) {
            $elapsed = microtime(true) - $started;
        }

        self::assertSame('idleTimeout', $e->limit);
        self::assertGreaterThanOrEqual(1.9, $elapsed);
        self::assertLessThan(4.0, $elapsed);
        self::assertFalse($transport->isOpen());
        $this->expectException(ConnectionException::class);
        $client->version();
    }
}
