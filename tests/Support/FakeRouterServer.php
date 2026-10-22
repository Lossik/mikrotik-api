<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests\Support;

/**
 * The fake RouterOS API server itself; runs in its own process (tests/Support/fake-router.php), driven by FakeRouter.
 *
 * - Listens on 127.0.0.1 with port 0 and prints "PORT <n>" (parallel runs never collide), accepts one client.
 * - Every received sentence is printed as "RECV <json list of base64 words>" (binary safe), the end as "END".
 * - Every reply sentence carries the `.tag` word of the sentence it answers (as its last word), unless the scenario
 *   breaks that on purpose (`wrong-tag`, `no-tag`, `fatal`); a command without `.tag` is answered without one.
 * - The word encoding is written here independently of src/ (an oracle, not a copy of the code under test).
 * - TLS mode: the accepted connection is switched to TLS before the scenario; a failed handshake prints
 *   "TLS FAILED <reason>" and ends the script (nothing is received).
 *
 * See FakeRouter::SCENARIOS for what each scenario does.
 */
final class FakeRouterServer
{
    /** The fake router gives up after this many seconds without a byte from the client. */
    private const READ_TIMEOUT = 30;

    private const PAUSE_MS = 1300;

    private const SPLIT_PAUSE_MS = 300;

    /** Word of 200 bytes: its length 0xC8 is encoded in two bytes, 0x80 0xC8. */
    private const LONG_WORD_BYTES = 191;

    /**
     * RFC 7919 ffdhe2048 (public parameters, not a key). PHP never enables automatic DH parameters for a server, so
     * without them an ADH-only server has no usable cipher (F3d probe f3d-adh-php-server.log).
     */
    private const FFDHE2048 = <<<'PEM'
        -----BEGIN DH PARAMETERS-----
        MIIBCAKCAQEA//////////+t+FRYortKmq/cViAnPTzx2LnFg84tNpWp4TZBFGQz
        +8yTnc4kmz75fS/jY2MMddj2gbICrsRhetPfHtXV/WVhJDP1H18GbtCFY2VVPe0a
        87VXE15/V8k1mE8McODmi3fipona8+/och3xWKE2rec1MKzKT0g6eXq8CrGCsyT7
        YdEIqUuyyOP7uWrat2DX9GgdT0Kj3jlN9K5W7edjcrsZCwenyO4KbXCeAvzhzffi
        7MA0BM0oNC9hkXL+nOmFg/+OTxIy7vKBg8P+OxtMb61zO7X8vC7CIAXFjvGDfRaD
        ssbzSibBsu/6iGtCOGEoXJf//////////wIBAg==
        -----END DH PARAMETERS-----

        PEM;

    /** @var resource|null */
    private $conn;

    /** Anonymous mode: the temp file with the DH parameters (read during the handshake). */
    private ?string $dhParamFile = null;

    /**
     * @param list<string> $cancelReply
     * @param string|null $tls null = plain TCP, 'anonymous' = ADH only, otherwise a PEM file (certificate and key)
     * @param string $legacyChallenge `legacy-login` only: the `=ret=` value of the challenge, as sent
     * @param string $legacyResponse `legacy-login` only: the `=response=` value the client has to answer with
     */
    public function __construct(
        private readonly string $scenario,
        private readonly string $user,
        private readonly string $password,
        private readonly array $cancelReply,
        private readonly bool $doneBeforeCancel,
        private readonly ?string $tls,
        private readonly string $legacyChallenge,
        private readonly string $legacyResponse,
    ) {
    }

