<?php declare(strict_types=1);

namespace Lossik\Device\Mikrotik\Api;

use Lossik\Device\Communication as Comm;

class Command extends Comm\Command
{


	/**
	 * @param $record
	 * @return mixed
	 * @deprecated
	 */
	public function addItem($record)
	{
		return $this->add($record);
	}


	public function command($com, array $arr = [])
	{
		return $this->connection->comm($this->menu . '/' . $com, $arr);
	}


	public function __call($name, $arguments)
	{
		return $this->command($name, $arguments);
	}


	/**
	 * @param $id
	 * @param array $record
	 * @return mixed
	 * @deprecated
	 */
	public function updateItem($id, array $record)
	{
		return $this->update(['.id' => $id], $record, null, true);
	}


	/**
	 * @param array $where
	 * @param array $record
	 * @param null $filterCallback
	 * @return mixed
	 * @deprecated
	 */
	public function updateItems(array $where, array $record, $filterCallback = null)
	{
		return $this->update($where, $record, $filterCallback);
	}


	/**
	 * @param array $where
	 * @param array $record
	 * @param null $filterCallback
	 * @return mixed
	 * @deprecated
	 */
	public function updateOneItem(array $where, array $record, $filterCallback = null)
	{
		return $this->update($where, $record, $filterCallback, true);
	}


	/**
	 * @param array $columns
	 * @param array $where
	 * @param null $filterCallback
	 * @return array
	 * @deprecated
	 */
	public function getItems(array $columns = [], array $where = [], $filterCallback = null)
	{
		return $this->get($where, $filterCallback);
	}


	/**
	 * @param $id
	 * @param null $filterCallback
	 * @return array|mixed
	 * @deprecated
	 */
	public function getItem($id, $filterCallback = null)
	{
		$result = $this->get(['.id' => $id], $filterCallback, true);

		return $result ? $result[0] : [];
	}


	/**
	 * @param array $where
	 * @param null $filterCallback
	 * @return array|mixed
	 * @deprecated
	 */
	public function getOneItem(array $where = [], $filterCallback = null)
	{
		$result = $this->get($where, $filterCallback, true);

		return $result ? $result[0] : [];
	}


	/**
	 * @param $id
	 * @return mixed
	 * @deprecated
	 */
	public function delItem($id)
	{
		return $this->del(['.id' => $id]);
	}


	/**
	 * @param array $where
	 * @param null $filterCallback
	 * @return mixed
	 * @deprecated
	 */
	public function delItems(array $where, $filterCallback = null)
	{
		return $this->del($where, $filterCallback);
	}


	/**
	 * @param array $where
	 * @param null $filterCallback
	 * @return mixed
	 * @deprecated
	 */
	public function delOneItem(array $where, $filterCallback = null)
	{
		return $this->del($where, $filterCallback, true);
	}


	public function get(array $where = [], $filterCallback = null, $onlyFirstItem = false)
	{
		$args = [];
		$whereCallbacks = [];
		if($filterCallback){
			$whereCallbacks[] = $filterCallback;
		}
		foreach ($where as $key => $value) {
			if(is_string($value) && strpos($value,'~') === 0){
				$value = substr($value,1);
				$whereCallbacks[] = function ($item) use($key, $value){
					return strpos($item[$key] ?? '' , $value) === 0;
				};
			}else{
				$args['?' . $key] = $value;
			}
		}

		$result = $this->command('print', $args);

		if ($whereCallbacks) {
			foreach ($whereCallbacks as $callback) {
				$result = array_filter($result, $callback);
			}
		}

		$result = array_values($result);
		$first  = current($result);

		return $onlyFirstItem ? ($first ? [$first] : []) : $result;
	}


	public function add(array $record)
	{
		return $this->command('add', $record);
	}


	public function update(array $where, array $record, $filterCallback = null, $onlyFirstItem = false)
	{
		$items = $this->get($where, $filterCallback, $onlyFirstItem);
		$ids   = array_column($items, '.id');

		return $this->command('set', ['.id' => implode(',', $ids)] + $record);
	}


	public function del(array $where, $filterCallback = null, $onlyFirstItem = false)
	{
		$items = $this->get($where, $filterCallback, $onlyFirstItem);
		$ids   = array_column($items, '.id');

		return $this->command('remove', ['.id' => implode(',', $ids)]);
	}


}
