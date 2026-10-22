<?php declare(strict_types = 1);

/**
 * Fake RouterOS API server for tests (the router is the one thing the library does not control).
 *
 * Usage: php fake-router.php <base64 of a JSON script>
 *
 * Script: {"user": "...", "password": "...", "steps": [step, ...]}
 * - Listens on 127.0.0.1 with port 0 and prints "PORT <n>" (parallel runs never collide), accepts one client.
 * - Login: the first sentence must be exactly ["/login", "=name=<user>", "=password=<password>"] (post-6.43 login);
 *   then "!done", otherwise "!trap" + "!done" (in one write).
 * - Each step waits for one sentence from the client and answers it:
 *     sentences  list of sentences (lists of words) to send; encoded, concatenated, sent in ONE write unless cut
 *     truncate   send only the first N bytes of the encoded reply
 *     cuts       byte offsets where the reply is split into separate writes, with pauseMs between them
 *     pauseMs    pause between the parts of a cut reply
 *     close      close the connection after sending (and end the script)
 * - After the last step it reads further sentences until the client disconnects.
 * Every received sentence is printed as "RECV <json list of words>", the end as "END".
 */

$script   = json_decode(base64_decode($argv[1] ?? '', true) ?: 'null', true);
if (!is_array($script)) {
	fwrite(STDERR, "usage: php fake-router.php <base64 json script>\n");
	exit(2);
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$server) {
	fwrite(STDERR, "listen failed: $errstr\n");
	exit(1);
}

$name = (string) stream_socket_get_name($server, false);
echo 'PORT ' . substr($name, strrpos($name, ':') + 1) . "\n";
fflush(STDOUT);

$conn = stream_socket_accept($server, 20);
if (!$conn) {
	fwrite(STDERR, "no client connected\n");
	exit(1);
}
stream_set_timeout($conn, 10);


function encodeLength(int $l): string
{
	if ($l < 0x80) {
		return chr($l);
	}
	if ($l < 0x4000) {
		return pack('n', $l | 0x8000);
	}
	if ($l < 0x200000) {
		$v = $l | 0xC00000;

		return chr($v >> 16 & 0xFF) . chr($v >> 8 & 0xFF) . chr($v & 0xFF);
	}
	if ($l < 0x10000000) {
		return pack('N', $l | 0xE0000000);
	}

	return chr(0xF0) . pack('N', $l);
}


/**
 * @param list<string> $words
 */
function sentence(array $words): string
{
	$out = '';
	foreach ($words as $w) {
		$out .= encodeLength(strlen($w)) . $w;
	}

	return $out . chr(0);
}


/**
 * @param resource $conn
 */
function readExact($conn, int $n): ?string
{
	$buf = '';
	while (strlen($buf) < $n) {
		$chunk = fread($conn, $n - strlen($buf));
		if ($chunk === false || $chunk === '') {
			if (feof($conn) || stream_get_meta_data($conn)['timed_out']) {
				return null;
			}
			continue;
		}
		$buf .= $chunk;
	}

	return $buf;
}


/**
 * @param resource $conn
 */
function readLength($conn): ?int
{
	$b = readExact($conn, 1);
	if ($b === null) {
		return null;
	}
	$c = ord($b);
	if (($c & 0x80) === 0) {
		return $c;
	}
	$extra = ($c & 0xC0) === 0x80 ? 1 : (($c & 0xE0) === 0xC0 ? 2 : (($c & 0xF0) === 0xE0 ? 3 : 4));
	$rest  = readExact($conn, $extra);
	if ($rest === null) {
		return null;
	}
	$value = $extra === 4 ? 0 : $c & (0xFF >> ($extra + 1));
	foreach (str_split($rest) as $byte) {
		$value = ($value << 8) + ord($byte);
	}

	return $value;
}


/**
 * @param resource $conn
 * @return list<string>|null null when the client disconnected (or went silent for 10 s)
 */
function receive($conn): ?array
{
	$words = [];
	while (true) {
		$l = readLength($conn);
		if ($l === null) {
			return null;
		}
		if ($l === 0) {
			echo 'RECV ' . json_encode($words, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
			fflush(STDOUT);

			return $words;
		}
		$word = readExact($conn, $l);
		if ($word === null) {
			return null;
		}
		$words[] = $word;
	}
}


/**
 * @param resource $conn
 */
function send($conn, string $bytes): void
{
	if ($bytes !== '') {
		fwrite($conn, $bytes);
		fflush($conn);
	}
}


$login = receive($conn);
if ($login === ['/login', '=name=' . $script['user'], '=password=' . $script['password']]) {
	send($conn, sentence(['!done']));
	$steps = $script['steps'] ?? [];
} else {
	send($conn, sentence(['!trap', '=message=invalid user name or password (6)']) . sentence(['!done']));
	$steps = [];
}

foreach ($steps as $step) {
	if (receive($conn) === null) {
		fwrite(STDERR, "client disconnected before the script ended\n");
		break;
	}
	$bytes = implode('', array_map('sentence', $step['sentences'] ?? []));
	if (isset($step['truncate'])) {
		$bytes = substr($bytes, 0, $step['truncate']);
	}
	$offset = 0;
	foreach ([...($step['cuts'] ?? []), strlen($bytes)] as $i => $cut) {
		if ($i > 0) {
			usleep(($step['pauseMs'] ?? 0) * 1000);
		}
		send($conn, substr($bytes, $offset, $cut - $offset));
		$offset = $cut;
	}
	if (!empty($step['close'])) {
		fclose($conn);
		echo "END\n";
		exit(0);
	}
}

while (receive($conn) !== null) {
	// record whatever else the client sends until it disconnects
}
echo "END\n";
