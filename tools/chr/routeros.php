<?php

declare(strict_types=1);

/**
 * Minimal RouterOS API client over a raw socket, shared by provision.php and smoke.php.
 * Deliberately independent of the library under test.
 */
final class ChrApi
{
    /** @var resource */
    private $socket;

    private function __construct($socket)
    {
        $this->socket = $socket;
    }

    public static function connect(string $host, int $port, float $timeout): self
    {
        $socket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, $timeout);
        if ($socket === false) {
            throw new RuntimeException("connect to $host:$port failed: $errstr ($errno)");
        }

        return new self($socket);
    }

    public function close(): void
    {
        fclose($this->socket);
    }

    /**
     * Sends one command and reads replies until !done.
     *
     * @param list<string> $words attribute and query words, e.g. "=name=admin"
     * @return array{done: array<string, string>, re: list<array<string, string>>, trap: ?string}
     */
    public function call(string $command, array $words = [], float $timeout = 30.0): array
    {
        $this->write(array_merge([$command], $words));
        $deadline = microtime(true) + $timeout;
        $result = ['done' => [], 're' => [], 'trap' => null];
        while (true) {
            $sentence = $this->readSentence($deadline);
            $type = array_shift($sentence);
            $attributes = self::attributes($sentence);
            switch ($type) {
                case '!re':
                    $result['re'][] = $attributes;
                    break;
                case '!empty':
                    // RouterOS 7.18+: an empty print result, followed by !done.
                    break;
                case '!trap':
                    $result['trap'] ??= $attributes['message'] ?? 'unknown error';
                    break;
                case '!done':
                    $result['done'] = $attributes;

                    return $result;
                case '!fatal':
                    throw new RuntimeException("$command: !fatal " . implode(' ', $sentence));
                default:
                    throw new RuntimeException("$command: unexpected reply type '$type'");
            }
        }
    }

    /**
     * Logs in; returns null on success, the !trap message when the router rejects the credentials.
     */
    public function login(string $user, string $password, float $timeout = 30.0): ?string
    {
        return $this->call('/login', ["=name=$user", "=password=$password"], $timeout)['trap'];
    }

    /**
     * @param list<string> $words
     */
    private function write(array $words): void
    {
        $data = '';
        foreach ($words as $word) {
            $data .= self::encodeLength(strlen($word)) . $word;
        }
        $data .= "\0";
        for ($written = 0; $written < strlen($data); $written += $n) {
            $n = fwrite($this->socket, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException('write to the router failed');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function readSentence(float $deadline): array
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

    private function readLength(float $deadline): int
    {
        $first = ord($this->readBytes(1, $deadline));
        if (($first & 0x80) === 0) {
            return $first;
        }
        if (($first & 0xC0) === 0x80) {
            $extra = 1;
            $length = $first & 0x3F;
        } elseif (($first & 0xE0) === 0xC0) {
            $extra = 2;
            $length = $first & 0x1F;
        } elseif (($first & 0xF0) === 0xE0) {
            $extra = 3;
            $length = $first & 0x0F;
        } elseif ($first === 0xF0) {
            $extra = 4;
            $length = 0;
        } else {
            throw new RuntimeException(sprintf('unsupported length prefix 0x%02X', $first));
        }
        foreach (str_split($this->readBytes($extra, $deadline)) as $byte) {
            $length = ($length << 8) | ord($byte);
        }

        return $length;
    }

    private function readBytes(int $count, float $deadline): string
    {
        $data = '';
        while (strlen($data) < $count) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                throw new RuntimeException('timeout waiting for the router reply');
            }
            stream_set_timeout($this->socket, (int) $left, (int) (fmod($left, 1.0) * 1e6));
            $chunk = fread($this->socket, $count - strlen($data));
            if ($chunk === false || ($chunk === '' && feof($this->socket))) {
                throw new RuntimeException('connection closed by the router');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private static function encodeLength(int $length): string
    {
        return match (true) {
            $length < 0x80 => chr($length),
            $length < 0x4000 => pack('n', $length | 0x8000),
            $length < 0x200000 => substr(pack('N', $length | 0xC00000), 1),
            $length < 0x10000000 => pack('N', $length | 0xE0000000),
            default => "\xF0" . pack('N', $length),
        };
    }

    /**
     * @param list<string> $words
     * @return array<string, string>
     */
    private static function attributes(array $words): array
    {
        $attributes = [];
        foreach ($words as $word) {
            if ($word[0] === '=') {
                $parts = explode('=', substr($word, 1), 2);
                $attributes[$parts[0]] = $parts[1] ?? '';
            }
        }

        return $attributes;
    }
}

function chr_env(string $name, ?string $default = null): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        if ($default === null) {
            fwrite(STDERR, "ERROR: environment variable $name is not set\n");
            exit(1);
        }

        return $default;
    }

    return $value;
}

const CHR_HOST = '127.0.0.1';
const CHR_API_PORT = 18728;
const CHR_SSH_PORT = 18722;
