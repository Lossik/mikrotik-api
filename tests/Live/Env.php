<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests\Live;

use Lossik\Mikrotik\Api\ApiOptions;
use Lossik\Mikrotik\Api\ApiTransport;
use Lossik\Mikrotik\Api\TlsOptions;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The router the live tests run against, from the variables tools/chr/run-live.sh exports. A missing variable fails
 * the test: a live run that silently tests nothing is worse than a red one.
 */
final readonly class Env
{
    private const VARIABLES = [
        'MIKROTIK_LIVE_HOST',
        'MIKROTIK_LIVE_PORT_API',
        'MIKROTIK_LIVE_PORT_TLS',
        'MIKROTIK_LIVE_PORT_SSH',
        'MIKROTIK_LIVE_USER',
        'MIKROTIK_LIVE_PASS',
        'CHR_VERSION',
    ];

    public function __construct(
        public string $host,
        public int $apiPort,
        public int $tlsPort,
        public int $sshPort,
        public string $user,
        public string $password,
        public string $chrVersion,
    ) {
    }

    /**
     * @throws AssertionFailedError a variable is missing, empty or (a port) not a number
     */
    public static function load(): self
    {
        /** @var array<string, string> $values */
        $values = [];
        $missing = [];
        foreach (self::VARIABLES as $name) {
            $value = getenv($name);
            if ($value === false || $value === '') {
                $missing[] = $name;
            }
            $values[$name] = $value === false ? '' : $value;
        }
        if ($missing !== []) {
            throw new AssertionFailedError(
                'The live tests need the environment variables set by tools/chr/run-live.sh; missing: '
                . implode(', ', $missing),
            );
        }
        foreach (['MIKROTIK_LIVE_PORT_API', 'MIKROTIK_LIVE_PORT_TLS', 'MIKROTIK_LIVE_PORT_SSH'] as $name) {
            if (!ctype_digit($values[$name])) {
                throw new AssertionFailedError(sprintf('%s is not a port number: %s', $name, $values[$name]));
            }
        }

        return new self(
            $values['MIKROTIK_LIVE_HOST'],
            (int) $values['MIKROTIK_LIVE_PORT_API'],
            (int) $values['MIKROTIK_LIVE_PORT_TLS'],
            (int) $values['MIKROTIK_LIVE_PORT_SSH'],
            $values['MIKROTIK_LIVE_USER'],
            $values['MIKROTIK_LIVE_PASS'],
            $values['CHR_VERSION'],
        );
    }

    /**
     * The options for a connection to this router: the API port, or the api-ssl port when $tls is given. The idle
     * limit is short on purpose (the default 60 s would outlast the 10 s of a medium test), yet far above the
     * milliseconds a command takes on the emulated router.
     */
    public function options(float $idleTimeout = 8.0, string $charset = 'UTF-8', ?TlsOptions $tls = null): ApiOptions
    {
        return new ApiOptions(
            port: $tls === null ? $this->apiPort : $this->tlsPort,
            idleTimeout: $idleTimeout,
            charset: $charset,
            tls: $tls,
        );
    }

    /**
     * @throws \Lossik\DeviceCommunication\CommunicationException
     */
    public function connect(?ApiOptions $options = null, ?string $password = null): ApiTransport
    {
        return ApiTransport::connect(
            $this->host,
            $this->user,
            $password ?? $this->password,
            $options ?? $this->options(),
        );
    }
}
