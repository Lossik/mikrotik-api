<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests;

use Lossik\Mikrotik\Api\ApiOptions;
use Lossik\Mikrotik\Api\TlsOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiOptionsTest extends TestCase
{
    /**
     * @return iterable<string, array{ApiOptions, int}>
     */
    public static function ports(): iterable
    {
        yield 'plain TCP' => [new ApiOptions(), 8728];
        yield 'TLS' => [new ApiOptions(tls: TlsOptions::anonymous()), 8729];
        yield 'TLS with a port set' => [new ApiOptions(port: 8728, tls: TlsOptions::anonymous()), 8728];
    }

    #[DataProvider('ports')]
    public function testPortDefaultsToTheServiceOfTheProtocolUnlessSet(ApiOptions $options, int $port): void
    {
        self::assertSame($port, $options->effectivePort());
    }
}