    public static function fromArgument(string $argument): self
    {
        $script = json_decode((string) base64_decode($argument, true), true);
        if (
            !is_array($script)
            || !is_string($script['scenario'] ?? null)
            || !is_string($script['user'] ?? null)
            || !is_string($script['password'] ?? null)
            || !is_array($script['cancelReply'] ?? null)
            || !is_bool($script['doneBeforeCancel'] ?? null)
            || !(is_string($script['tls'] ?? null) || ($script['tls'] ?? null) === null)
            || !is_string($script['legacyChallenge'] ?? null)
            || !is_string($script['legacyResponse'] ?? null)
        ) {
            throw new \InvalidArgumentException('usage: php fake-router.php <base64 json script>');
        }
        $cancelReply = [];
        foreach ($script['cancelReply'] as $item) {
            $cancelReply[] = is_string($item) ? $item : throw new \InvalidArgumentException('cancelReply: strings');
        }

        return new self(
            $script['scenario'],
            $script['user'],
            $script['password'],
            $cancelReply,
            $script['doneBeforeCancel'],
            $script['tls'] ?? null,
            $script['legacyChallenge'],
            $script['legacyResponse'],
        );
    }

    public function run(): int
    {
        // the server listens on tcp:// and switches the accepted connection to TLS itself, so that a failed handshake
        // ends the script normally instead of failing inside stream_socket_accept()
        $server = stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            stream_context_create(['ssl' => $this->tlsContext()]),
        );
        if ($server === false) {
            fwrite(STDERR, "listen failed: $errstr\n");

            return 1;
        }
        $name = (string) stream_socket_get_name($server, false);
        $this->out('PORT ' . substr($name, (int) strrpos($name, ':') + 1));

        $conn = stream_socket_accept($server, 20);
        if ($conn === false) {
            fwrite(STDERR, "no client connected\n");

            return 1;
        }
        stream_set_timeout($conn, self::READ_TIMEOUT);
        if ($this->tls !== null) {
            $method = $this->tls === 'anonymous'
                ? STREAM_CRYPTO_METHOD_TLSv1_2_SERVER
                : STREAM_CRYPTO_METHOD_ANY_SERVER;
            $handshake = @stream_socket_enable_crypto($conn, true, $method);
            if ($this->dhParamFile !== null) {
                unlink($this->dhParamFile);
            }
            if ($handshake !== true) {
                $this->out('TLS FAILED ' . (error_get_last()['message'] ?? ''));
                fclose($conn);
                $this->out('END');

                return 0;
            }
        }
        $this->conn = $conn;

        try {
            $this->serve();
        } catch (ClientGone) {
            fwrite(STDERR, "client disconnected before the script ended\n");
        }
        if ($this->conn !== null) {
            fclose($this->conn);
        }
        $this->out('END');

