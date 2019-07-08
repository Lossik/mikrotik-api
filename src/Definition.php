<?php declare(strict_types = 1);

namespace Lossik\Device\Mikrotik\Api;

const API_IMPOSSIBLE_CONNECT = 1;
const API_TRAP               = 4;
const API_FATAL              = 8;

use Lossik\Device\Communication\IDefinition;
use Lossik\Device\Communication\RuntimeException;

class Definition implements IDefinition
{

	private $options;


	/**
	 * @param $array
	 * @return array
	 */
	static public function arrayChangeKeyName($array)
	{
		if (is_array($array)) {
			$array_new = [];
			foreach ($array as $k => $v) {
				$tmp = str_replace("-", "_", $k);
				$tmp = str_replace("/", "_", $tmp);
				if ($tmp) {
					$array_new[$tmp] = $v;
				} else {
					$array_new[$k] = $v;
				}
			}

			return $array_new;
		}

		return $array;
	}


	public static function getLength($socket)
	{
		$BYTE = ord(fread($socket, 1));
		if ($BYTE & 128) {
			if (($BYTE & 192) == 128) {
				return (($BYTE & 63) << 8) + ord(fread($socket, 1));
			} else {
				if (($BYTE & 224) == 192) {
					$LENGTH = (($BYTE & 31) << 8) + ord(fread($socket, 1));

					return ($LENGTH << 8) + ord(fread($socket, 1));
				} else {
					if (($BYTE & 240) == 224) {
						$LENGTH = (($BYTE & 15) << 8) + ord(fread($socket, 1));
						$LENGTH = ($LENGTH << 8) + ord(fread($socket, 1));

						return ($LENGTH << 8) + ord(fread($socket, 1));
					} else {
						$LENGTH = ord(fread($socket, 1));
						$LENGTH = ($LENGTH << 8) + ord(fread($socket, 1));
						$LENGTH = ($LENGTH << 8) + ord(fread($socket, 1));

						return ($LENGTH << 8) + ord(fread($socket, 1));
					}
				}
			}
		} else {
			return $BYTE;
		}
	}


	/**
	 * @param $response
	 * @throws RuntimeException
	 * @return array|null
	 */
	public static function parseResponse($response)
	{
		if (is_array($response)) {
			$PARSED      = [];
			$CURRENT     = null;
			$singlevalue = null;
			$error       = false;
			foreach ($response as $x) {
				if (in_array($x, ['!fatal', '!re', '!trap'])) {
					if ($x == '!re') {
						$CURRENT =& $PARSED[];
					} else {
						$CURRENT =& $PARSED[$x][];
						$error   = $x;
					}
				} elseif ($x != '!done') {
					$MATCHES = [];
					if (preg_match_all('/[^=]+/i', $x, $MATCHES)) {
						if ($MATCHES[0][0] == 'ret') {
							$singlevalue = $MATCHES[0][1];
						}
						$CURRENT[$MATCHES[0][0]] = (isset($MATCHES[0][1]) ? $MATCHES[0][1] : '');
					}
				}
			}
			if (empty($PARSED) && !is_null($singlevalue)) {
				$PARSED = $singlevalue;
			}
			if ($error == '!fatal') {
				throw new RuntimeException(isset($response[1]) ? $response[1] : json_encode([
																								$response,
																							]), API_FATAL);
			}
			if ($error == '!trap') {
				throw new RuntimeException(isset($PARSED['!trap'][0]['message']) ? $PARSED['!trap'][0]['message'] : json_encode([
																																	$response,
																																]), API_TRAP);
			}

			return $PARSED;
		} else {
			return [];
		}
	}


	/**
	 * @param $var
	 * @return bool
	 */
	static public function isIterable($var)
	{
		return $var !== null && (is_array($var) || $var instanceof \Traversable || $var instanceof \Iterator || $var instanceof \IteratorAggregate);
	}


