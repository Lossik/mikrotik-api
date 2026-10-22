<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests\Live;

use Lossik\DeviceCommunication\Menu;
use Lossik\DeviceCommunication\Query;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * Client and Menu over ApiTransport against a real RouterOS: update and delete of several items (plan L4), the
 * answers behind U5 (L10) and the menus the first consumer uses (L14).
 */
#[Group('live')]
#[Medium]
final class MenuLiveTest extends LiveTestCase
{
    private const ID_PATTERN = '/^\*[0-9A-F]+$/';

    private const LIST = 'lossik-live-menu';

    /**
     * The attributes `add` needs on each menu, as RouterOS itself demands them (its `!trap` "missing =parent=" and
     * the like, measured on CHR 7.24.5); `comment` is added by the test. All of them come back from `print`
     * verbatim.
     *
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function menus(): iterable
    {
        yield '/queue/tree' => ['/queue/tree', ['name' => 'lossik-live-q', 'parent' => 'global']];
        yield '/queue/simple' => ['/queue/simple', ['name' => 'lossik-live-qs', 'target' => '10.96.0.1/32']];
        yield '/ip/firewall/nat' => ['/ip/firewall/nat', ['chain' => 'srcnat', 'action' => 'masquerade']];
        yield '/ip/firewall/filter' => ['/ip/firewall/filter', ['chain' => 'forward', 'action' => 'accept']];
        yield '/ip/firewall/mangle' => [
            '/ip/firewall/mangle',
            ['chain' => 'prerouting', 'action' => 'mark-packet', 'new-packet-mark' => 'lossik-live'],
        ];
        yield '/ip/firewall/address-list' => [
            '/ip/firewall/address-list',
            ['list' => 'lossik-live-menu', 'address' => '10.96.0.2'],
        ];
        yield '/ip/arp' => ['/ip/arp', ['address' => '10.96.0.3', 'interface' => 'ether1']];
        yield '/ip/address' => ['/ip/address', ['address' => '10.96.1.1/24', 'interface' => 'ether1']];
        yield '/ppp/secret' => ['/ppp/secret', ['name' => 'lossik-live-u']];
        yield '/ip/dhcp-server/lease' => [
            '/ip/dhcp-server/lease',
            ['address' => '10.96.0.4', 'mac-address' => '02:00:00:00:00:02'],
        ];
        yield '/ipv6/dhcp-server/binding' => [
            '/ipv6/dhcp-server/binding',
            ['address' => '2001:db8::1/128', 'duid' => '0x0001', 'iaid' => '1'],
        ];
        yield '/ipv6/firewall/address-list' => [
            '/ipv6/firewall/address-list',
            ['list' => 'lossik-live-menu', 'address' => '2001:db8::2/128'],
        ];
    }

    /**
     * L14: the cycle of the first consumer on each menu it uses.
     *
     * @param array<string, string> $attributes
     */
    #[DataProvider('menus')]
    public function testAddPrintUpdateDeleteOnTheMenu(string $path, array $attributes): void
    {
        $menu = $this->client()->menu($path);
        $mine = Query::where('comment', self::TAG);

        $id = $menu->add($attributes + ['comment' => self::TAG]);
        $added = $menu->print($mine);
        $updated = $menu->update($mine, ['disabled' => 'yes']);
        $disabled = $menu->first($mine);
        $deleted = $menu->delete($mine);

        self::assertNotNull($id);
        self::assertMatchesRegularExpression(self::ID_PATTERN, $id);
        self::assertCount(1, $added);
        self::assertSame($id, $added[0]['.id'] ?? null);
        foreach ($attributes as $key => $value) {
            self::assertSame($value, $added[0][$key] ?? null, $key);
        }
        self::assertSame(1, $updated);
        self::assertSame('true', $disabled['disabled'] ?? null);
        self::assertSame(1, $deleted);
        self::assertSame([], $menu->print($mine));
    }

    /**
     * The proplist reaches the router as the attribute word `=.proplist=`; with a plain `.proplist=` word RouterOS
     * ignores it and returns every attribute (measured on CHR 7.24.5). It adds no `.id` the caller did not ask for.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function proplists(): iterable
    {
        yield 'name only' => [['name']];
        yield 'id and name' => [['.id', 'name']];
    }

    /**
     * @param list<string> $proplist
     */
    #[DataProvider('proplists')]
    public function testPrintReturnsOnlyTheAttributesOfTheProplist(array $proplist): void
    {
        $rows = $this->client()->menu('/interface')->print(proplist: $proplist);

        self::assertNotSame([], $rows);
        foreach ($rows as $row) {
            $keys = array_keys($row);
            sort($keys);
            $expected = $proplist;
            sort($expected);
            self::assertSame($expected, $keys);
        }
    }

