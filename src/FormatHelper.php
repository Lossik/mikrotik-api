<?php declare(strict_types=1);

namespace Lossik\Device\Mikrotik\Api;


final class FormatHelper
{


	/**
	 * FormatHelper constructor.
	 */
	public function __construct(){ throw new \LogicException('Static class'); }


	/**
	 * @param int $speed in bit/s
	 * @return string
	 */
	static public function prettySpeed($speed)
	{

		$result = '';

		if ($speed >= 1000000000) {
			$result .= ((int)($speed / 1000000000)) . 'G ';
			$speed  = $speed % 1000000000;
		}

		if ($speed >= 1000000) {
			$result .= ((int)($speed / 1000000)) . 'M ';
			$speed  = $speed % 1000000;
		}

		if ($speed >= 1000) {
			$result .= ((int)($speed / 1000)) . 'k ';
			$speed  = $speed % 1000;
		}

		return trim($result . ($speed ?: ''));

	}


	/**
	 * @param string $speed
	 * @return int
	 */
	static public function unprettySpeed($speed)
	{
		$result = 0;

		if (($pos = strpos($speed, 'G')) !== false) {
			$result += ((int)(substr($speed, 0, $pos)) * 1000000000);
			$speed  = substr($speed, $pos);
		}
		if (($pos = strpos($speed, 'M')) !== false) {
			$result += ((int)(substr($speed, 0, $pos)) * 1000000);
			$speed  = substr($speed, $pos);
		}
		if (($pos = strpos($speed, 'k')) !== false) {
			$result += ((int)(substr($speed, 0, $pos)) * 1000);
			$speed  = substr($speed, $pos);
		}

		$result += (int)$speed;

		return trim($result);

	}

}
