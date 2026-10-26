<?php

/**
 * Fake RouterOS API server for tests (the router is the one thing the library does not control).
 *
 * Usage: php fake-router.php <base64 of a JSON script>; started by FakeRouter, the server is FakeRouterServer.
 */

declare(strict_types=1);

use Lossik\Mikrotik\Api\Tests\Support\FakeRouterServer;

require __DIR__ . '/../../vendor/autoload.php';

exit(FakeRouterServer::fromArgument($argv[1] ?? '')->run());
