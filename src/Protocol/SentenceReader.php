<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Protocol;

use Lossik\DeviceCommunication\ConnectionException;
use Lossik\DeviceCommunication\ProtocolException;
use Lossik\DeviceCommunication\TimeoutException;

/**
 * Reads API sentences from a stream (TCP, or TLS - only the stream differs).
 *
 * The stream is read with `fread` in short ticks (`stream_set_timeout` of at most one second, or less when a deadline
 * is closer), so a time limit never waits for a long blocking read. Received bytes stay in a buffer until a whole
 * sentence is there: a word or a length prefix read in part survives any number of ticks. The end of a sentence is its
 * empty word, never an empty buffer. After every read `feof()` decides whether the peer closed the connection, also
 * in the middle of a word or a length. The idle limit is measured by the clock from the last received byte; a read
 * that times out is only a tick.
 */
final class SentenceReader
{
    /** The longest single wait in `fread`, in seconds. */
    private const TICK = 1.0;

    private const CHUNK = 65536;

    private string $buffer = '';

    /** Where the next sentence starts in the buffer. */
    private int $offset = 0;

    private float $lastByteAt;

    /**
     * @param resource $stream a blocking stream
     */
    public function __construct(private $stream)
    {
        $this->lastByteAt = self::now();
    }

    /**
     * A monotonic clock in seconds.
     */
    public static function now(): float
    {
        return hrtime(true) / 1e9;
    }

    /**
     * Starts the idle clock anew; called when a command was sent (the silence before the first byte of its response
     * counts as idle).
     */
    public function restartIdleClock(): void
    {
        $this->lastByteAt = self::now();
    }

    /**
     * @param float $idleTimeout the longest silence since the last received byte (or the last restartIdleClock())
     * @param float|null $responseDeadline the time (by now()) by which the sentence must be complete; null = none
     * @return list<string> the words of the next sentence, without its terminating empty word
     * @throws ProtocolException a control byte (0xF8 and above) in place of a word length
     * @throws TimeoutException the idle or the response limit ran out ('idleTimeout', 'responseTimeout')
     * @throws ConnectionException the peer closed the connection
     */
    public function readSentence(float $idleTimeout, ?float $responseDeadline): array
    {
        while (true) {
            $sentence = $this->takeSentence();
            if ($sentence !== null) {
                return $sentence;
            }
            if (feof($this->stream)) {
                throw new ConnectionException('The device closed the connection');
            }
            $now = self::now();
            if ($responseDeadline !== null && $now >= $responseDeadline) {
                throw new TimeoutException('The response did not complete within responseTimeout', 'responseTimeout');
            }
            $idleDeadline = $this->lastByteAt + $idleTimeout;
            if ($now >= $idleDeadline) {
                throw new TimeoutException(
                    sprintf('No data from the device for %.1f s (idleTimeout)', $now - $this->lastByteAt),
                    'idleTimeout',
                );
            }
            $this->readChunk(min(self::TICK, $idleDeadline - $now, ($responseDeadline ?? INF) - $now));
        }
    }

    /**
     * Reads whatever arrives within one tick (possibly nothing) into the buffer.
     */
    private function readChunk(float $tick): void
    {
        $seconds = (int) floor($tick);
        $microseconds = max(1000, (int) (($tick - $seconds) * 1e6));
        stream_set_timeout($this->stream, $seconds, $microseconds);
        $chunk = @fread($this->stream, self::CHUNK);
        // false (a failed read) is handled like an empty read: feof() or a deadline ends the loop
        if (is_string($chunk) && $chunk !== '') {
            if ($this->offset > 0) {
                $this->buffer = substr($this->buffer, $this->offset);
                $this->offset = 0;
            }
            $this->buffer .= $chunk;
            $this->lastByteAt = self::now();
        }
    }

    /**
     * The next complete sentence from the buffer (consumed), or null when the buffer does not hold one yet.
     *
     * @return list<string>|null
     * @throws ProtocolException
     */
    private function takeSentence(): ?array
    {
        $buffer = $this->buffer;
        $size = strlen($buffer);
        $pos = $this->offset;
        $words = [];
        while ($pos < $size) {
            $following = WordCodec::followingLengthBytes(ord($buffer[$pos]));
            if ($pos + 1 + $following > $size) {
                return null;
            }
            $length = WordCodec::decodeLength(substr($buffer, $pos, 1 + $following));
            $pos += 1 + $following;
            if ($length === 0) {
                $this->offset = $pos;

                return $words;
            }
            if ($pos + $length > $size) {
                return null;
            }
            $words[] = substr($buffer, $pos, $length);
            $pos += $length;
        }

        return null;
    }
}
