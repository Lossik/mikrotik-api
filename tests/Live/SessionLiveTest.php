<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests\Live;

use Lossik\DeviceCommunication\AuthenticationException;
use Lossik\DeviceCommunication\Client;
use Lossik\DeviceCommunication\Query;
use Lossik\DeviceCommunication\TrapException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * Login, replies and traps of a real RouterOS (plan L1, L3, L5). Every scenario of the live suite also measures U20:
 * ApiTransport throws ProtocolException on a reply whose `.tag` is not the command's, so a router that left a tag
 * out of any sentence would turn these tests red.
 */
#[Group('live')]
#[Medium]
final class SessionLiveTest extends LiveTestCase
{
    public function testLoginAndVersionReportTheRunningRouterOs(): void
    {
        $version = $this->client()->version();

        self::assertNotNull($version);
        self::assertMatchesRegularExpression('/^' . preg_quote($this->env->chrVersion, '/') . '( |$)/', $version);
    }

    /**
     * Outside the plan: the real reply to a wrong password (T15 has it only from the fake router).
     */
    public function testWrongPasswordIsAnAuthenticationException(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->env->connect(password: $this->env->password . '-wrong');
    }

    /**
     * FAQ-3 / U20 in raw sentences: every sentence of every command carries the tag of its command, up to and
     * including the `!done` that ends it (the reading stops at the first `!done`, a missing one runs into the
     * timeout), and the unknown command is answered with a `!trap` (plan-stare-ros §5.1). Which other reply types
     * come is the router's business: RouterOS 6.x never sends `!empty`. `!fatal` is not read here on purpose: the
     * router sends it without a tag (see StreamLiveTest::testQuit...).
     */
    public function testEveryReplySentenceCarriesTheTagOfItsCommand(): void
    {
        $raw = $this->raw();
        $untagged = [];
        $types = [];
        $commands = [
            ['/system/resource/print', ['=.proplist=version']],
            ['/ip/firewall/address-list/print', ['?list=lossik-live-none']],
            ['/lossik-live-none', []],
            ['/ip/firewall/address-list/add', ['=list=lossik-live-tag', '=address=10.97.0.1', '=comment=' . self::TAG]],
        ];
        foreach ($commands as [$command, $words]) {
            $tag = $raw->send($command, $words);
            do {
                $sentence = $raw->readSentence(microtime(true) + 10.0);
                $types[$command][] = RawApi::type($sentence);
                if (RawApi::tagOf($sentence) !== $tag) {
                    $untagged[] = $command . ' ' . json_encode($sentence);
                }
            } while (RawApi::type($sentence) !== '!done');
        }
        $raw->close();

        self::assertSame([], $untagged);
        self::assertContains('!trap', $types['/lossik-live-none'] ?? []);
    }

    /**
     * L3: the trap carries the category; the response is read to its end, so the same connection goes on.
     */
    public function testUnknownCommandIsATrapWithACategoryAndTheConnectionStaysOpen(): void
    {
        $transport = $this->transport();
        $client = new Client($transport);

        try {
            $client->menu('/')->run('lossik-live-nonexistent');
            self::fail('The router accepted an unknown command');
        } catch (TrapException $e) {
            self::assertSame(0, $e->category);
            self::assertNotSame([], $e->traps());
        }

        self::assertTrue($transport->isOpen());
        self::assertNotNull($client->version());
    }

    /**
     * L5: no match is an empty result (RouterOS 7.18+ says `!empty`; older ones just `!done`).
     */
    public function testPrintWithNoMatchIsAnEmptyList(): void
    {
        $rows = $this->client()->menu('/ip/firewall/address-list')->print(Query::where('list', 'lossik-live-none'));

        self::assertSame([], $rows);
    }
}
