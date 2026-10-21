<?php declare(strict_types = 1);

namespace Lossik\Device\Mikrotik\Api\Tests\Live;

use Lossik\Device\Mikrotik\Api\Connection;
use Lossik\Device\Mikrotik\Api\Options;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The 2.x API against a real RouterOS (MikroTik CHR booted by tools/chr/run-live.sh, which exports MIKROTIK_LIVE_*).
 * Missing settings are a failure, not a skip: a live run that tests nothing must not look green.
 */
#[Group('live')]
final class RouterTest extends TestCase
{

	private const MARK = 'lossik-live-test';

	private const MENU = '/ip/firewall/address-list';

	private Connection $connection;


	protected function setUp(): void
	{
		$env = [];
		foreach (['MIKROTIK_LIVE_HOST', 'MIKROTIK_LIVE_PORT_API', 'MIKROTIK_LIVE_USER', 'MIKROTIK_LIVE_PASS'] as $name) {
			$value = getenv($name);
			if ($value === false || $value === '') {
				self::fail("$name is not set; run the live tests through tools/chr/run-live.sh");
			}
			$env[$name] = $value;
		}

		$options          = new Options();
		$options->port    = (int) $env['MIKROTIK_LIVE_PORT_API'];
		$options->timeout = 10;

		$this->connection = (new Connection($options))->connect($env['MIKROTIK_LIVE_HOST'], $env['MIKROTIK_LIVE_USER'], $env['MIKROTIK_LIVE_PASS']);
		$this->removeTestEntries();
	}


	protected function tearDown(): void
	{
		if (isset($this->connection) && $this->connection->isConnected()) {
			$this->removeTestEntries();
			$this->connection->disconnect();
		}
	}


	private function removeTestEntries(): void
	{
		$entries = $this->connection->Command(self::MENU)->get(['comment' => self::MARK]);
		if ($entries) {
			$this->connection->Command(self::MENU)->del(['comment' => self::MARK]);
		}
	}


	public function testSystemResourceReportsTheRouterVersion(): void
	{
		$resource = $this->connection->Command('/system/resource')->get();

		self::assertCount(1, $resource);
		$expected = getenv('CHR_VERSION');
		self::assertStringStartsWith($expected !== false && $expected !== '' ? $expected : '7.', $resource[0]['version'] ?? '');
		self::assertSame($resource[0]['version'], $this->connection->version());
	}


	public function testAddressListEntryCanBeAddedFoundAndDeleted(): void
	{
		$list    = $this->connection->Command(self::MENU);
		$address = '192.0.2.' . random_int(1, 254);

		$id = $list->add(['list' => self::MARK, 'address' => $address, 'comment' => self::MARK]);

		self::assertMatchesRegularExpression('/^\*[0-9A-F]+$/', $id);
		$found = $list->getOneItem(['list' => self::MARK, 'address' => $address]);
		self::assertSame($id, $found['.id'] ?? null);

		$list->del(['.id' => $id]);

		self::assertSame([], $list->get(['list' => self::MARK]));
	}

}
