<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests;

use Lossik\DeviceCommunication\AuthenticationException;
use Lossik\DeviceCommunication\Command;
use Lossik\DeviceCommunication\CommunicationException;
use Lossik\DeviceCommunication\ConnectionException;
use Lossik\DeviceCommunication\ProtocolException;
use Lossik\DeviceCommunication\Query;
use Lossik\DeviceCommunication\TimeoutException;
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

    /**
     * @return iterable<string, array{?int, float, float, class-string<ConnectionException>}>
     */
    public static function connectFailures(): iterable
    {
        yield '110 (Linux ETIMEDOUT) before limit' => [110, 0.05, 2.0, TimeoutException::class];
        yield '60 (Darwin/macOS ETIMEDOUT) before limit' => [60, 0.05, 2.0, TimeoutException::class];
        yield '10060 (Windows WSAETIMEDOUT) before limit' => [10060, 0.05, 2.0, TimeoutException::class];
        yield '111 (Linux ECONNREFUSED) after limit' => [111, 2.5, 2.0, ConnectionException::class];
        yield '61 (Darwin/macOS ECONNREFUSED) after limit' => [61, 2.5, 2.0, ConnectionException::class];
        yield '10061 (Windows WSAECONNREFUSED) after limit' => [10061, 2.5, 2.0, ConnectionException::class];
        yield '0 (e.g. TLS handshake stall) at limit' => [0, 2.0, 2.0, TimeoutException::class];
        yield '0 (e.g. TLS handshake stall) after limit' => [0, 2.5, 2.0, TimeoutException::class];
        yield '0 (e.g. immediate TLS failure) before limit' => [0, 0.5, 2.0, ConnectionException::class];
        yield 'null errno after limit' => [null, 2.5, 2.0, TimeoutException::class];
        yield 'null errno before limit' => [null, 0.5, 2.0, ConnectionException::class];
    }

    /**
     * Connect failure classification without network I/O or OS dependencies.
     *
     * @param class-string<ConnectionException> $expectedClass
     */
    #[DataProvider('connectFailures')]
    public function testConnectFailureClassification(
        ?int $errno,
        float $elapsed,
        float $connectTimeout,
        string $expectedClass,
    ): void {
        self::assertSame(
            $expectedClass,
            ApiTransport::connectExceptionClass($errno, $elapsed, $connectTimeout),
        );
    }

    /**
     * T17 (FAQ-8a): the legacy MD5 login of RouterOS before 6.43. The expected `=response=` values are written out:
     * '00' + md5(zero byte . password bytes . the 16 bytes of the challenge 0123456789abcdeffedcba9876543210),
     * calculated outside the code under test (python hashlib, cross-checked with openssl md5). The fake router
     * accepts nothing but the expected response, so a wrong digest is an AuthenticationException.
     *
     * @return iterable<string, array{string, ApiOptions, string}> password, options, expected `=response=` value
     */
    public static function legacyLogins(): iterable
    {
        yield 'ASCII password' => ['secret', new ApiOptions(), '003b45289c18627761f0eb67731ebe47fc'];
        yield 'non-ASCII password, UTF-8 bytes' => [
            'Heslo-žlutý',
            new ApiOptions(),
            '000a14536309f738932bea0545b6b5fd4e',
        ];
        yield 'non-ASCII password, windows-1250 bytes' => [
            'Heslo-žlutý',
            new ApiOptions(charset: 'windows-1250'),
            '000f32207d554f7ef54a6961cd2922a32c',
        ];
    }

    #[DataProvider('legacyLogins')]
    public function testLegacyChallengeIsAnsweredWithTheMd5ResponseAndTheSessionWorks(
        string $password,
        ApiOptions $options,
        string $response,
    ): void {
        $router = FakeRouter::start('legacy-login', legacyResponse: $response);
        $transport = self::connect($router, $options, $password);

        $rows = $transport->execute(new Command('/ip/address', 'print'))->rows;
        $transport->close();

        self::assertSame(self::OK_ROWS, $rows);
        $received = $router->received();
        self::assertCount(3, $received);
        self::assertSame(
            ['/login', '=name=' . FakeRouter::USER, '=response=' . $response],
            self::withoutTag($received[1]),
            'the response sentence carries no password',
        );
    }

    /** T17 */
    public function testWrongPasswordAtTheLegacyLoginIsAnAuthenticationExceptionAndNothingFollows(): void
    {
        $router = FakeRouter::start('legacy-login');

        try {
            self::connect($router, password: 'wrong');
            self::fail('AuthenticationException expected');
        } catch (AuthenticationException) {
        }
        self::assertCount(2, $router->received(), 'the login and its MD5 response, no command');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedChallenges(): iterable
    {
        yield 'empty' => [''];
        yield '31 hex digits' => ['0123456789abcdeffedcba987654321'];
        yield '33 hex digits' => ['0123456789abcdeffedcba98765432100'];
        yield '32 characters, not all hex' => ['0123456789abcdeffedcba987654321g'];
        yield '32 hex digits followed by LF' => ["0123456789abcdeffedcba9876543210\n"];
    }

    #[DataProvider('malformedChallenges')]
    public function testMalformedLegacyChallengeIsAProtocolExceptionAndNoResponseIsSent(string $challenge): void
    {
        $router = FakeRouter::start('legacy-login', legacyChallenge: $challenge);

        try {
            self::connect($router);
            self::fail('ProtocolException expected');
        } catch (ProtocolException) {
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

    /**
     * T19 (FAQ-3): a reply without `.tag` belongs to no command, like one with another tag.
     *
     * @return iterable<string, array{key-of<FakeRouter::SCENARIOS>}>
     */
    public static function foreignReplies(): iterable
    {
        yield 'reply with another tag' => ['wrong-tag'];
        yield 'reply without .tag' => ['no-tag'];
    }

    /**
     * @param key-of<FakeRouter::SCENARIOS> $scenario
     */
    #[DataProvider('foreignReplies')]
    public function testReplyWithForeignTagIsAProtocolErrorAndClosesTheTransport(string $scenario): void
    {
        $router = FakeRouter::start($scenario);
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
        yield 'proplist' => [null, ['.id', 'name'], ['=.proplist=.id,name']];
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

    /**
     * T22b (FAQ-2): words windows-1250 cannot hold. iconv works on the whole word, so one such character spoils the
     * word, and nothing is approximated (`Ĩ` would become `I` with //TRANSLIT, which 2.x used).
     *
     * @return iterable<string, array{string}>
     */
    public static function unconvertibleValues(): iterable
    {
        yield 'Cyrillic letter' => ['Ж'];
        yield 'one such character among convertible ones' => ['Žluťoučký Ж kůň'];
        yield 'character that //TRANSLIT would approximate' => ['Ĩ'];
    }

    #[DataProvider('unconvertibleValues')]
    public function testWordUnconvertibleToTheCharsetIsRejectedBeforeAnyIoAndTheTransportStaysOpen(string $value): void
    {
        $router = FakeRouter::start('echo');
        $transport = self::connect($router, new ApiOptions(charset: 'windows-1250'));

        try {
            $transport->execute(new Command('/ip/address', 'add', ['comment' => $value]));
            self::fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException) {
        }
        self::assertTrue($transport->isOpen());

        $transport->execute(new Command('/ip/address', 'print'));
        $transport->close();
        $received = $router->received();
        self::assertCount(2, $received, 'the login and the next command; nothing of the rejected one');
        self::assertSame(['/ip/address/print'], self::withoutTag($received[1]));
    }

    /** T22b (FAQ-2) */
    public function testReplyWordUndefinedInTheCharsetIsAProtocolErrorAndClosesTheTransport(): void
    {
        $router = FakeRouter::start('undefined-byte');
        $transport = self::connect($router, new ApiOptions(charset: 'windows-1250'));

        try {
            $transport->execute(new Command('/ip/address', 'print'));
            self::fail('ProtocolException expected');
        } catch (ProtocolException) {
        }
        self::assertFalse($transport->isOpen());
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
    private static function connect(
        FakeRouter $router,
        ApiOptions $options = new ApiOptions(),
        string $password = FakeRouter::PASSWORD,
    ): ApiTransport {
        return ApiTransport::connect('127.0.0.1', FakeRouter::USER, $password, new ApiOptions(
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
