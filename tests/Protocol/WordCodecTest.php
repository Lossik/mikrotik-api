<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests\Protocol;

use Lossik\DeviceCommunication\ProtocolException;
use Lossik\Mikrotik\Api\Protocol\WordCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WordCodecTest extends TestCase
{
    /**
     * Every boundary of the length prefix forms, with the bytes the RouterOS API defines for it.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function lengthBoundaries(): iterable
    {
        yield '0' => [0, '00'];
        yield '0x7F, longest 1-byte form' => [0x7F, '7F'];
        yield '0x80, shortest 2-byte form' => [0x80, '8080'];
        yield '0x3FFF, longest 2-byte form' => [0x3FFF, 'BFFF'];
        yield '0x4000, shortest 3-byte form' => [0x4000, 'C04000'];
        yield '0x1FFFFF, longest 3-byte form' => [0x1FFFFF, 'DFFFFF'];
        yield '0x200000, shortest 4-byte form' => [0x200000, 'E0200000'];
        yield '0xFFFFFFF, longest 4-byte form' => [0xFFFFFFF, 'EFFFFFFF'];
        yield '0x10000000, 5-byte form' => [0x10000000, 'F010000000'];
    }

    #[DataProvider('lengthBoundaries')]
    public function testLengthIsEncodedAsTheProtocolDefinesAndDecodedBack(int $length, string $hex): void
    {
        $prefix = (string) hex2bin($hex);

        self::assertSame($hex, strtoupper(bin2hex(WordCodec::encodeLength($length))));
        self::assertSame(strlen($prefix) - 1, WordCodec::followingLengthBytes(ord($prefix[0])));
        self::assertSame($length, WordCodec::decodeLength($prefix));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function controlBytes(): iterable
    {
        yield '0xF8, lowest control byte' => [0xF8];
        yield '0xFF' => [0xFF];
    }

    #[DataProvider('controlBytes')]
    public function testControlByteInPlaceOfLengthIsRejected(int $firstByte): void
    {
        $this->expectException(ProtocolException::class);

        WordCodec::followingLengthBytes($firstByte);
    }
}
