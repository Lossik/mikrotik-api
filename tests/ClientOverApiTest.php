<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests;

use Lossik\DeviceCommunication\Client;
use Lossik\DeviceCommunication\Query;
use Lossik\Mikrotik\Api\ApiOptions;
use Lossik\Mikrotik\Api\ApiTransport;
use Lossik\Mikrotik\Api\Tests\Support\FakeRouter;
use PHPUnit\Framework\TestCase;

/**
 * Client of lossik/device-communication over ApiTransport against the fake router (plan F3f): a wiring check in the
 * shape of the first consumer, not a copy of the Menu unit tests (device-communication) or of the query words (T21).
 */
final class ClientOverApiTest extends TestCase
{
    private const OK_ROWS = [['.id' => '*1', 'name' => 'a'], ['.id' => '*2', 'name' => 'b']];

    public function testPrintUpdateFirstAndDeleteReachTheRouterAsApiWords(): void
    {
        // `ok` answers every command with the rows *1 and *2
        $router = FakeRouter::start('ok');
        $transport = self::connect($router);
        $menu = (new Client($transport))->menu('/ip/firewall/address-list');

        $rows = $menu->print(Query::where('list', 'x'), proplist: ['.id', 'address']);
        $updated = $menu->update(Query::where('list', 'x'), ['disabled' => 'yes'], onlyFirst: true);
        $deleted = $menu->delete(Query::where('list', 'x'));
        $transport->close();

        self::assertSame(self::OK_ROWS, $rows);
        self::assertSame(1, $updated);
        self::assertSame(2, $deleted);
        self::assertSame([
            ['/ip/firewall/address-list/print', '.proplist=.id,address', '?list=x'],
            ['/ip/firewall/address-list/print', '.proplist=.id', '?list=x'],
            ['/ip/firewall/address-list/set', '=.id=*1', '=disabled=yes'],
            ['/ip/firewall/address-list/print', '.proplist=.id', '?list=x'],
            ['/ip/firewall/address-list/remove', '=.id=*1,*2'],
        ], array_map(self::withoutTag(...), array_slice($router->received(), 1)));
    }

    public function testListenOfAMenuStreamsOverApiTransport(): void
    {
        $router = FakeRouter::start('listen');
        $transport = self::connect($router);

        $rows = [];
        (new Client($transport))->menu('/interface')->listen(static function (array $row) use (&$rows): bool {
            $rows[] = $row;

            return false;
        });
        $transport->close();

        self::assertSame([['.id' => '*1', 'name' => 'event1']], $rows);
        self::assertSame(['/interface/listen'], self::withoutTag($router->received()[1]));
    }

    private static function connect(FakeRouter $router): ApiTransport
    {
        return ApiTransport::connect('127.0.0.1', FakeRouter::USER, FakeRouter::PASSWORD, new ApiOptions(
            port: $router->port(),
        ));
    }

    /**
     * @param list<string> $sentence
     * @return list<string>
     */
    private static function withoutTag(array $sentence): array
    {
        return array_values(array_filter($sentence, static fn (string $w): bool => !str_starts_with($w, '.tag=')));
    }
}
