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
use Lossik\DeviceCommunication\StreamingTransport;
use Lossik\DeviceCommunication\TimeoutException;
use Lossik\DeviceCommunication\TrapException;
use Lossik\Mikrotik\Api\Protocol\QueryCompiler;
use Lossik\Mikrotik\Api\Protocol\Reply;
use Lossik\Mikrotik\Api\Protocol\SentenceReader;
use Lossik\Mikrotik\Api\Protocol\WordCodec;

/**
 * The RouterOS API (binary protocol over TCP, or over TLS with ApiOptions::$tls) as a Transport.
 *
 *     $transport = ApiTransport::connect('10.0.0.1', 'admin', 'secret', new ApiOptions(idleTimeout: 120));
 *
 * Every command carries its own `.tag`; its response is read sentence by sentence up to `!done`. A `!trap` is thrown
 * as TrapException only after `!done`, so the connection stays usable. Any ConnectionException (EOF, a time limit,
 * `!fatal`, a protocol error) closes the transport; every further call then throws ConnectionException without I/O.
 * stream() delivers the rows of a long-running command (`listen`) as they arrive and cancels it with `/cancel`.
 * Both logins are supported: the post-6.43 one, and the legacy MD5 challenge login of older RouterOS (implemented
 * by the documented scheme and tested against the fake router only; not yet run against a real RouterOS before 6.43).
 */
