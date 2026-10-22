<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests\Support;

/**
 * Runs a fake RouterOS API server (tests/Support/fake-router.php, FakeRouterServer) in its own process and collects
 * what the client sent to it.
 *
 * Every reply sentence carries the `.tag` of the command it answers (as its last word); a command without `.tag`
 * is answered without one. Exceptions are the scenarios that break it on purpose (`wrong-tag`, `fatal`).
 *
 * TLS mode (start() with $tlsCertificate or $tlsAnonymous): the same scenarios after a TLS handshake. When the
 * handshake fails, the router ends without receiving anything (received() returns []).
 */
final class FakeRouter
{
    public const USER = 'admin';

    public const PASSWORD = 'secret';

    /**
     * Scenario name => what the fake router does. Every scenario except bad-login and legacy-login first answers a
     * correct post-6.43 `/login` (`=name=`, `=password=`) with `!done` (wrong credentials: `!trap` + `!done`), then
     * its script; the script handles the first command after the login unless said otherwise. Scenarios that do not
     * close the connection then answer every further command like `ok`.
     */
    public const SCENARIOS = [
        'ok' => '`!re =.id=*1 =name=a`, `!re =.id=*2 =name=b`, `!done` in one write',
        'silence' => 'no reply at all',
        'silence-in-length' => 'only 0x80 (the first byte of a two-byte length), then silent',
        'eof' => 'closes the connection before the first byte of the reply',
        'eof-mid' => 'the first 7 bytes of `!re =.id=*1 =name=cut` (03 21 72 65 07 3d 2e: inside the content '
            . 'of "=.id=*1"), then closes',
        'eof-in-length' => 'only 0x80, then closes',
        'slow-gaps' => 'the 3 sentences of `ok`, each after a pause of 1.3 s (3.9 s in total)',
        'slow-mid' => '`!re =.id=*1 =name=late-content`, `!done` with pauses of 1.3 s after byte 7 and byte 9 '
            . '(both inside the content of "=.id=*1")',
        'slow-in-length' => '`!re =comment=<191 x>`, `!done` with a pause of 1.3 s after byte 5 (between the two bytes '
            . '0x80 0xC8 of the length)',
        'trickle' => 'the reply of `ok` one byte every 0.3 s for 5.1 s (17 pauses), then the rest',
        'fatal' => '`!fatal`, `session terminated on request` (no tag), then closes',
        'value-eq' => '`!re =comment=a=b=c =empty=`, `!done`',
        'echo' => 'answers every command with `!done` (received() shows what was sent)',
        'echo-back' => 'answers every command with `!re` carrying its `=...` words unchanged (bytes as received), '
            . 'then `!done`',
        'trap-split' => '`!trap =message=no such item`, 300 ms, `!done` in a second write; the second command gets '
            . '`!re =.id=*7 =name=second`, `!done`',
        'done-ret-split' => '`!done =ret=*9A` split after the word "!done": 05 "!done", 300 ms, the rest',
        'trap-multi' => '`!trap =category=0 =message=no such command`, `!trap =message=no such command prefix`, '
            . '`!done`',
        'empty' => '`!empty`, `!done`',
        'bad-login' => 'answers the login with `!trap =message=invalid user name or password (6)`, `!done` '
            . '(whatever the credentials)',
        'login-split' => 'answers the login with `!done` whose terminating empty word comes 300 ms later; '
            . 'then every command like `ok`',
        'legacy-login' => 'answers the login with the pre-6.43 challenge `!done =ret=<32 hex>`; a second sentence '
            . '(an MD5 response) would be accepted with `!done`',
        'big' => '3000 `!re =.id=*<hex n> =name=item<n>` (n = 0..2999, last `.id` = `*bb7`), `!done`',
        'wrong-tag' => 'the reply of `ok` with `.tag=999`',
        'reserved-length' => 'only the control byte 0xF8, then closes',
        'listen' => '3 `!re =.id=*n =name=event<n>` with the listen tag S, 0.3 s apart; then waits for `/cancel` '
            . '(own tag C, `=tag=S`) and answers it with $cancelReply (see start())',
    ];

