<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api;

use Lossik\DeviceCommunication\AuthenticationException;
use Lossik\DeviceCommunication\Command;
use Lossik\DeviceCommunication\CommunicationException;
use Lossik\DeviceCommunication\ConnectionException;
use Lossik\DeviceCommunication\FatalException;
use Lossik\DeviceCommunication\ProtocolException;
use Lossik\DeviceCommunication\Result;
use Lossik\DeviceCommunication\TimeoutException;
use Lossik\DeviceCommunication\Transport;
use Lossik\DeviceCommunication\TrapException;
use Lossik\Mikrotik\Api\Protocol\QueryCompiler;
use Lossik\Mikrotik\Api\Protocol\Reply;
use Lossik\Mikrotik\Api\Protocol\SentenceReader;
use Lossik\Mikrotik\Api\Protocol\WordCodec;

/**
 * The RouterOS API (binary protocol over TCP) as a Transport.
 *
 *     $transport = ApiTransport::connect('10.0.0.1', 'admin', 'secret', new ApiOptions(idleTimeout: 120));
 *
 * Every command carries its own `.tag`; its response is read sentence by sentence up to `!done`. A `!trap` is thrown
 * as TrapException only after `!done`, so the connection stays usable. Any ConnectionException (EOF, a time limit,
 * `!fatal`, a protocol error) closes the transport; every further call then throws ConnectionException without I/O.
 * Only the post-6.43 login is supported (RouterOS 6.43 and newer).
 */
final class ApiTransport implements Transport
{
    /** @var resource|null */
    private $stream;

    private ?SentenceReader $reader;

    private int $lastTag = 0;

    private readonly bool $convertCharset;

    /**
     * @param resource $stream
     */
    private function __construct($stream, private readonly ApiOptions $options)
    {
        $this->stream = $stream;
        $this->reader = new SentenceReader($stream);
        $this->convertCharset = strcasecmp($options->charset, 'UTF-8') !== 0;
    }

    /**
     * Opens the connection and logs in.
     *
     * @throws TimeoutException the connection was not opened within connectTimeout ('connectTimeout'), or the login
     *                          response ran out of idleTimeout / responseTimeout
     * @throws ConnectionException the device is unreachable or closed the connection
     * @throws AuthenticationException wrong user name or password, or the device wants the legacy MD5 login of
     *                                 RouterOS before 6.43 (not supported; no MD5 response is ever sent)
     * @throws CommunicationException
     */
    public static function connect(
        string $host,
        string $user,
        string $password,
        ApiOptions $options = new ApiOptions(),
    ): self {
        $address = sprintf(
            'tcp://%s:%d',
            str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host,
            $options->port,
        );
        $started = SentenceReader::now();
        $stream = @stream_socket_client($address, $errno, $errstr, $options->connectTimeout);
        if ($stream === false) {
            $message = sprintf('Cannot connect to %s: %s (%d)', $address, $errstr, $errno);
            if (SentenceReader::now() - $started >= $options->connectTimeout) {
                throw new TimeoutException($message, 'connectTimeout');
            }
            throw new ConnectionException($message);
        }

        $transport = new self($stream, $options);
        try {
            $transport->login($user, $password);
        } catch (\Throwable $e) {
            $transport->close();
            throw $e;
        }

        return $transport;
    }

