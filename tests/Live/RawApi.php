<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests\Live;

/**
 * A bare RouterOS API connection for the live tests: it shows the sentences exactly as the router sends them (tags,
 * `!empty`, raw bytes) and does not depend on the library under test, so it also does the set-up, the clean-up and
 * the triggers (reboot, changes seen by `listen`) that must work whatever the library does. Not a client: no limits
 * beyond the per-sentence timeout, no charset conversion, every word is a byte string.
 */
final class RawApi
{
    private int $lastTag = 0;

    /**
     * @param resource $socket
     */
    private function __construct(private $socket)
    {
    }

    /**
     * @throws \RuntimeException the connection or the login failed
     */
    public static function connect(Env $env): self
    {
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $env->host, $env->apiPort),
            $errno,
            $errstr,
            10.0,
        );
        if ($socket === false) {
            throw new \RuntimeException(sprintf('Raw connection to the router failed: %s (%d)', $errstr, $errno));
        }
        $api = new self($socket);
        $reply = $api->call('/login', ['=name=' . $env->user, '=password=' . $env->password]);
        if (self::type($reply[0] ?? []) !== '!done') {
            throw new \RuntimeException('Raw login failed: ' . json_encode($reply));
        }

        return $api;
    }

    public function close(): void
    {
        @fclose($this->socket);
    }

    /**
     * Sends a command with a new `.tag` and reads the sentences up to its `!done` (or a `!fatal`).
     *
     * @param list<string> $words the attribute and query words
     * @return list<list<string>> the sentences, each a list of its words (the type word first)
     * @throws \RuntimeException no reply within $timeout, or the router closed the connection
     */
    public function call(string $command, array $words = [], float $timeout = 10.0): array
    {
        $this->send($command, $words);
        $deadline = microtime(true) + $timeout;
        $sentences = [];
        do {
            $sentence = $this->readSentence($deadline);
            $sentences[] = $sentence;
        } while (!in_array(self::type($sentence), ['!done', '!fatal'], true));

        return $sentences;
    }

    /**
     * Like call(), but returns the attributes of the `!re` sentences and fails on a `!trap`.
     *
     * @param list<string> $words
     * @return list<array<string, string>>
     */
    public function rows(string $command, array $words = []): array
    {
        $rows = [];
        foreach ($this->call($command, $words) as $sentence) {
            $type = self::type($sentence);
            if ($type === '!trap') {
                throw new \RuntimeException($command . ': ' . json_encode($sentence));
            }
            if ($type === '!re') {
                $rows[] = self::attributes($sentence);
            }
        }

        return $rows;
    }

    /**
     * Sends a command with a new `.tag` without reading the reply.
     *
     * @param list<string> $words
     * @return string the `.tag` of the command
     */
    public function send(string $command, array $words = []): string
    {
        $tag = (string) ++$this->lastTag;
        $bytes = '';
        foreach ([$command, ...$words, '.tag=' . $tag] as $word) {
            $bytes .= self::encodeLength(strlen($word)) . $word;
        }
        $bytes .= "\x00";
        for ($offset = 0; $offset < strlen($bytes); $offset += $written) {
            $written = fwrite($this->socket, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Writing to the router failed');
            }
        }

        return $tag;
    }

    /**
     * @return list<string> the words of the next sentence
     * @throws \RuntimeException the deadline passed, or the router closed the connection
     */
    public function readSentence(float $deadline): array
    {
        $words = [];
        while (true) {
            $length = $this->readLength($deadline);
            if ($length === 0) {
                if ($words === []) {
                    continue;
                }

                return $words;
            }
            $words[] = $this->readBytes($length, $deadline);
        }
    }

    /**
     * @param list<string> $sentence
     */
    public static function type(array $sentence): string
    {
        return $sentence[0] ?? '';
    }

    /**
     * The `.tag` of a sentence; null = it has none.
     *
     * @param list<string> $sentence
     */
    public static function tagOf(array $sentence): ?string
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
     * @return array<string, string>
     */
    public static function attributes(array $sentence): array
    {
        $attributes = [];
        foreach ($sentence as $word) {
            if (str_starts_with($word, '=')) {
                $pair = explode('=', substr($word, 1), 2);
                $attributes[$pair[0]] = $pair[1] ?? '';
            }
        }

        return $attributes;
    }

    private function readLength(float $deadline): int
    {
        $first = ord($this->readBytes(1, $deadline));
        if ($first < 0x80) {
            return $first;
        }
        if (($first & 0xC0) === 0x80) {
            return (($first & 0x3F) << 8) | ord($this->readBytes(1, $deadline));
        }
        if (($first & 0xE0) === 0xC0) {
            $rest = $this->readBytes(2, $deadline);

            return (($first & 0x1F) << 16) | (ord($rest[0]) << 8) | ord($rest[1]);
        }

        throw new \RuntimeException(sprintf('The router sent a word longer than 2 MiB (length byte 0x%02X)', $first));
    }

    private function readBytes(int $count, float $deadline): string
    {
        $data = '';
        while (strlen($data) < $count) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                throw new \RuntimeException('The router did not reply in time');
            }
            stream_set_timeout($this->socket, (int) $left, (int) (fmod($left, 1.0) * 1e6));
            $chunk = fread($this->socket, max(1, $count - strlen($data)));
            if ($chunk === false || ($chunk === '' && feof($this->socket) && !self::timedOut($this->socket))) {
                throw new \RuntimeException('The router closed the connection');
            }
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * @param resource $socket
     */
    private static function timedOut($socket): bool
    {
        return stream_get_meta_data($socket)['timed_out'];
    }

    private static function encodeLength(int $length): string
    {
        return match (true) {
            $length < 0x80 => pack('C', $length),
            $length < 0x4000 => pack('n', $length | 0x8000),
            $length < 0x200000 => substr(pack('N', $length | 0xC00000), 1),
            default => throw new \InvalidArgumentException('A word longer than 2 MiB'),
        };
    }
}
