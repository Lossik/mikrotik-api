<?php declare(strict_types=1);


namespace Lossik\Device\Mikrotik\Api;


use Lossik\Device\Communication\IOptions;

class Options implements IOptions
{


	public $port = 8728;
	public $ssl = false;
	public $sslPort = 8729;
	public $timeout = 3;
	public $localCharset = 'UTF-8';
	public $remoteCharset = 'WINDOWS-1250';

}