<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests\Live;

use Lossik\DeviceCommunication\Client;
use Lossik\Mikrotik\Api\ApiOptions;
use Lossik\Mikrotik\Api\ApiTransport;
use PHPUnit\Framework\TestCase;

/**
 * Base of the live tests (group `live`, see tools/chr): connects to the router named by the environment and removes
 * after every test what the test left behind - each item with the comment {@see self::TAG}, in the menus the tests
 * use. Anything else a test changes (an interface comment, the api-ssl certificate, the identity) is restored by that
 * test in `finally`.
 *
 * The test of the router reboot (ZRebootLiveTest) must run last; it announces the reboot, and every test that runs
 * after it fails instead of talking to a router that is gone.
 */
abstract class LiveTestCase extends TestCase
{
    /** The comment of everything the live tests create on the router. */
    protected const TAG = 'lossik-live-test';

    /** The menus in which the tests create items; an item before the one it depends on (a binding before its pool). */
    private const TAGGED_MENUS = [
        '/ip/firewall/address-list',
        '/ipv6/firewall/address-list',
        '/ip/firewall/nat',
        '/ip/firewall/filter',
        '/ip/firewall/mangle',
        '/queue/tree',
        '/queue/simple',
        '/ip/arp',
        '/ip/address',
        '/ppp/secret',
        '/ip/dhcp-server/lease',
        '/ipv6/dhcp-server/binding',
        '/ipv6/pool',
        '/system/script',
        '/interface/bridge',
    ];

    private static bool $routerRebooted = false;

    protected Env $env;

    /** @var list<ApiTransport> */
    private array $transports = [];

    protected function setUp(): void
    {
        $this->env = Env::load();
        if (self::$routerRebooted) {
            self::fail('The router was rebooted by an earlier live test; the reboot test must run last');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->transports as $transport) {
            $transport->close();
        }
        // without the environment (setUp failed) there is no router to clean
        if (isset($this->env) && !self::$routerRebooted) {
            $this->removeTaggedItems();
        }
    }

    /**
     * Opens (and closes in tearDown) a library connection to the router.
     */
    protected function transport(?ApiOptions $options = null): ApiTransport
    {
        return $this->transports[] = $this->env->connect($options);
    }

    protected function client(?ApiOptions $options = null): Client
    {
        return new Client($this->transport($options));
    }

    protected function raw(): RawApi
    {
        return RawApi::connect($this->env);
    }

    /**
     * Called by the reboot test before it makes the router restart.
     */
    protected static function routerWillReboot(): void
    {
        self::$routerRebooted = true;
    }

    private function removeTaggedItems(): void
    {
        $raw = RawApi::connect($this->env);
        try {
            foreach (self::TAGGED_MENUS as $menu) {
                foreach ($raw->rows($menu . '/print', ['?comment=' . self::TAG, '=.proplist=.id']) as $row) {
                    $raw->rows($menu . '/remove', ['=.id=' . $row['.id']]);
                }
            }
        } finally {
            $raw->close();
        }
    }
}
