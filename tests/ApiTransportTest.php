<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests;

use Lossik\DeviceCommunication\AuthenticationException;
use Lossik\DeviceCommunication\Command;
use Lossik\DeviceCommunication\CommunicationException;
use Lossik\DeviceCommunication\ConnectionException;
use Lossik\DeviceCommunication\ProtocolException;
use Lossik\DeviceCommunication\Query;
use Lossik\DeviceCommunication\TrapException;
use Lossik\Mikrotik\Api\ApiOptions;
use Lossik\Mikrotik\Api\ApiTransport;
use Lossik\Mikrotik\Api\Tests\Support\FakeRouter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ApiTransport against the fake router (plan §7.1); the scenarios that wait or could hang are in
 * ApiTransportTimingTest.
 */
final class ApiTransportTest extends TestCase
{
    private const OK_ROWS = [['.id' => '*1', 'name' => 'a'], ['.id' => '*2', 'name' => 'b']];

    /** T2 */
    public function testReservedLengthByteIsRejectedAndClosesTheTransport(): void
    {
        $router = FakeRouter::start('reserved-length');
        $transport = self::connect($router);

        try {
            $transport->execute(new Command('/ip/address', 'print'));
            self::fail('ProtocolException expected');
        } catch (ProtocolException) {
        }
        self::assertFalse($transport->isOpen());
    }

    /** T3 */
    public function testValueContainingEqualsSignsStaysWhole(): void
    {
        $router = FakeRouter::start('value-eq');
        $transport = self::connect($router);

        $rows = $transport->execute(new Command('/ip/address', 'print'))->rows;

        self::assertSame([['comment' => 'a=b=c', 'empty' => '']], $rows);
    }

    /** T4 */
    public function testMultiLineValueAndEdgeSpacesAreSentAsOneUnchangedWord(): void
    {
        $router = FakeRouter::start('echo');
        $transport = self::connect($router);

        $transport->execute(new Command('/system/script', 'add', [
            'source' => "l1\nl2 indented\nl3",
            'comment' => '  lead and trail  ',
        ]));
        $transport->close();

        self::assertSame(
            ['/system/script/add', "=source=l1\nl2 indented\nl3", '=comment=  lead and trail  '],
            self::withoutTag($router->received()[1]),
        );
    }

    /** T5 */
    public function testTrapIsReadToItsDoneSoTheNextCommandGetsItsOwnReply(): void
    {
        $router = FakeRouter::start('trap-split');
        $transport = self::connect($router);

        try {
            $transport->execute(new Command('/ip/address', 'remove', ['.id' => '*99']));
            self::fail('TrapException expected');
        } catch (TrapException $e) {
            self::assertSame('no such item', $e->getMessage());
        }

        self::assertSame(
            [['.id' => '*7', 'name' => 'second']],
            $transport->execute(new Command('/ip/address', 'print'))->rows,
        );
    }

    /** T6 */
    public function testRetInAnotherWriteThanDoneIsKept(): void
    {
        $router = FakeRouter::start('done-ret-split');
        $transport = self::connect($router);

        $result = $transport->execute(new Command('/ip/arp', 'add', ['address' => '10.0.0.2']));

        self::assertSame('*9A', $result->ret);
    }

    /** T12 */
    public function testCommandAfterCloseThrowsConnectionExceptionWithoutIo(): void
    {
        $router = FakeRouter::start('ok');
        $transport = self::connect($router);
        $transport->close();

        try {
            $transport->execute(new Command('/ip/address', 'print'));
            self::fail('ConnectionException expected');
        } catch (ConnectionException) {
        }
        self::assertCount(1, $router->received(), 'only the login reached the router');
    }

    /** T13 */
    public function testSeveralTrapsCarryTheFirstCategoryAndMessageAndAllTraps(): void
    {
        $router = FakeRouter::start('trap-multi');
        $transport = self::connect($router);

        try {
            $transport->execute(new Command('/ip/address', 'nonexistent'));
            self::fail('TrapException expected');
        } catch (TrapException $e) {
            self::assertSame(0, $e->category);
            self::assertSame('no such command', $e->getMessage());
            self::assertCount(2, $e->traps());
        }
        self::assertTrue($transport->isOpen());
    }

    /** T14 */
    public function testEmptyReplyIsAnEmptyResult(): void
    {
        $router = FakeRouter::start('empty');
        $transport = self::connect($router);

        self::assertSame([], $transport->execute(new Command('/ip/address', 'print'))->rows);
    }

    /** T15, first row */
    public function testWrongPasswordIsAnAuthenticationError(): void
    {
        $this->expectException(AuthenticationException::class);

        self::connect(FakeRouter::start('bad-login'));
    }

    /** T15, second row */
    public function testLoginReplySplitAcrossWritesLogsInAndTheNextCommandGetsItsOwnRows(): void
    {
        $router = FakeRouter::start('login-split');
        $transport = self::connect($router);

        self::assertSame(self::OK_ROWS, $transport->execute(new Command('/ip/address', 'print'))->rows);
    }

    /** T16 */
    public function testUnreachablePortIsAConnectionErrorNotAnAuthenticationError(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $started = hrtime(true);
        try {
            ApiTransport::connect(
                '127.0.0.1',
                FakeRouter::USER,
                FakeRouter::PASSWORD,
                new ApiOptions(port: $port, connectTimeout: 2.0),
            );
            self::fail('ConnectionException expected');
        } catch (CommunicationException $e) {
            self::assertNotInstanceOf(AuthenticationException::class, $e);
            self::assertInstanceOf(ConnectionException::class, $e);
        }
        self::assertLessThan(2.0, (hrtime(true) - $started) / 1e9);
    }

