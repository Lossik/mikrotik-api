<?php declare(strict_types = 1);

/**
 * Client run in a subprocess by EofDeprecationTest: logs in and reads the reply to one "print".
 * On 2.x a connection closed by the router makes the read loop spin forever, so the caller kills this process.
 *
 * Usage: php read-client.php <port>   (credentials in the environment: FAKE_ROUTER_USER, FAKE_ROUTER_PASSWORD)
 */

require __DIR__ . '/../../vendor/autoload.php';

$options          = new Lossik\Device\Mikrotik\Api\Options();
$options->port    = (int) $argv[1];
$options->timeout = 1;

$connection = new Lossik\Device\Mikrotik\Api\Connection($options);
$connection->connect('127.0.0.1', (string) getenv('FAKE_ROUTER_USER'), (string) getenv('FAKE_ROUTER_PASSWORD'));
$connection->Command('ip address')->get();
