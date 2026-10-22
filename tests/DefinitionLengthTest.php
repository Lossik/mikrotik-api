<?php declare(strict_types = 1);

namespace Lossik\Device\Mikrotik\Api\Tests;

use Lossik\Device\Mikrotik\Api\Definition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The word length prefix (1 to 5 bytes) read from a finite stream, whole and cut after each of its bytes.
 *
 * A connection closed inside the prefix counts every missing byte as 0, exactly as ord('') did before PHP 8.5
 * deprecated it. The deprecation itself fails the test through failOnDeprecation in phpunit.xml.dist, so on PHP 8.5
 * (the only version where it exists) a read of a missing byte that bypasses the helper is caught here, one prefix
 * shape at a time, without the endless read loop of a whole client (see EofDeprecationTest).
 */
final class DefinitionLengthTest extends TestCase
{

	/**
	 * Expected lengths follow the RouterOS API encoding (big-endian, the leading bits of the first byte say how
	 * many bytes follow); a cut prefix is the whole one with its missing bytes zeroed.
	 *
	 * @return array<string, array{string, int, string}> stream (hex), length, unread bytes of the stream (hex)
	 */
	public static function prefixes(): array
	{
		return [
			'closed before the first byte' => ['', 0, ''],
			'one byte' => ['7faa', 127, 'aa'],
			'two bytes' => ['80c8aa', 200, 'aa'],
			'two bytes, cut after the first' => ['81', 0x0100, ''],
			'three bytes' => ['c10203aa', 0x010203, 'aa'],
			'three bytes, cut after the first' => ['c1', 0x010000, ''],
			'three bytes, cut after the second' => ['c102', 0x010200, ''],
			'four bytes' => ['e1020304aa', 0x01020304, 'aa'],
			'four bytes, cut after the first' => ['e1', 0x01000000, ''],
			'four bytes, cut after the second' => ['e102', 0x01020000, ''],
			'four bytes, cut after the third' => ['e10203', 0x01020300, ''],
			'five bytes' => ['f001020304aa', 0x01020304, 'aa'],
			'five bytes, cut after the first' => ['f0', 0, ''],
			'five bytes, cut after the second' => ['f001', 0x01000000, ''],
			'five bytes, cut after the third' => ['f00102', 0x01020000, ''],
			'five bytes, cut after the fourth' => ['f0010203', 0x01020300, ''],
		];
	}


	#[DataProvider('prefixes')]
	public function testReadsTheLengthAndLeavesTheRestOfTheStream(string $streamHex, int $length, string $unreadHex): void
	{
		$stream = fopen('php://memory', 'r+');
		self::assertIsResource($stream);
		fwrite($stream, (string) hex2bin($streamHex));
		rewind($stream);

		self::assertSame($length, Definition::getLength($stream));
		self::assertSame($unreadHex, bin2hex((string) stream_get_contents($stream)));
	}

}
