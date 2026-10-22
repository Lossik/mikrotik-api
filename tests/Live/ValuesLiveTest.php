<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests\Live;

use Lossik\DeviceCommunication\Query;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * Values that 2.x mangled (plan L2, API half; B1, B2) and the charset (L7, API half; U2, Q-D). The SSH half of both
 * is in lossik/mikrotik-ssh.
 */
#[Group('live')]
#[Medium]
final class ValuesLiveTest extends LiveTestCase
{
    private const LIST = 'lossik-live-values';

    private const ADDRESS = '10.98.0.1';

    private const PANGRAM = 'Příliš žluťoučký kůň';

    /**
     * The comment of these items is the value under test, so they cannot carry the tag the clean-up looks for.
     */
    protected function tearDown(): void
    {
        if (isset($this->env)) {
            $raw = $this->raw();
            foreach ($raw->rows('/ip/firewall/address-list/print', ['?list=' . self::LIST, '=.proplist=.id']) as $row) {
                $raw->rows('/ip/firewall/address-list/remove', ['=.id=' . $row['.id']]);
            }
            $raw->close();
        }
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function awkwardValues(): iterable
    {
        yield 'equals signs' => ['a=b=c'];
        yield 'new line' => ["l1\nl2"];
        yield 'leading and trailing spaces' => ['  lead and trail  '];
        yield 'double quote' => ['say "hi"'];
        yield 'dollar' => ['$var'];
        yield 'backslash' => ['c:\\x'];
    }

    #[DataProvider('awkwardValues')]
    public function testValueComesBackIdentical(string $value): void
    {
        $menu = $this->client()->menu('/ip/firewall/address-list');

        $menu->add(['list' => self::LIST, 'address' => self::ADDRESS, 'comment' => $value]);
        $row = $menu->first(Query::where('list', self::LIST));

        self::assertSame($value, $row['comment'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function charsets(): iterable
    {
        yield 'UTF-8 (the default)' => ['UTF-8', self::PANGRAM];
        $cp1250 = (string) iconv('UTF-8', 'windows-1250', self::PANGRAM);
        yield 'windows-1250 (the 2.x default)' => ['windows-1250', $cp1250];
    }

    /**
     * U2: what the router stores is what the client sent, byte for byte, in whichever charset the client talks (the
     * router does not convert) - the raw bytes of the reply are the ones that were written. Which charset the
     * default should be (Q-D) also depends on how the router's own CLI shows them (F5b, over SSH).
     */
    #[DataProvider('charsets')]
    public function testTheRouterStoresTheBytesTheClientSent(string $charset, string $expectedBytes): void
    {
        $options = $this->env->options(charset: $charset);
        $menu = $this->client($options)->menu('/ip/firewall/address-list');

        $menu->add(['list' => self::LIST, 'address' => self::ADDRESS, 'comment' => self::PANGRAM]);
        $row = $menu->first(Query::where('list', self::LIST));
        $raw = $this->raw();
        $rawRow = $raw->rows('/ip/firewall/address-list/print', ['?list=' . self::LIST])[0] ?? [];
        $raw->close();

        self::assertSame(self::PANGRAM, $row['comment'] ?? null);
        self::assertSame($expectedBytes, $rawRow['comment'] ?? null);
    }
}
