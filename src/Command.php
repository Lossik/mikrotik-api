<?php
/**
 * Created by PhpStorm.
 * User: Losse
 * Date: 21.06.2017
 * Time: 12:08
 */

namespace Lossik\Device\Mikrotik\Api;


class Command
{


	/** @var string */
	private $menu;

	/** @var Connection|callable */
	private $connection;


	/**
	 * MikrotikCommand constructor.
	 * @param string $menu
	 */
	public function __construct($menu)
	{
		$this->menu = $menu;
	}


	public function addItem($record)
	{
		return $this->command('add', $record);
	}


	public function command($com, array $arr = [])
	{
		return $this->getConnection()->comm($this->menu . '/' . $com, $arr);
	}


	/**
	 * @return Connection
	 * @throws Exception
	 */
	protected function getConnection()
	{
		if ($this->connection instanceof Connection) {
			return $this->connection;
		}

		if (is_callable($this->connection)) {
			$connection = call_user_func($this->connection);
			if (!($connection instanceof Connection)) {
				throw new Exception('Callback set with "setConnection" must return instance of ' . Connection::class);
			}

			return $this->connection = $connection;
		}

		throw new Exception('Connection is not set');
	}


	/**
	 * @param $connection Connection|callable
	 * @return $this
	 */
	public function setConnection($connection)
	{
		$this->connection = $connection;

		return $this;
	}


	public function getVersion()
	{
		return $this->getConnection()->version();
	}


	public function __call($name, $arguments)
	{
		return $this->command($name, $arguments);
	}


	public function updateItem($id, array $record)
	{
		return $this->command('set', ['.id' => $id] + $record);
	}


	public function updateItems(array $where, array $record, $filterCallback = null)
	{
		$r   = $this->getItems(['.id'], $where, $filterCallback);
		$ids = array_column($r, '.id');

		return $this->command('set', ['.id' => implode(',', $ids)] + $record);
	}


	public function updateOneItem(array $where, array $record, $filterCallback = null)
	{
		$r   = $this->getOneItem($where, $filterCallback);

		return $this->command('set', ['.id' => $r['.id']] + $record);
	}


	public function getItems(array $columns = [], array $where = [], $filterCallback = null)
	{
		$args = [];

		foreach ($where as $key => $value) {
			$args['?' . $key] = $value;
		}

		if ($columns) {
			$args['.proplist'] = implode(',', $columns);
		}

		$result = $this->command('print', $args);

		if ($filterCallback) {
			$result = array_filter($result, $filterCallback);
		}

		return array_values($result);
	}


	public function getItem($id, $filterCallback = null)
	{
		$result = $this->getItems([], ['.id' => $id], $filterCallback);

		return $result ? $result[0] : [];
	}


	public function getOneItem(array $where = [], $filterCallback = null)
	{
		$result = $this->getItems([], $where, $filterCallback);

		return $result ? $result[0] : [];
	}


	public function delItem($id)
	{
		return $this->command('remove', ['.id' => $id]);
	}


	public function delItems(array $where, $filterCallback = null)
	{
		$r   = $this->getItems(['.id'], $where, $filterCallback);
		$ids = array_column($r, '.id');

		return $this->command('remove', ['.id' => implode(',', $ids)]);
	}


	public function delOneItem(array $where, $filterCallback = null)
	{
		$r = $this->getOneItem($where, $filterCallback);

		return $this->command('remove', ['.id' => $r['.id']]);
	}

}
