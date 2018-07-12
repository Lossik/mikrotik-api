<?php declare(strict_types=1);

namespace Lossik\Device\Mikrotik\Api;


use Lossik\Device\Communication\ICommand;

class Connection extends \Lossik\Device\Communication\Connection
{


	/**
	 * @var string
	 */
	private $ver;


	/**
	 * @param Options|null $options
	 */
	public function __construct(Options $options = null)
	{
		parent::__construct($options ?: new Options(), new Definition());
	}


	/**
	 * @return string
	 */
	public function version()
	{
		return $this->ver ?: ($this->ver = $this->comm('/system/resource/print', ['.proplist' => 'version'])[0]['version']);
	}


	/**
	 * @param string $menu
	 * @return ICommand
	 */
	public function Command($menu): ICommand
	{
		$command = new Command($menu);
		$command->setConnection($this);

		return $command;
	}

}