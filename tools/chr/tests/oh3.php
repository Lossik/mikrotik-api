<?php

declare(strict_types=1);

/**
 * OH3 without a router: ChrApi::login against a fake API server on a local socket.
 * The fake writes its replies up front and keeps the connection open; after login() returns (or throws)
 * the test reads every sentence the client sent, so a client that sends one sentence too many is caught.
 */

require __DIR__ . '/../routeros.php';

/**
 * @param list<string> $words
 */
function encodeSentence(array $words): string
{
    $data = '';
    foreach ($words as $word) {
        $data .= chr(strlen($word)) . $word; // the fake only sends words shorter than 0x80 bytes
    }

    return $data . "\0";
}

/**
 * Splits everything the client has sent so far into sentences (words up to 0x3FFF bytes).
 *
 * @param resource $conn
 * @return list<list<string>>
 */
function sentSentences($conn): array
{
    stream_set_blocking($conn, false);
    $data = '';
    while (($chunk = fread($conn, 65536)) !== false && $chunk !== '') {
        $data .= $chunk;
    }
    $sentences = [];
    $words = [];
    $i = 0;
    while ($i < strlen($data)) {
        $len = ord($data[$i++]);
        if (($len & 0x80) !== 0) {
            $len = (($len & 0x3F) << 8) | ord($data[$i++]);
        }
        if ($len === 0) {
            $sentences[] = $words;
            $words = [];
            continue;
        }
        $words[] = substr($data, $i, $len);
        $i += $len;
    }

    return $sentences;
}

$retRouter = '4e5c00369b2000365bc1c86b9cd064fd';
$retOther = '0f1e2d3c4b5a69788796a5b4c3d2e1f0';
$secret = 'S3cret pass';
// Expected response from the formula of plan §5.1, computed here and not by ChrApi.
$responseSecret = '00' . md5("\0" . $secret . hex2bin($retOther));

// name => [password, replies of the fake, expected result of login(), expected sentences sent by the client]
// expected result: null = logged in, 'trap:<message>' = login() returns the !trap message, 'exception:<text>' = throws
$cases = [
    'before 6.43, empty password (pair accepted by a real 6.42.12)' => [
        '',
        [['!done', "=ret=$retRouter"], ['!done']],
        null,
        [['/login', '=name=admin', '=password='], ['/login', '=name=admin', '=response=000ce24ef113c075d3d2696fd78e7d50f6']],
    ],
    'before 6.43, non-empty password' => [
        $secret,
        [['!done', "=ret=$retOther"], ['!done']],
        null,
        [['/login', '=name=admin', "=password=$secret"], ['/login', '=name=admin', "=response=$responseSecret"]],
    ],
    '6.43 and later: one sentence' => [
        $secret,
        [['!done']],
        null,
        [['/login', '=name=admin', "=password=$secret"]],
    ],
    '!trap to the first sentence' => [
        $secret,
        [['!trap', '=message=invalid user name or password (6)'], ['!done']],
        'trap:invalid user name or password (6)',
        [['/login', '=name=admin', "=password=$secret"]],
    ],
    '!trap to the second sentence' => [
        $secret,
        [['!done', "=ret=$retOther"], ['!trap', '=message=cannot log in'], ['!done']],
        'trap:cannot log in',
        [['/login', '=name=admin', "=password=$secret"], ['/login', '=name=admin', "=response=$responseSecret"]],
    ],
    'ret too short' => [
        '',
        [['!done', '=ret=too_short']],
        'exception:login challenge is not 32 hex digits: too_short',
        [['/login', '=name=admin', '=password=']],
    ],
    'ret of 32 non-hex characters' => [
        '',
        [['!done', '=ret=' . str_repeat('z', 32)]],
        'exception:login challenge is not 32 hex digits: ' . str_repeat('z', 32),
        [['/login', '=name=admin', '=password=']],
    ],
];

$failures = 0;
foreach ($cases as $name => [$password, $replies, $expected, $expectedSent]) {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        fwrite(STDERR, "OH3 FAIL: cannot create the fake server: $errstr\n");
        exit(1);
    }
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    $api = ChrApi::connect('127.0.0.1', $port, 5.0);
    $conn = stream_socket_accept($server, 5.0);
    foreach ($replies as $reply) {
        fwrite($conn, encodeSentence($reply));
    }

    try {
        $trap = $api->login('admin', $password, 2.0);
        $result = $trap === null ? null : "trap:$trap";
    } catch (RuntimeException $e) {
        $result = 'exception:' . $e->getMessage();
    }
    $sent = sentSentences($conn);
    $api->close();
    fclose($conn);
    fclose($server);

    if ($result !== $expected) {
        fwrite(STDERR, "OH3 FAIL [$name]: login() gave " . var_export($result, true) . ', expected ' . var_export($expected, true) . "\n");
        $failures++;
    }
    if ($sent !== $expectedSent) {
        fwrite(STDERR, "OH3 FAIL [$name]: client sent " . json_encode($sent) . ', expected ' . json_encode($expectedSent) . "\n");
        $failures++;
    }
}

if ($failures > 0) {
    exit(1);
}
echo "OH3 OK\n";
