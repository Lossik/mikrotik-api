<?php declare(strict_types = 1);

namespace Lossik\Device\Mikrotik\Api\Tests\Support;

/**
 * Runs tests/Support/fake-router.php in its own process and collects what the client sent to it.
 *
 * Steps (see fake-router.php): each answers one sentence received after the login.
 */
final class FakeRouter
{

	/** Word of 200 bytes: its length 0xC8 is encoded in two bytes, 0x80 0xC8. */
	private const LONG_WORD_BYTES = 191;

	/** @var resource */
	private $process;

	/** @var array<int, resource> */
	private array $pipes;

	private int $port;


	/**
	 * @param list<array<string, mixed>> $steps
	 */
	private function __construct(string $user, string $password, array $steps)
	{
		$script        = base64_encode(json_encode(['user' => $user, 'password' => $password, 'steps' => $steps], JSON_THROW_ON_ERROR));
		$process       = proc_open([PHP_BINARY, __DIR__ . '/fake-router.php', $script], [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		], $pipes);
		if ($process === false) {
			throw new \RuntimeException('Cannot start the fake router');
		}
		$this->process = $process;
		$this->pipes   = $pipes;
		stream_set_timeout($this->pipes[1], 10);
		$line = (string) fgets($this->pipes[1]);
		if (!preg_match('/^PORT (\d+)$/', trim($line), $m)) {
			$this->stop();
			throw new \RuntimeException('Fake router did not report its port: ' . $line);
		}
		$this->port = (int) $m[1];
	}


	/**
	 * @param list<array<string, mixed>> $steps
	 */
	public static function start(string $user, string $password, array $steps = []): self
	{
		return new self($user, $password, $steps);
	}


	/**
	 * Step: answer with these sentences in one TCP write.
	 *
	 * @param list<string> ...$sentences
	 * @return array<string, mixed>
	 */
	public static function reply(array ...$sentences): array
	{
		return ['sentences' => $sentences];
	}


	/**
	 * Steps of the raw transport scenarios (each answers the first command after the login):
	 * - eof: the connection is closed before the first byte of the reply;
	 * - eof-in-length: "!re", then only 0x80 (first byte of a two-byte word length), then closed;
	 * - eof-content: closed inside the content of the second word ("=." of "=.id=*1");
	 * - slow-content: two pauses of $pauseMs inside the content of the same word, then the rest of the reply;
	 * - slow-length: a pause of $pauseMs between the two bytes of a word length, then the rest of the reply.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function scenario(string $name, int $pauseMs = 0): array
	{
		$longRecord = [['!re', '=comment=' . str_repeat('x', self::LONG_WORD_BYTES)], ['!done']];

		// 03 "!re" 07 "=.id=*1": the bytes 5-11 are the content of the second word
		$record = [['!re', '=.id=*1', '=name=late-content'], ['!done']];

		return match ($name) {
			'eof' => [['close' => true]],
			'eof-in-length' => [['sentences' => $longRecord, 'truncate' => 5, 'close' => true]],
			'eof-content' => [['sentences' => $record, 'truncate' => 7, 'close' => true]],
			'slow-content' => [['sentences' => $record, 'cuts' => [7, 9], 'pauseMs' => $pauseMs]],
			'slow-length' => [['sentences' => $longRecord, 'cuts' => [5], 'pauseMs' => $pauseMs]],
		};
	}


	public function port(): int
	{
		return $this->port;
	}


	/**
	 * Waits until the router finished its script and the client disconnected (or the router closed the connection).
	 *
	 * @return list<list<string>> sentences received, the login first
	 */
	public function received(): array
	{
		$out = (string) stream_get_contents($this->pipes[1]);
		stream_set_blocking($this->pipes[2], false);
		$err = (string) stream_get_contents($this->pipes[2]);
		$this->stop();
		if (!preg_match('/^END$/m', $out)) {
			throw new \RuntimeException("Fake router did not finish:\n" . $out . $err);
		}
		preg_match_all('/^RECV (.*)$/m', $out, $m);

		return array_map(static fn(string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $m[1]);
	}


	public function stop(): void
	{
		if (is_resource($this->process)) {
			proc_terminate($this->process, 9);
			foreach ($this->pipes as $pipe) {
				if (is_resource($pipe)) {
					fclose($pipe);
				}
			}
			proc_close($this->process);
		}
	}


	public function __destruct()
	{
		$this->stop();
	}

}