    /**
     * @throws TrapException the device refused the command (the transport stays open)
     * @throws ConnectionException the transport is closed, or got closed by this call (EOF, time limit, `!fatal`,
     *                             protocol error)
     * @throws \InvalidArgumentException a word cannot be converted to the configured charset (nothing was sent)
     */
    public function execute(Command $command): Result
    {
        $words = [rtrim($command->menu, '/') . '/' . $command->verb];
        foreach ($command->attributes as $key => $value) {
            $words[] = '=' . $key . '=' . $value;
        }
        if ($command->proplist !== []) {
            $words[] = '.proplist=' . implode(',', $command->proplist);
        }
        if ($command->query !== null) {
            array_push($words, ...QueryCompiler::compile($command->query));
        }

        return $this->exchange($words);
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
        }
        $this->stream = null;
        $this->reader = null;
    }

    public function isOpen(): bool
    {
        return $this->stream !== null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @throws CommunicationException
     */
    private function login(string $user, string $password): void
    {
        try {
            $result = $this->exchange(['/login', '=name=' . $user, '=password=' . $password]);
        } catch (TrapException $e) {
            throw new AuthenticationException($e->getMessage(), 0, $e);
        }
        if ($result->ret !== null) {
            throw new AuthenticationException(
                'The device requires the legacy MD5 login of RouterOS before 6.43, which is not supported',
            );
        }
    }

    /**
     * Sends one command (with a new `.tag`) and reads its response up to `!done`.
     *
     * @param list<string> $words the command without `.tag`
     * @throws TrapException
     * @throws ConnectionException
     */
    private function exchange(array $words): Result
    {
        $stream = $this->stream;
        $reader = $this->reader;
        if ($stream === null || $reader === null) {
            throw new ConnectionException('The transport is closed');
        }
        $tag = (string) ++$this->lastTag;
        $bytes = '';
        foreach ([...$words, '.tag=' . $tag] as $word) {
            $bytes .= WordCodec::encodeWord($this->toDevice($word));
        }
        $bytes .= "\x00";

        try {
            $this->write($stream, $bytes);
            $reader->restartIdleClock();

            return $this->readResponse($reader, $tag);
        } catch (ConnectionException $e) {
            $this->close();
            throw $e;
        }
    }

    /**
     * @throws TrapException
     * @throws ConnectionException
     */
    private function readResponse(SentenceReader $reader, string $tag): Result
    {
        $responseDeadline = $this->options->responseTimeout === null
            ? null
            : SentenceReader::now() + $this->options->responseTimeout;
        $rows = [];
        $traps = [];
        while (true) {
            $words = array_map(
                fn (string $word): string => $this->fromDevice($word),
                $reader->readSentence($this->options->idleTimeout, $responseDeadline),
            );
            $reply = Reply::fromWords($words);
            $this->checkTag($reply, $tag);
            switch ($reply->type) {
                case '!re':
                    $rows[] = $reply->attributes;
                    break;
                case '!trap':
                    $traps[] = $reply->attributes;
                    break;
                case '!empty':
                    break;
                case '!done':
                    if ($traps !== []) {
                        $category = $traps[0]['category'] ?? null;
                        throw new TrapException(
                            $traps[0]['message'] ?? 'The device refused the command',
                            $category !== null && ctype_digit($category) ? (int) $category : null,
                            $traps,
                        );
                    }

                    return new Result($rows, $reply->attributes['ret'] ?? null);
                case '!fatal':
                    $reason = $reply->otherWords[0] ?? $reply->attributes['message'] ?? '';
                    throw new FatalException($reason !== '' ? $reason : 'The device ended the session', $reason);
                default:
                    throw new ProtocolException('Unknown reply sentence type ' . $reply->type);
            }
        }
    }

    /**
     * The one place that decides whether a reply belongs to the command: its `.tag` must be the command's tag.
     * `!fatal` is taken whatever its tag (the device closes the connection after it). A missing tag counts as a
     * foreign one. Whether RouterOS tags every reply (`!empty`, `!trap`, `!re`) is to be measured on CHR (risk U20).
     *
     * @throws ProtocolException
     */
    private function checkTag(Reply $reply, string $tag): void
    {
        if ($reply->type !== '!fatal' && $reply->tag !== $tag) {
            throw new ProtocolException(sprintf(
                'A reply with .tag %s to the command with .tag %s',
                $reply->tag ?? '(none)',
                $tag,
            ));
        }
    }

    /**
     * @param resource $stream
     * @throws ConnectionException
     */
    private function write($stream, string $bytes): void
    {
        $timeout = $this->options->idleTimeout;
        stream_set_timeout($stream, (int) floor($timeout), (int) (($timeout - floor($timeout)) * 1e6));
        while ($bytes !== '') {
            $written = @fwrite($stream, $bytes);
            if ($written === false || $written === 0) {
                throw new ConnectionException('Writing to the device failed');
            }
            $bytes = substr($bytes, $written);
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function toDevice(string $word): string
    {
        if (!$this->convertCharset) {
            return $word;
        }
        $converted = @iconv('UTF-8', $this->options->charset, $word);
        if ($converted === false) {
            throw new \InvalidArgumentException(
                sprintf('A word of the command cannot be converted from UTF-8 to %s', $this->options->charset),
            );
        }

        return $converted;
    }

    /**
     * @throws ProtocolException
     */
    private function fromDevice(string $word): string
    {
        if (!$this->convertCharset) {
            return $word;
        }
        $converted = @iconv($this->options->charset, 'UTF-8', $word);
        if ($converted === false) {
            throw new ProtocolException(sprintf('A word from the device is not valid %s', $this->options->charset));
        }

        return $converted;
    }
}