    /** T17 */
    public function testLegacyChallengeIsRefusedWithoutSendingAnMd5Response(): void
    {
        $router = FakeRouter::start('legacy-login');

        try {
            self::connect($router);
            self::fail('AuthenticationException expected');
        } catch (AuthenticationException) {
        }
        self::assertCount(1, $router->received());
    }

    /** T18 */
    public function testBigResponseArrivingInPiecesIsReadWhole(): void
    {
        $router = FakeRouter::start('big');
        $transport = self::connect($router);

        $rows = $transport->execute(new Command('/ip/address', 'print'))->rows;

        self::assertCount(3000, $rows);
        self::assertSame('*bb7', $rows[2999]['.id']);
    }

    /** T19 */
    public function testReplyWithForeignTagIsAProtocolErrorAndClosesTheTransport(): void
    {
        $router = FakeRouter::start('wrong-tag');
        $transport = self::connect($router);

        try {
            $transport->execute(new Command('/ip/address', 'print'));
            self::fail('ProtocolException expected');
        } catch (ProtocolException) {
        }
        self::assertFalse($transport->isOpen());
    }

    /**
     * T21
     *
     * @return iterable<string, array{Query|null, list<string>, list<string>}>
     */
    public static function queries(): iterable
    {
        yield 'where' => [Query::where('name', 'x'), [], ['?name=x']];
        yield 'has' => [Query::has('name'), [], ['?name']];
        yield 'hasNot' => [Query::hasNot('name'), [], ['?-name']];
        yield 'less' => [Query::less('mtu', '1500'), [], ['?<mtu=1500']];
        yield 'greater' => [Query::greater('mtu', '1500'), [], ['?>mtu=1500']];
        yield 'or' => [Query::or(Query::where('a', '1'), Query::where('b', '2')), [], ['?a=1', '?b=2', '?#|']];
        yield 'and of three' => [
            Query::and(Query::where('a', '1'), Query::where('b', '2'), Query::where('c', '3')),
            [],
            ['?a=1', '?b=2', '?c=3', '?#&', '?#&'],
        ];
        yield 'not' => [Query::not(Query::where('a', '1')), [], ['?a=1', '?#!']];
        yield 'proplist' => [null, ['.id', 'name'], ['.proplist=.id,name']];
    }

    /**
     * @param list<string> $proplist
     * @param list<string> $expectedWords
     */
    #[DataProvider('queries')]
    public function testQueryAndProplistAreSentAsApiWords(?Query $query, array $proplist, array $expectedWords): void
    {
        $router = FakeRouter::start('echo');
        $transport = self::connect($router);

        $transport->execute(new Command('/interface', 'print', [], $query, $proplist));
        $transport->close();

        self::assertSame(['/interface/print', ...$expectedWords], self::withoutTag($router->received()[1]));
    }

    /**
     * T22: "Žluťoučký kůň" in UTF-8 and in windows-1250 (bytes written out, not computed with iconv).
     *
     * @return iterable<string, array{ApiOptions, string}>
     */
    public static function charsets(): iterable
    {
        yield 'default (UTF-8) passes the bytes unchanged' => [
            new ApiOptions(),
            "\xC5\xBDlu\xC5\xA5ou\xC4\x8Dk\xC3\xBD k\xC5\xAF\xC5\x88",
        ];
        yield 'windows-1250 converts both ways' => [
            new ApiOptions(charset: 'windows-1250'),
            "\x8Elu\x9Dou\xE8k\xFD k\xF9\xF2",
        ];
    }

    #[DataProvider('charsets')]
    public function testCharsetOfWordsSentAndReceived(ApiOptions $options, string $bytesOnTheWire): void
    {
        $value = 'Žluťoučký kůň';
        $router = FakeRouter::start('echo-back');
        $transport = self::connect($router, $options);

        $rows = $transport->execute(new Command('/ip/address', 'add', ['comment' => $value]))->rows;
        $transport->close();

        self::assertSame('=comment=' . $bytesOnTheWire, $router->received()[1][1]);
        self::assertSame([['comment' => $value]], $rows);
    }

    /** T24 */
    public function testMenuWithSpacesIsSentAsMenuWithSlashes(): void
    {
        $router = FakeRouter::start('echo');
        $transport = self::connect($router);

        $transport->execute(new Command('ip firewall nat', 'print'));
        $transport->close();

        self::assertSame('/ip/firewall/nat/print', $router->received()[1][0]);
    }

    /**
     * Keep the router in a variable for the whole test: a destroyed FakeRouter stops its process.
     */
    private static function connect(FakeRouter $router, ApiOptions $options = new ApiOptions()): ApiTransport
    {
        return ApiTransport::connect('127.0.0.1', FakeRouter::USER, FakeRouter::PASSWORD, new ApiOptions(
            port: $router->port(),
            connectTimeout: $options->connectTimeout,
            idleTimeout: $options->idleTimeout,
            responseTimeout: $options->responseTimeout,
            cancelTimeout: $options->cancelTimeout,
            charset: $options->charset,
        ));
    }

    /**
     * The words of a received sentence without its `.tag` (exactly one is required).
     *
     * @param list<string> $sentence
     * @return list<string>
     */
    private static function withoutTag(array $sentence): array
    {
        $words = array_values(array_filter($sentence, static fn (string $w): bool => !str_starts_with($w, '.tag=')));
        self::assertCount(count($sentence) - 1, $words, 'exactly one .tag word');

        return $words;
    }
}
