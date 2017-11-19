<?php
/**
 * Created by PhpStorm.
 * User: Losse
 * Date: 21.06.2017
 * Time: 12:07
 */

namespace Lossik\Device\Mikrotik\Api;


use Lossik\Device\Communication\Connection as CommConnection;

class Connection extends CommConnection
{


	private $ver;


	public function __construct(Options $options = null)
	{
		parent::__construct($options ?: new Options(), new Definition());
	}


	public function version()
	{
		return $this->ver ?: ($this->ver = $this->comm('/system/resource/print', ['.proplist' => 'version'])[0]['version']);
	}


	public function Command($menu)
	{
		$command = new Command($menu);
		$command->setConnection($this);

		return $command;
	}


}