final class ApiTransport implements StreamingTransport
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
     * @throws ConnectionException the device is unreachable or closed the connection, or the TLS handshake failed
     *                             (the certificate or its fingerprint did not verify, no common cipher)
     * @throws AuthenticationException wrong user name or password (also at the legacy MD5 login)
     * @throws ProtocolException the legacy login challenge of the device is malformed
     * @throws CommunicationException
     */
    public static function connect(
        string $host,
        string $user,
        string $password,
        ApiOptions $options = new ApiOptions(),
    ): self {
        $tls = $options->tls;
        $address = sprintf(
            '%s://%s:%d',
            $tls === null ? 'tcp' : 'ssl',
            str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host,
            $options->effectivePort(),
        );
        $context = stream_context_create($tls === null ? [] : ['ssl' => $tls->contextOptions($host)]);
        $started = SentenceReader::now();
        // a failed TLS handshake leaves errno 0 and no errstr; its reason is only in the warnings
        $warnings = [];
        set_error_handler(static function (int $level, string $warning) use (&$warnings): bool {
            $warnings[] = $warning;

            return true;
        }, E_WARNING);
        try {
            $stream = stream_socket_client(
                $address,
                $errno,
                $errstr,
                $options->connectTimeout,
                STREAM_CLIENT_CONNECT,
                $context,
            );
        } finally {
            restore_error_handler();
        }
        if ($stream === false) {
            $message = sprintf(
                'Cannot connect to %s: %s',
                $address,
                implode('; ', array_filter([$errstr !== '' ? sprintf('%s (%d)', $errstr, $errno) : '', ...$warnings])),
            );
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
        return $this->exchange(self::words($command));
    }

    /**
     * Runs a long-running command (`listen`, `monitor`, …) and hands every row to $onRow as it arrives.
     *
     * The stream ends when the device finishes the command (`!done`). When $onRow returns false, or throws, the
     * command is cancelled: `/cancel` with its own `.tag` is sent, no further row reaches $onRow, and the replies
     * are read until both the command and `/cancel` are done (in any order, within cancelTimeout). The transport is
     * then usable again; an exception of $onRow is rethrown after that. The `!trap` of the command that reports
     * the cancel (`interrupted`) and a `!trap` of `/cancel` itself (the command had already finished) are not
     * errors. No other command may run on this transport while it streams (there is no keepalive either).
     *
     * @param callable(array<string, string>): bool $onRow returns false = cancel
     * @param float|null $idleTimeout the longest silence of the device while streaming; null = no limit
     * @throws TrapException the device refused the command (the transport stays open)
     * @throws TimeoutException idleTimeout ran out while streaming ('idleTimeout'), or the device did not finish
     *                          the cancelled command within cancelTimeout ('cancelTimeout'); the transport is closed
     * @throws ConnectionException the transport is closed, or got closed by this call (EOF, `!fatal`, protocol
     *                             error)
     * @throws \InvalidArgumentException a word cannot be converted to the configured charset (nothing was sent)
     */
    public function stream(Command $command, callable $onRow, ?float $idleTimeout = null): void
    {
        [$reader, $tag] = $this->send(self::words($command));
        try {
            $callbackError = $this->readStream($reader, $tag, $onRow, $idleTimeout);
        } catch (ConnectionException $e) {
            $this->close();
            throw $e;
        }
        if ($callbackError !== null) {
            throw $callbackError;
        }
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
     * Logs in with `/login =name= =password=` (RouterOS 6.43 and newer). A reply that carries `=ret=<32 hex>` is the
     * challenge of the legacy login of older RouterOS, which is answered with `/login =name= =response=00<md5>`,
     * md5 over a zero byte, the password and the decoded challenge. The exchange (`=ret=` of 32 hex digits, then
     * `=response=00` and 32 hex digits) is shown in the examples of the API manual,
     * https://help.mikrotik.com/docs/spaces/ROS/pages/47579160/API ("Initial login"); the formula comes from the former
     * MikroTik wiki page Manual:API, which the manual no longer spells out.
     * The first sentence already carries the password in clear (a router that answers with a challenge ignores it),
     * so plain TCP shows it to the network even when the response is sent; use TLS for any router that is not on a
     * trusted segment.
     *
     * @throws AuthenticationException the device refused the user name or the password
     * @throws ProtocolException the challenge is not 32 hexadecimal digits
     * @throws CommunicationException
     */
    private function login(string $user, string $password): void
    {
        try {
            $challenge = $this->exchange(['/login', '=name=' . $user, '=password=' . $password])->ret;
            if ($challenge === null) {
                return;
            }
            if (preg_match('/^[0-9a-f]{32}$/i', $challenge) !== 1) {
                throw new ProtocolException('The login challenge of the device is not 32 hexadecimal digits');
            }
            // the digest covers the password as the router receives it, i.e. after the charset conversion
            $digest = md5("\x00" . $this->toDevice($password) . (string) hex2bin($challenge));
            $this->exchange(['/login', '=name=' . $user, '=response=00' . $digest]);
        } catch (TrapException $e) {
            throw new AuthenticationException($e->getMessage(), 0, $e);
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
        [$reader, $tag] = $this->send($words);
        try {
            return $this->readResponse($reader, $tag);
        } catch (ConnectionException $e) {
            $this->close();
            throw $e;
        }
    }

    /**
     * Sends one command with a new `.tag` and starts the idle clock.
     *
     * @param list<string> $words the command without `.tag`
     * @return array{SentenceReader, string} the reader and the tag of the command
     * @throws ConnectionException the transport is closed, or writing failed (then it is closed)
     * @throws \InvalidArgumentException a word cannot be converted to the configured charset (nothing was sent)
     */
    private function send(array $words): array
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
        } catch (ConnectionException $e) {
            $this->close();
            throw $e;
        }
        $reader->restartIdleClock();

        return [$reader, $tag];
    }

    /**
     * Reads the replies of the streamed command $tag; cancels it when $onRow returns false or throws.
     *
     * @param callable(array<string, string>): bool $onRow
     * @return \Throwable|null what $onRow threw (the command was then cancelled and drained)
     * @throws TrapException
     * @throws ConnectionException
     */
    private function readStream(SentenceReader $reader, string $tag, callable $onRow, ?float $idleTimeout): ?\Throwable
    {
        $traps = [];
        $callbackError = null;
        // set once /cancel is sent: its tag, and the time by which both commands must be done
        $cancelTag = null;
        $cancelDeadline = null;
        $commandDone = false;
        $cancelDone = false;
        while (true) {
            $words = array_map(
                fn (string $word): string => $this->fromDevice($word),
                $cancelTag === null
                    ? $reader->readSentence($idleTimeout, null)
                    : $reader->readSentence(null, $cancelDeadline, 'cancelTimeout'),
            );
            $reply = Reply::fromWords($words);
            if ($cancelTag !== null && $reply->tag === $cancelTag && $reply->type !== '!fatal') {
                // the replies of /cancel: a !trap means the command had already finished; only its !done matters
                if ($reply->type === '!done') {
                    $cancelDone = true;
                }
            } else {
                $this->checkTag($reply, $tag);
                switch ($reply->type) {
                    case '!re':
                        if ($cancelTag !== null) {
                            break;
                        }
                        try {
                            $continue = $onRow($reply->attributes) !== false;
                        } catch (\Throwable $e) {
                            $callbackError = $e;
                            $continue = false;
                        }
                        if (!$continue) {
                            [, $cancelTag] = $this->send(['/cancel', '=tag=' . $tag]);
                            $cancelDeadline = SentenceReader::now() + $this->options->cancelTimeout;
                        }
                        break;
                    case '!trap':
                        if ($cancelTag === null || ($reply->attributes['message'] ?? null) !== 'interrupted') {
                            $traps[] = $reply->attributes;
                        }
                        break;
                    case '!empty':
                        break;
                    case '!done':
                        $commandDone = true;
                        break;
                    case '!fatal':
                        throw self::fatal($reply);
                    default:
                        throw new ProtocolException('Unknown reply sentence type ' . $reply->type);
                }
            }
            if ($commandDone && ($cancelTag === null || $cancelDone)) {
                if ($traps !== [] && $callbackError === null) {
                    throw self::trap($traps);
                }

                return $callbackError;
            }
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
                        throw self::trap($traps);
                    }

                    return new Result($rows, $reply->attributes['ret'] ?? null);
                case '!fatal':
                    throw self::fatal($reply);
                default:
                    throw new ProtocolException('Unknown reply sentence type ' . $reply->type);
            }
        }
    }

    /**
     * @param non-empty-list<array<string, string>> $traps the attributes of every `!trap` of the response
     */
    private static function trap(array $traps): TrapException
    {
        $category = $traps[0]['category'] ?? null;

        return new TrapException(
            $traps[0]['message'] ?? 'The device refused the command',
            $category !== null && ctype_digit($category) ? (int) $category : null,
            $traps,
        );
    }

    private static function fatal(Reply $reply): FatalException
    {
        $reason = $reply->otherWords[0] ?? $reply->attributes['message'] ?? '';

        return new FatalException($reason !== '' ? $reason : 'The device ended the session', $reason);
    }

    /**
     * The words of a command, without `.tag`.
     *
     * @return list<string>
     */
    private static function words(Command $command): array
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

        return $words;
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