    /** The order RouterOS documents for /cancel: trap(S) -> done(C) -> done(S). */
    public const CANCEL_DOCUMENTED = ['trap-s', 'done-c', 'done-s'];

    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private int $port;

    /**
     * @param list<string> $cancelReply listen only: the sentences that answer `/cancel`, in this order and in one
     *                                  write: 'trap-s' (`!trap =category=2 =message=interrupted`, tag S),
     *                                  'done-s' (`!done`, tag S), 'trap-c' (`!trap =message=…`, tag C),
     *                                  'done-c' (`!done`, tag C); leave one out to never send it
     * @param bool $doneBeforeCancel listen only: the 3 `!re` and `!done` of S come in one write, so `!done` S
     *                               arrives before the client sends `/cancel` (then $cancelReply holds only
     *                               C sentences)
     * @param string|null $tlsCertificate TLS with this certificate: a PEM file holding the certificate and its key
     * @param bool $tlsAnonymous TLS 1.2 with anonymous DH only (`ADH:@SECLEVEL=0`, no certificate), like api-ssl
     *                           of a router without a certificate
     */
    private function __construct(
        string $scenario,
        array $cancelReply,
        bool $doneBeforeCancel,
        string $user,
        string $password,
        ?string $tlsCertificate,
        bool $tlsAnonymous,
    ) {
        if (!isset(self::SCENARIOS[$scenario])) {
            throw new \InvalidArgumentException('Unknown fake router scenario: ' . $scenario);
        }
        $script = base64_encode(json_encode([
            'scenario' => $scenario,
            'user' => $user,
            'password' => $password,
            'cancelReply' => $cancelReply,
            'doneBeforeCancel' => $doneBeforeCancel,
            'tls' => $tlsAnonymous ? 'anonymous' : $tlsCertificate,
        ], JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, __DIR__ . '/fake-router.php', $script], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if ($process === false) {
            throw new \RuntimeException('Cannot start the fake router');
        }
        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_timeout($this->pipes[1], 10);
        $line = (string) fgets($this->pipes[1]);
        if (preg_match('/^PORT (\d+)$/', trim($line), $m) !== 1) {
            stream_set_blocking($this->pipes[2], false);
            $err = (string) stream_get_contents($this->pipes[2]);
            $this->stop();
            throw new \RuntimeException('Fake router did not report its port: ' . $line . $err);
        }
        $this->port = (int) $m[1];
    }

    /**
     * @param key-of<self::SCENARIOS> $scenario
     * @param list<'trap-s'|'done-s'|'trap-c'|'done-c'> $cancelReply see the constructor
     */
    public static function start(
        string $scenario = 'ok',
        array $cancelReply = self::CANCEL_DOCUMENTED,
        bool $doneBeforeCancel = false,
        string $user = self::USER,
        string $password = self::PASSWORD,
        ?string $tlsCertificate = null,
        bool $tlsAnonymous = false,
    ): self {
        return new self($scenario, $cancelReply, $doneBeforeCancel, $user, $password, $tlsCertificate, $tlsAnonymous);
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * Waits until the router finished its script and the client disconnected (or the router closed the
     * connection).
     *
     * @return list<list<string>> sentences received (words with their bytes as received), the login first
     */
    public function received(): array
    {
        $out = (string) stream_get_contents($this->pipes[1]);
        stream_set_blocking($this->pipes[2], false);
        $err = (string) stream_get_contents($this->pipes[2]);
        $this->stop();
        if (preg_match('/^END$/m', $out) !== 1) {
            throw new \RuntimeException("Fake router did not finish:\n" . $out . $err);
        }
        preg_match_all('/^RECV (.*)$/m', $out, $m);

        $sentences = [];
        foreach ($m[1] as $json) {
            $words = [];
            foreach ((array) json_decode($json, true, 512, JSON_THROW_ON_ERROR) as $word) {
                $bytes = is_string($word) ? base64_decode($word, true) : false;
                if ($bytes === false) {
                    throw new \RuntimeException('Bad RECV line: ' . $json);
                }
                $words[] = $bytes;
            }
            $sentences[] = $words;
        }

        return $sentences;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