    public function testDhcpServerCanBePrinted(): void
    {
        // the CHR has no DHCP server and the live tests add none
        self::assertSame([], $this->client()->menu('/ip/dhcp-server')->print());
    }

    public function testIdentityCanBeSetAndTheNameIsRestored(): void
    {
        $identity = $this->client()->menu('/system/identity');
        $original = self::nameOf($identity);
        self::assertNotNull($original);

        try {
            $identity->run('set', ['name' => 'lossik-live-name']);
            $changed = self::nameOf($identity);
        } finally {
            $raw = $this->raw();
            $raw->rows('/system/identity/set', ['=name=' . $original]);
            $raw->close();
        }

        self::assertSame('lossik-live-name', $changed);
    }

    /**
     * L4: `add` returns the ID, `update` and `delete` change all matches with one `.id=*1,*2` or only the first.
     */
    public function testUpdateAndDeleteReachAllMatchesOrOnlyTheFirst(): void
    {
        $menu = $this->client()->menu('/ip/firewall/address-list');
        $mine = Query::where('list', self::LIST);
        $ids = [];
        foreach (['10.96.2.1', '10.96.2.2', '10.96.2.3'] as $address) {
            $ids[] = $this->addToList($menu, $address);
        }

        $updatedAll = $menu->update($mine, ['disabled' => 'yes']);
        $afterAll = self::disabledById($menu, $mine);
        $updatedFirst = $menu->update($mine, ['disabled' => 'no'], onlyFirst: true);
        $afterFirst = self::disabledById($menu, $mine);
        $deletedFirst = $menu->delete($mine, onlyFirst: true);
        $remaining = array_keys(self::disabledById($menu, $mine));
        $deletedRest = $menu->delete($mine);

        foreach ($ids as $id) {
            self::assertMatchesRegularExpression(self::ID_PATTERN, $id);
        }
        self::assertSame(3, $updatedAll);
        self::assertSame(array_fill_keys($ids, 'true'), $afterAll);
        self::assertSame(1, $updatedFirst);
        self::assertSame([$ids[0] => 'false', $ids[1] => 'true', $ids[2] => 'true'], $afterFirst);
        self::assertSame(1, $deletedFirst);
        self::assertSame([$ids[1], $ids[2]], $remaining);
        self::assertSame(2, $deletedRest);
        self::assertSame([], $menu->print($mine));
    }

    /**
     * L10 / U5: RouterOS resolves an unambiguous abbreviation of a menu in the API too.
     */
    public function testAbbreviatedMenuNameIsResolvedByTheRouter(): void
    {
        $this->client()->menu('/ip/firewall/nat')->add(['chain' => 'srcnat', 'comment' => self::TAG]);

        $rows = $this->client()->menu('/ip/firewal/nat')->print(Query::where('comment', self::TAG));

        self::assertCount(1, $rows);
    }

    /**
     * L10 / U5: `?address=` (the empty value) matches no item, not all of them.
     */
    public function testQueryForAnEmptyValueMatchesNothing(): void
    {
        $menu = $this->client()->menu('/ip/firewall/address-list');
        $this->addToList($menu, '10.96.3.1');

        self::assertSame([], $menu->print(Query::where('address', '')));
    }

    /**
     * L10 / U5: what 2.x sent when nothing matched - `set` and `remove` with an empty `.id` - changes nothing on the
     * router and is no error. 3.0 never sends it (Menu::update/delete print first).
     */
    public function testEmptyIdIsNoOp(): void
    {
        $menu = $this->client()->menu('/ip/firewall/address-list');
        $this->addToList($menu, '10.96.3.2');

        $menu->run('set', ['.id' => '', 'disabled' => 'yes']);
        $menu->run('remove', ['.id' => '']);

        $row = $menu->first(Query::where('list', self::LIST));
        self::assertSame('false', $row['disabled'] ?? null);
    }

    private static function nameOf(Menu $identity): ?string
    {
        return $identity->first()['name'] ?? null;
    }

    private function addToList(Menu $menu, string $address): string
    {
        $id = $menu->add(['list' => self::LIST, 'address' => $address, 'comment' => self::TAG]);
        self::assertNotNull($id);

        return $id;
    }

    /**
     * @return array<string, string> the `disabled` of each matching item, by `.id`
     */
    private static function disabledById(Menu $menu, Query $query): array
    {
        $disabled = [];
        foreach ($menu->print($query) as $row) {
            $disabled[$row['.id']] = $row['disabled'] ?? '';
        }

        return $disabled;
    }
}