        return 0;
    }

    /**
     * @return array<string, mixed> the `ssl` context options of the server
     */
    private function tlsContext(): array
    {
        if ($this->tls === null) {
            return [];
        }
        if ($this->tls !== 'anonymous') {
            return ['local_cert' => $this->tls];
        }
        $this->dhParamFile = (string) tempnam(sys_get_temp_dir(), 'fake-router-dh');
        file_put_contents($this->dhParamFile, self::FFDHE2048);

        return ['ciphers' => 'ADH:@SECLEVEL=0', 'security_level' => 0, 'dh_param' => $this->dhParamFile];
    }

    private function serve(): void
    {
        $login = $this->receive();
        $tag = self::tagOf($login);

        switch ($this->scenario) {
            case 'bad-login':
                $this->send(
                    $this->sentence(['!trap', '=message=invalid user name or password (6)'], $tag)
                    . $this->sentence(['!done'], $tag),
                );
                $this->drain();

                return;
            case 'legacy-login':
                // pre-6.43 challenge: only the expected MD5 response (given by the script, not computed here) is let in
                $this->send($this->sentence(['!done', '=ret=' . $this->legacyChallenge], $tag));
                $response = $this->receive();
                $expected = ['/login', '=name=' . $this->user, '=response=' . $this->legacyResponse];
                if (self::withoutTag($response) !== $expected) {
                    $this->send(
                        $this->sentence(['!trap', '=message=invalid user name or password (6)'], self::tagOf($response))
                        . $this->sentence(['!done'], self::tagOf($response)),
                    );
                    $this->drain();

                    return;
                }
                $this->send($this->sentence(['!done'], self::tagOf($response)));
                $this->serveOk();

                return;
        }

        if (self::withoutTag($login) !== ['/login', '=name=' . $this->user, '=password=' . $this->password]) {
            $this->send(
                $this->sentence(['!trap', '=message=invalid user name or password (6)'], $tag)
                . $this->sentence(['!done'], $tag),
            );
            $this->drain();

            return;
        }
        if ($this->scenario === 'login-split') {
            // the "!done" sentence of the login without its terminating empty word, which comes 300 ms later
            $done = $this->sentence(['!done'], $tag);
            $this->sendParts($done, [strlen($done) - 1], self::SPLIT_PAUSE_MS);
            $this->serveOk();

            return;
        }
        $this->send($this->sentence(['!done'], $tag));

        $this->scenario();
    }

    private function scenario(): void
    {
        if ($this->scenario === 'echo') {
            while ($this->conn !== null) {
                $this->send($this->sentence(['!done'], self::tagOf($this->receive())));
            }
        }
        if ($this->scenario === 'echo-back') {
            while ($this->conn !== null) {
                $command = $this->receive();
                $attributes = array_values(array_filter(
                    self::withoutTag($command),
                    static fn (string $word): bool => str_starts_with($word, '='),
                ));
                $tag = self::tagOf($command);
                $this->send($this->sentence(['!re', ...$attributes], $tag) . $this->sentence(['!done'], $tag));
            }
        }

        $tag = self::tagOf($this->receive());
        $record = [['!re', '=.id=*1', '=name=late-content'], ['!done']];
        $longRecord = [['!re', '=comment=' . str_repeat('x', self::LONG_WORD_BYTES)], ['!done']];

        switch ($this->scenario) {
            case 'ok':
                $this->send($this->sentences(self::okRows(), $tag));
                break;
            case 'silence':
                $this->drain();

                return;
            case 'silence-in-length':
                $this->send("\x80");
                $this->drain();

                return;
            case 'eof':
                $this->close();

                return;
            case 'eof-mid':
                // 03 "!re" 07 "=." - cut inside the content of the word "=.id=*1"
                $this->send(substr($this->sentence(['!re', '=.id=*1', '=name=cut'], $tag), 0, 7));
                $this->close();

                return;
            case 'eof-in-length':
                $this->send("\x80");
                $this->close();

                return;
            case 'slow-gaps':
                foreach (self::okRows() as $sentence) {
                    usleep(self::PAUSE_MS * 1000);
                    $this->send($this->sentence($sentence, $tag));
                }
                break;
            case 'slow-mid':
                // pauses after "03 !re 07 =." and after "id": both inside the content of "=.id=*1"
                $this->sendParts($this->sentences($record, $tag), [7, 9], self::PAUSE_MS);
                break;
            case 'slow-in-length':
                // pause after "03 !re 80": between the two bytes of the length 0x80 0xC8
                $this->sendParts($this->sentences($longRecord, $tag), [5], self::PAUSE_MS);
                break;
            case 'trickle':
                // one byte every 300 ms for about 5 s, then the rest of the reply
                $this->sendParts($this->sentences(self::okRows(), $tag), range(1, 17), 300);
                break;
            case 'fatal':
                $this->send($this->sentence(['!fatal', 'session terminated on request'], null));
                $this->close();

                return;
            case 'value-eq':
                $this->send($this->sentences([['!re', '=comment=a=b=c', '=empty='], ['!done']], $tag));
                break;
            case 'trap-split':
                $this->send($this->sentence(['!trap', '=message=no such item'], $tag));
                usleep(self::SPLIT_PAUSE_MS * 1000);
                $this->send($this->sentence(['!done'], $tag));
                $second = self::tagOf($this->receive());
                $this->send($this->sentences([['!re', '=.id=*7', '=name=second'], ['!done']], $second));
                break;
            case 'done-ret-split':
                // the "!done" word, 300 ms, then "=ret=*9A" (and the tag) with the end of the sentence
                $done = $this->sentence(['!done', '=ret=*9A'], $tag);
                $this->sendParts($done, [6], self::SPLIT_PAUSE_MS);
                break;
            case 'trap-multi':
                $this->send($this->sentences([
                    ['!trap', '=category=0', '=message=no such command'],
                    ['!trap', '=message=no such command prefix'],
                    ['!done'],
                ], $tag));
                break;
            case 'empty':
                $this->send($this->sentences([['!empty'], ['!done']], $tag));
                break;
            case 'big':
                $rows = [];
                for ($i = 0; $i < 3000; $i++) {
                    $rows[] = ['!re', '=.id=*' . dechex($i), '=name=item' . $i];
                }
                $rows[] = ['!done'];
                $this->send($this->sentences($rows, $tag));
                break;
            case 'wrong-tag':
                $this->send($this->sentences(self::okRows(), '999'));
                break;
            case 'no-tag':
                $this->send($this->sentences(self::okRows(), null));
                break;
            case 'undefined-byte':
                $this->send($this->sentences([['!re', "=comment=a\x81b"], ['!done']], $tag));
                break;
            case 'reserved-length':
                $this->send("\xF8");
                $this->close();

                return;
            case 'listen':
                $this->listen($tag);
                break;
            default:
                throw new \InvalidArgumentException('Unknown scenario: ' . $this->scenario);
        }

        $this->serveOk();
    }

    /**
     * Three `!re` of the listen command S, then waits for `/cancel` (its own tag C, `=tag=S`) and answers it with
     * the sentences of cancelReply in that order, in one write.
     */
    private function listen(?string $listenTag): void
    {
        $events = [];
        for ($i = 1; $i <= 3; $i++) {
            $events[] = ['!re', '=.id=*' . $i, '=name=event' . $i];
        }
        if ($this->doneBeforeCancel) {
            // all events and the end of S arrive before the client can send /cancel
            $this->send($this->sentences([...$events, ['!done']], $listenTag));
        } else {
            foreach ($events as $event) {
                usleep(self::SPLIT_PAUSE_MS * 1000);
                $this->send($this->sentence($event, $listenTag));
            }
        }

        $cancel = $this->receive();
        $cancelTag = self::tagOf($cancel);
        $cancelled = $listenTag;
        foreach ($cancel as $word) {
            if (str_starts_with($word, '=tag=')) {
                $cancelled = substr($word, 5);
            }
        }
        $reply = '';
        foreach ($this->cancelReply as $item) {
            $reply .= match ($item) {
                'trap-s' => $this->sentence(['!trap', '=category=2', '=message=interrupted'], $cancelled),
                'done-s' => $this->sentence(['!done'], $cancelled),
                // the real wording is unknown (L8 records it); the client must not throw it either way
                'trap-c' => $this->sentence(['!trap', '=message=no such command'], $cancelTag),
                'done-c' => $this->sentence(['!done'], $cancelTag),
                default => throw new \InvalidArgumentException('Unknown cancel reply item: ' . $item),
            };
        }
        $this->send($reply);
    }

    /**
     * Answers every further command with the rows of `ok`, until the client disconnects.
     */
    private function serveOk(): void
    {
        while ($this->conn !== null) {
            $this->send($this->sentences(self::okRows(), self::tagOf($this->receive())));
        }
    }

    /**
     * Records every further sentence without answering, until the client disconnects.
     */
    private function drain(): void
    {
        while ($this->conn !== null) {
            $this->receive();
        }
    }

    /**
     * @return list<list<string>>
     */
    private static function okRows(): array
    {
        return [['!re', '=.id=*1', '=name=a'], ['!re', '=.id=*2', '=name=b'], ['!done']];
    }

    /**
     * @param list<string> $sentence
     */
    private static function tagOf(array $sentence): ?string
    {
        foreach ($sentence as $word) {
            if (str_starts_with($word, '.tag=')) {
                return substr($word, 5);
            }
        }

        return null;
    }

    /**
     * @param list<string> $sentence
     * @return list<string>
     */
    private static function withoutTag(array $sentence): array
    {
        return array_values(array_filter($sentence, static fn (string $w): bool => !str_starts_with($w, '.tag=')));
    }

    /**
     * @param int<0, max> $l
     */
    private static function encodeLength(int $l): string
    {
        if ($l < 0x80) {
            return chr($l);
        }
        if ($l < 0x4000) {
            return pack('n', $l | 0x8000);
        }
        if ($l < 0x200000) {
            $v = $l | 0xC00000;

            return chr($v >> 16 & 0xFF) . chr($v >> 8 & 0xFF) . chr($v & 0xFF);
        }
        if ($l < 0x10000000) {
            return pack('N', $l | 0xE0000000);
        }

        return chr(0xF0) . pack('N', $l);
    }

    /**
     * @param list<string> $words
     */
    private function sentence(array $words, ?string $tag): string
    {
        if ($tag !== null) {
            $words[] = '.tag=' . $tag;
        }
        $out = '';
        foreach ($words as $w) {
            $out .= self::encodeLength(strlen($w)) . $w;
        }

        return $out . "\x00";
    }

    /**
     * @param list<list<string>> $sentences
     */
    private function sentences(array $sentences, ?string $tag): string
    {
        return implode('', array_map(fn (array $words): string => $this->sentence($words, $tag), $sentences));
    }

    /**
     * Sends the bytes split at the given offsets, pausing before every part but the first.
     *
     * @param list<int> $cuts
     */
    private function sendParts(string $bytes, array $cuts, int $pauseMs): void
    {
        $offset = 0;
        foreach ([...$cuts, strlen($bytes)] as $i => $cut) {
            if ($i > 0) {
                usleep($pauseMs * 1000);
            }
            $this->send(substr($bytes, $offset, $cut - $offset));
            $offset = $cut;
        }
    }

    private function send(string $bytes): void
    {
        $conn = $this->connection();
        while ($bytes !== '') {
            $written = @fwrite($conn, $bytes);
            if ($written === false || $written === 0) {
                throw new ClientGone();
            }
            $bytes = substr($bytes, $written);
        }
        fflush($conn);
    }

    private function close(): void
    {
        fclose($this->connection());
        $this->conn = null;
    }

    /**
     * @return resource
     */
    private function connection()
    {
        return $this->conn ?? throw new \LogicException('The connection is closed');
    }

    private function readExact(int $n): string
    {
        $conn = $this->connection();
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($conn, max(1, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                if (feof($conn) || stream_get_meta_data($conn)['timed_out']) {
                    throw new ClientGone();
                }
                continue;
            }
            $buf .= $chunk;
        }

        return $buf;
    }

    private function readLength(): int
    {
        $c = ord($this->readExact(1));
        if (($c & 0x80) === 0) {
            return $c;
        }
        $extra = match (true) {
            ($c & 0xC0) === 0x80 => 1,
            ($c & 0xE0) === 0xC0 => 2,
            ($c & 0xF0) === 0xE0 => 3,
            default => 4,
        };
        $value = $extra === 4 ? 0 : $c & (0xFF >> ($extra + 1));
        foreach (str_split($this->readExact($extra)) as $byte) {
            $value = ($value << 8) + ord($byte);
        }

        return $value;
    }

    /**
     * Reads and records one sentence.
     *
     * @return list<string>
     * @throws ClientGone when the client disconnected (or stayed silent for READ_TIMEOUT)
     */
    private function receive(): array
    {
        $words = [];
        while (($length = $this->readLength()) !== 0) {
            $words[] = $this->readExact($length);
        }
        $this->out('RECV ' . json_encode(array_map('base64_encode', $words), JSON_THROW_ON_ERROR));

        return $words;
    }

    private function out(string $line): void
    {
        fwrite(STDOUT, $line . "\n");
        fflush(STDOUT);
    }
}
