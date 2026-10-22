<?php declare(strict_types = 1);

namespace Lossik\Device\Mikrotik\Api\Tests;

use Lossik\Device\Mikrotik\Api\Tests\Support\FakeRouter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A connection closed by the router while the client reads a reply must not produce PHP deprecations.
 *
 * 2.0.x called ord('') on every pass of the read loop at EOF, which PHP 8.5 reports as deprecated (hundreds of
 * thousands of lines). The loop itself still never ends on 2.x (known issue, fixed in 3.0), so the client runs in a
 * subprocess that is killed after a few seconds. Its deprecation output is checked, and that it was still running
 * when the test stopped it: a client that exited earlier crashed (a fatal error shows no "Deprecated" line).
 * On PHP 8.2-8.4 ord('') is silent, so this test can only fail there for a different reason; PHP 8.5 is the
 * discriminating version.
 */
final class EofDeprecationTest extends TestCase
{

	private const RUN_SECONDS = 5;

	private const KEPT_LINES = 3;


	/**
	 * @return array<string, array{string}>
	 */
	public static function closedConnections(): array
	{
		return [
			'closed before the first byte of the reply' => ['eof'],
			'closed after the first byte of a two-byte word length' => ['eof-in-length'],
		];
	}


	#[DataProvider('closedConnections')]
	public function testReadingFromAClosedConnectionReportsNoDeprecation(string $scenario): void
	{
		$user     = bin2hex(random_bytes(8));
		$password = bin2hex(random_bytes(8));
		$router   = FakeRouter::start($user, $password, FakeRouter::scenario($scenario));

		$client = proc_open(
			[PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL', __DIR__ . '/Support/read-client.php', (string) $router->port()],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			null,
			getenv() + ['FAKE_ROUTER_USER' => $user, 'FAKE_ROUTER_PASSWORD' => $password],
		);
		self::assertIsResource($client);
		fclose($pipes[0]);

		[$deprecations, $kept] = self::countDeprecatedLines($pipes[2], $pipes[1]);

		$status = proc_get_status($client);
		proc_terminate($client, 9);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($client);

		// the read loop never ends on 2.x: a client that is gone before the test stopped it has crashed
		self::assertTrue($status['running'], "The client exited by itself (exit code {$status['exitcode']}), the first lines of its output:\n" . implode("\n", $kept));
		// the client really logged in and waited for the reply - otherwise "no deprecation" proves nothing
		self::assertSame(['/ip/address/print'], $router->received()[1] ?? null, implode("\n", $kept));
		self::assertSame(0, $deprecations, "Deprecated lines on stderr, the first ones:\n" . implode("\n", $kept));
	}


	/**
	 * Reads stderr for RUN_SECONDS line by line and counts lines with "Deprecated" without keeping the output
	 * (2.0.x writes hundreds of thousands of lines).
	 *
	 * @param resource $stderr
	 * @param resource $stdout
	 * @return array{int, list<string>} number of Deprecated lines, the first lines of stderr and stdout
	 */
	private static function countDeprecatedLines($stderr, $stdout): array
	{
		stream_set_blocking($stderr, false);
		stream_set_blocking($stdout, false);
		$deadline = microtime(true) + self::RUN_SECONDS;
		$count    = 0;
		$kept     = [];
		$partial  = '';
		while (($left = $deadline - microtime(true)) > 0) {
			$read   = [$stderr, $stdout];
			$write  = $except = null;
			if (!stream_select($read, $write, $except, 0, (int) min($left * 1e6, 200000))) {
				continue;
			}
			foreach ($read as $stream) {
				$chunk = (string) fread($stream, 65536);
				if ($stream === $stdout) {
					if ($chunk !== '' && count($kept) < self::KEPT_LINES) {
						$kept[] = 'stdout: ' . trim($chunk);
					}
					continue;
				}
				$lines   = explode("\n", $partial . $chunk);
				$partial = (string) array_pop($lines);
				foreach ($lines as $line) {
					if (str_contains($line, 'Deprecated')) {
						$count++;
					}
					if ($line !== '' && count($kept) < self::KEPT_LINES) {
						$kept[] = $line;
					}
				}
			}
			if (feof($stderr) && feof($stdout)) {
				break;
			}
		}
		if (str_contains($partial, 'Deprecated')) {
			$count++;
		}

		return [$count, $kept];
	}

}
