<?php declare(strict_types = 1);

namespace Lossik\Device\Mikrotik\Api\Tests;

use Lossik\Device\Communication\RuntimeException;
use Lossik\Device\Mikrotik\Api\Command;
use Lossik\Device\Mikrotik\Api\Connection;
use Lossik\Device\Mikrotik\Api\Options;
use Lossik\Device\Mikrotik\Api\Tests\Support\FakeRouter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The call path of the 2.x API against a fake router: what reaches the router and what the caller gets back.
 * Expected values follow the RouterOS API protocol (sentences, query words "?k=v", attribute words "=k=v", "=ret=").
 */
final class ApiClientTest extends TestCase
{

	private string $user;

	private string $password;

	private ?FakeRouter $router = null;


	protected function setUp(): void
	{
		$this->user     = bin2hex(random_bytes(8));
		$this->password = bin2hex(random_bytes(8));
	}


	protected function tearDown(): void
	{
		$this->router?->stop();
	}


	/**
	 * @param list<array<string, mixed>> $steps
	 */
	private function connect(array $steps, int $timeout = 3): Connection
	{
		$this->router     = FakeRouter::start($this->user, $this->password, $steps);
		$options          = new Options();
		$options->port    = $this->router->port();
		$options->timeout = $timeout;

		return (new Connection($options))->connect('127.0.0.1', $this->user, $this->password);
	}


	/**
	 * @return list<list<string>> sentences the router received after the login
	 */
	private function commandsReceived(Connection $connection): array
	{
		$connection->disconnect();

		return array_slice($this->router->received(), 1);
	}


	public function testLoginSendsNameAndPasswordInOneSentence(): void
	{
		$connection = $this->connect([]);

		self::assertTrue($connection->isLoged());
		$connection->disconnect();
		self::assertSame([['/login', '=name=' . $this->user, '=password=' . $this->password]], $this->router->received());
	}


	public function testRejectedLoginThrowsLoginImpossible(): void
	{
		$this->router     = FakeRouter::start($this->user, $this->password);
		$options          = new Options();
		$options->port    = $this->router->port();
		$connection       = new Connection($options);

		try {
			$connection->connect('127.0.0.1', $this->user, bin2hex(random_bytes(8)));
			self::fail('Login with a wrong password must throw');
		} catch (RuntimeException $e) {
			self::assertSame(2, $e->getCode());
		}
		self::assertFalse($connection->isConnected());
	}


	public function testGetSendsQueryWordsAndReturnsRecords(): void
	{
		$connection = $this->connect([
			FakeRouter::reply(
				['!re', '=.id=*1', '=address=10.0.0.1/24', '=interface=ether1'],
				['!re', '=.id=*2', '=address=10.0.0.2/24', '=interface=ether1'],
				['!done'],
			),
		]);

		$result = $connection->Command('ip address')->get(['interface' => 'ether1', 'disabled' => 'false']);

		self::assertSame([
			['.id' => '*1', 'address' => '10.0.0.1/24', 'interface' => 'ether1'],
			['.id' => '*2', 'address' => '10.0.0.2/24', 'interface' => 'ether1'],
		], $result);
		self::assertSame([['/ip/address/print', '?interface=ether1', '?disabled=false']], $this->commandsReceived($connection));
	}


	public function testGetWithTildeFiltersByPrefixOnTheClient(): void
	{
		$connection = $this->connect([
			FakeRouter::reply(
				['!re', '=.id=*1', '=list=blocked', '=comment=abc-1'],
				['!re', '=.id=*2', '=list=blocked', '=comment=x-abc'],
				['!re', '=.id=*3', '=list=blocked'],
				['!re', '=.id=*4', '=list=blocked', '=comment=abc-2'],
				['!done'],
			),
		]);

		$result = $connection->Command('ip firewall address-list')->get(['list' => 'blocked', 'comment' => '~abc']);

		self::assertSame([
			['.id' => '*1', 'list' => 'blocked', 'comment' => 'abc-1'],
			['.id' => '*4', 'list' => 'blocked', 'comment' => 'abc-2'],
		], $result);
		self::assertSame([['/ip/firewall/address-list/print', '?list=blocked']], $this->commandsReceived($connection));
	}