	public function login($socket, $login, $password)
	{
		static::write($socket, '/login', false);
		static::write($socket, '=name=' . $login, false);
		static::write($socket, '=password=' . $password);
		$RESPONSE = static::read($socket, false, false);
		if (isset($RESPONSE[0]) && $RESPONSE[0] == '!done') {
			if (!isset($RESPONSE[1])) { // post 6.43 login
				return true;
			}
			$MATCHES = [];
			if (preg_match_all('/[^=]+/i', $RESPONSE[1], $MATCHES)) { // pre 6.43 login
				if ($MATCHES[0][0] == 'ret' && strlen($MATCHES[0][1]) == 32) {
					static::write($socket, '/login', false);
					static::write($socket, '=name=' . $login, false);
					static::write($socket, '=response=00' . md5(chr(0) . $password . pack('H*', $MATCHES[0][1])));
					$RESPONSE = static::read($socket, false, false);
					if (isset($RESPONSE[0]) && $RESPONSE[0] == '!done') {
						return true;
					}
				}
			}
		}

		return false;
	}


	public function encodeLength($length)
	{
		if ($length < 0x80) {
			$length = chr($length);
		} elseif ($length < 0x4000) {
			$length |= 0x8000;
			$length = chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
		} elseif ($length < 0x200000) {
			$length |= 0xC00000;
			$length = chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
		} elseif ($length < 0x10000000) {
			$length |= 0xE0000000;
			$length = chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
		} elseif ($length >= 0x10000000) {
			$length = chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
		}

		return $length;
	}


	public function socket($options, $ip)
	{
		$PROTOCOL = ($options->ssl ? 'ssl://' : '');
		$context  = stream_context_create([
											  'ssl' => [
												  'ciphers'          => 'ADH:ALL',
												  'verify_peer'      => false,
												  'verify_peer_name' => false,
											  ],
										  ]);

		$socket = @stream_socket_client($PROTOCOL . $ip . ':' . ($options->ssl ? $options->sslPort : $options->port), $error_no, $error_str, $options->timeout, STREAM_CLIENT_CONNECT, $context);

		if (!is_resource($socket)) {
			throw new RuntimeException("Unable to connect to the device.", API_IMPOSSIBLE_CONNECT);
		}
		stream_set_blocking($socket, true);
		stream_set_timeout($socket, $options->timeout);
		$this->options = clone $options;
		return $socket;
	}


	public function comm($socket, $com, $arr = [])
	{
		$count = count($arr);
		$com   = str_replace(' ', '/', ' ' . $com);
		$com   = str_replace('//', '/', $com);
		$this->write($socket, $com, !$arr);
		$i = 0;
		if (Definition::isIterable($arr)) {
			foreach ($arr as $k => $v) {
				switch ($k[0]) {
					case "?":
						$el = "$k=$v";
						break;
					case "~":
						$el = "$k~$v";
						break;
					default:
						$el = "=$k=$v";
						break;
				}
				$last = ($i++ == $count - 1);
				$this->write($socket, $el, $last);
			}
		}

		return $this->read($socket);
	}


	protected function write($socket, $command, $param2 = true)
	{
		if ($command) {
			$command = iconv($this->options->localCharset, $this->options->remoteCharset . '//IGNORE//TRANSLIT', $command);
			$data    = explode("\n", $command);
			foreach ($data as $com) {
				$com = trim($com);
				fwrite($socket, static::encodeLength(strlen($com)) . $com);
			}
			if (gettype($param2) == 'integer') {
				fwrite($socket, static::encodeLength(strlen('.tag=' . $param2)) . '.tag=' . $param2 . chr(0));
			} elseif (gettype($param2) == 'boolean') {
				fwrite($socket, ($param2 ? chr(0) : ''));
			}

			return true;
		} else {
			return false;
		}
	}


	protected function read($socket, $parse = true, $loged = true)
	{
		$RESPONSE     = [];
		$receiveddone = false;
		while (true) {
			$LENGTH = static::getLength($socket);

			$_ = "";
			if ($LENGTH > 0) {
				$_      = "";
				$retlen = 0;
				while ($retlen < $LENGTH) {
					$toread = $LENGTH - $retlen;
					$_      .= fread($socket, $toread);
					$retlen = strlen($_);
				}
				$RESPONSE[] = iconv($this->options->remoteCharset, $this->options->localCharset . '//IGNORE//TRANSLIT', $_);
			}
			if (in_array($_, ['!fatal', '!done', '!trap'])) {
				$receiveddone = true;
			}
			$STATUS = socket_get_status($socket);
			if ((!$loged && !$STATUS['unread_bytes']) || ($loged && !$STATUS['unread_bytes'] && $receiveddone)) {
				break;
			}
		}

		if ($parse) {
			$RESPONSE = static::parseResponse($RESPONSE);
		}

		return $RESPONSE;
	}

}
