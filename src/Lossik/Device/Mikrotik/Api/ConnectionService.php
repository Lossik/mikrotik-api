<?php
/**
 * Created by PhpStorm.
 * User: Losse
 * Date: 04.01.2017
 * Time: 15:40
 */

namespace Lossik\Device\Mikrotik\Api;


use Lossik\Device\Mikrotik\SSH;

class ConnectionService
{


	/**
	 * @var Connection[]
	 */
	private $mikrotiks;


	/**
	 * @var array
	 */
	private $params;


	/**
	 * Mikrotik constructor.
	 * @param array $params
	 */
	public function __construct(array $params)
	{
		$this->params = $params;
	}


	/**
	 * @param $ip
	 * @return Connection
	 * @throws Exception
	 */
	public function Connect($ip, $autofix = true)
	{
		try {
			if (!isset($this->mikrotiks[$ip])) {
				$this->mikrotiks[$ip] = new Connection();
			}
			if (!$this->mikrotiks[$ip]->isLoged()) {
				list($login, $password) = $this->getSettingsForIp($ip);
				$this->mikrotiks[$ip]->connect($ip, $login, $password);
				if (!$this->mikrotiks[$ip]->isLoged()) {
					throw new Exception('Not connect to router IP: ' . $ip, 3);
				}
			}
		}
		catch (Exception $e) {
			switch ($e->getCode()) {
				case 10061:
					if ($autofix) {
						$ssh = new SSH\Connection();
						$ssh->Connect($ip, $login, $password);
						$fix = new SSH\fixApi($ssh);
						$fix->proccess($this->params['autofix']['login'], $this->params['autofix']['password']);

						return $this->Connect($ip, false);
					}
					else {
						throw new Exception('Neni povoleno api', $e->getCode(), $e);
					}
				case 10060:
					throw new Exception('Router neni dostupny', $e->getCode(), $e);
			}
			throw new Exception($e->getMessage(), $e->getCode(), $e);
		}


		return $this->mikrotiks[$ip];
	}


	/**
	 * @param $ip
	 * @return array
	 * @throws Exception
	 */
	protected function getSettingsForIp($ip)
	{
		if (!isset($this->params[$ip])) {
			return [$this->params['try']['logins'], $this->params['try']['passwords']];
		}

		return [$this->params[$ip]['login'], $this->params[$ip]['password']];
	}

}