	public function testAddSendsAttributesAndReturnsTheNewId(): void
	{
		$connection = $this->connect([FakeRouter::reply(['!done', '=ret=*9A'])]);

		$result = $connection->Command('ip address')->add(['address' => '10.0.0.1/24', 'interface' => 'ether1']);

		self::assertSame('*9A', $result);
		self::assertSame(
			[['/ip/address/add', '=address=10.0.0.1/24', '=interface=ether1']],
			$this->commandsReceived($connection),
		);
	}


	/**
	 * @return array<string, array{callable(Command): mixed, list<string>}>
	 */
	public static function changeOfMatchedItems(): array
	{
		return [
			'update only the first match' => [
				static fn(Command $c) => $c->update(['interface' => 'ether1'], ['comment' => 'x'], null, true),
				['/ip/address/set', '=.id=*1', '=comment=x'],
			],
			'update all matches' => [
				static fn(Command $c) => $c->update(['interface' => 'ether1'], ['comment' => 'x']),
				['/ip/address/set', '=.id=*1,*2', '=comment=x'],
			],
			'delete only the first match' => [
				static fn(Command $c) => $c->del(['interface' => 'ether1'], null, true),
				['/ip/address/remove', '=.id=*1'],
			],
			'delete all matches' => [
				static fn(Command $c) => $c->del(['interface' => 'ether1']),
				['/ip/address/remove', '=.id=*1,*2'],
			],
		];
	}


	/**
	 * @param callable(Command): mixed $change
	 * @param list<string> $expectedChange
	 */
	#[DataProvider('changeOfMatchedItems')]
	public function testChangeTargetsTheIdsOfMatchedItems(callable $change, array $expectedChange): void
	{
		$connection = $this->connect([
			FakeRouter::reply(['!re', '=.id=*1', '=interface=ether1'], ['!re', '=.id=*2', '=interface=ether1'], ['!done']),
			FakeRouter::reply(['!done']),
		]);

		$change($connection->Command('ip address'));

		self::assertSame([['/ip/address/print', '?interface=ether1'], $expectedChange], $this->commandsReceived($connection));
	}


	/**
	 * @return array<string, array{list<list<string>>, array<string, string>}>
	 */
	public static function oneItemReplies(): array
	{
		return [
			'several matches give the first' => [
				[['!re', '=.id=*1', '=name=a'], ['!re', '=.id=*2', '=name=a'], ['!done']],
				['.id' => '*1', 'name' => 'a'],
			],
			'no match gives an empty array' => [
				[['!done']],
				[],
			],
		];
	}


	/**
	 * @param list<list<string>> $reply
	 * @param array<string, string> $expected
	 */
	#[DataProvider('oneItemReplies')]
	public function testGetOneItemReturnsTheFirstRecordItself(array $reply, array $expected): void
	{
		$connection = $this->connect([FakeRouter::reply(...$reply)]);

		self::assertSame($expected, $connection->Command('ip address')->getOneItem(['name' => 'a']));
	}


	public function testTrapInOneWriteThrowsWithRouterMessageAndConnectionStaysUsable(): void
	{
		$connection = $this->connect([
			FakeRouter::reply(['!trap', '=message=no such item'], ['!done']),
			FakeRouter::reply(['!re', '=.id=*7', '=name=second'], ['!done']),
		]);

		try {
			$connection->Command('ip address')->command('remove', ['.id' => '*99']);
			self::fail('!trap must throw');
		} catch (RuntimeException $e) {
			self::assertSame(4, $e->getCode());
			self::assertSame('no such item', $e->getMessage());
		}
		self::assertSame([['.id' => '*7', 'name' => 'second']], $connection->Command('ip address')->get());
	}


	public function testVersionAsksOnlyForTheVersionProperty(): void
	{
		$connection = $this->connect([FakeRouter::reply(['!re', '=version=7.24.5 (stable)'], ['!done'])]);

		self::assertSame('7.24.5 (stable)', $connection->version());
		self::assertSame([['/system/resource/print', '=.proplist=version']], $this->commandsReceived($connection));
	}


	public function testPausesInsideAWordLongerThanTheTimeoutStillGiveTheWholeRecord(): void
	{
		$connection = $this->connect(FakeRouter::scenario('slow-content', 1200), 1);

		self::assertSame([['.id' => '*1', 'name' => 'late-content']], $connection->Command('ip address')->get());
	}

}
