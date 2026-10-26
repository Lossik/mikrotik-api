<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Protocol;

use Lossik\DeviceCommunication\ProtocolException;

/**
 * Word encoding of the RouterOS API: every word is a length prefix of 1 to 5 bytes (network byte order) followed by
 * the word's bytes; an empty word (length 0) ends a sentence.
 *
 * | length                  | prefix                          |
 * |-------------------------|---------------------------------|
 * | 0x00 .. 0x7F            | 1 byte  `0xxxxxxx`              |
 * | 0x80 .. 0x3FFF          | 2 bytes `10xxxxxx` + 1 byte     |
 * | 0x4000 .. 0x1FFFFF      | 3 bytes `110xxxxx` + 2 bytes    |
 * | 0x200000 .. 0xFFFFFFF   | 4 bytes `1110xxxx` + 3 bytes    |
 * | 0x10000000 .. 2^32 - 1  | 5 bytes `11110xxx` + 4 bytes    |
 *
 * A first byte of 0xF8 or more is a control byte; the API protocol reserves them and this client does not support
 * them.
 */
final class WordCodec
{
    private const MAX_LENGTH = 0xFFFFFFFF;

    /**
     * The word as sent: its length prefix and its bytes, unchanged (no trimming, no splitting at line breaks).
     */
    public static function encodeWord(string $word): string
    {
        return self::encodeLength(strlen($word)) . $word;
    }

    /**
     * @throws \InvalidArgumentException when the length cannot be encoded (negative or over 2^32 - 1)
     */
    public static function encodeLength(int $length): string
    {
        if ($length < 0 || $length > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf('A word length must be 0 to 0xFFFFFFFF, got %d', $length));
        }
        if ($length < 0x80) {
            return chr($length);
        }
        if ($length < 0x4000) {
            return pack('n', $length | 0x8000);
        }
        if ($length < 0x200000) {
            return substr(pack('N', $length | 0xC00000), 1);
        }
        if ($length < 0x10000000) {
            return pack('N', $length | 0xE0000000);
        }

        return "\xF0" . pack('N', $length);
    }

    /**
     * How many bytes of the length prefix follow its first byte (0 to 4). A reader that receives the prefix in
     * pieces reads this many more bytes and then calls decodeLength() with the whole prefix.
     *
     * @param int $firstByte the first byte of the prefix (0 to 255)
     *
     * @throws ProtocolException for a control byte (0xF8 and above)
     */
    public static function followingLengthBytes(int $firstByte): int
    {
        if ($firstByte < 0 || $firstByte > 0xFF) {
            throw new \InvalidArgumentException(sprintf('A byte must be 0 to 255, got %d', $firstByte));
        }

        return match (true) {
            $firstByte < 0x80 => 0,
            $firstByte < 0xC0 => 1,
            $firstByte < 0xE0 => 2,
            $firstByte < 0xF0 => 3,
            $firstByte < 0xF8 => 4,
            default => throw new ProtocolException(
                sprintf('Unsupported control byte 0x%02X in place of a word length', $firstByte),
            ),
        };
    }

    /**
     * The word length encoded by a complete prefix (its first byte and all the bytes that follow it).
     *
     * @throws ProtocolException for a control byte (0xF8 and above)
     * @throws \InvalidArgumentException when the number of bytes does not match the first byte
     */
    public static function decodeLength(string $prefix): int
    {
        if ($prefix === '') {
            throw new \InvalidArgumentException('A length prefix has at least one byte');
        }
        $first = ord($prefix[0]);
        $following = self::followingLengthBytes($first);
        if (strlen($prefix) !== $following + 1) {
            throw new \InvalidArgumentException(sprintf(
                'A length prefix starting with 0x%02X has %d bytes, got %d',
                $first,
                $following + 1,
                strlen($prefix),
            ));
        }
        if ($following === 4) {
            $first = 0;
        } else {
            $first &= 0x7F >> $following;
        }
        $length = $first;
        for ($i = 1; $i <= $following; $i++) {
            $length = ($length << 8) | ord($prefix[$i]);
        }

        return $length;
    }
}
