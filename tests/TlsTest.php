<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests;

use Lossik\DeviceCommunication\Command;
use Lossik\DeviceCommunication\ConnectionException;
use Lossik\DeviceCommunication\TimeoutException;
use Lossik\Mikrotik\Api\ApiOptions;
use Lossik\Mikrotik\Api\ApiTransport;
use Lossik\Mikrotik\Api\Tests\Support\FakeRouter;
use Lossik\Mikrotik\Api\TlsOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

/**
 * ApiTransport over TLS against the fake router in TLS mode (plan §7.1: T23 and the TLS rows of T7/T8), under the
 * time limit of medium tests. Two self-signed certificates A and B (CN 127.0.0.1) are generated for the run into a
 * temp dir; no key is committed.
 */
#[Medium]
final class TlsTest extends TestCase
{
    private const OK_ROWS = [['.id' => '*1', 'name' => 'a'], ['.id' => '*2', 'name' => 'b']];

    private static string $dir;

    /** @var array<'A'|'B', array{pem: string, certificate: string, sha256: string}> */
    private static array $certificates = [];

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/mikrotik-api-tls-' . bin2hex(random_bytes(6));
        if (!mkdir(self::$dir, 0700)) {
            throw new \RuntimeException('Cannot create ' . self::$dir);
        }
        foreach (['A', 'B'] as $name) {
            self::$certificates[$name] = self::createCertificate($name);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach ((array) glob(self::$dir . '/*') as $file) {
            if (is_string($file)) {
                unlink($file);
            }
        }
        rmdir(self::$dir);
    }

    /**
     * T23: client TLS options => router (certificate A or B, or anonymous DH only).
     *
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedHandshakes(): iterable
    {
        yield 'verified against the CA file holding the certificate' => ['verified with CA A', 'A'];
        yield 'pinned fingerprint of the certificate' => ['pin A', 'A'];
        yield 'anonymous DH with anonymous()' => ['anonymous', 'anonymous'];
    }

    #[DataProvider('acceptedHandshakes')]
    public function testAcceptedHandshakeLogsInAndRunsCommands(string $client, string $router): void
    {
        $fakeRouter = self::startRouter('ok', $router);
        $transport = self::connect($fakeRouter, self::tls($client));

        self::assertSame(self::OK_ROWS, $transport->execute(new Command('/ip/address', 'print'))->rows);
    }

    /**
     * T23: client TLS options => router.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function refusedHandshakes(): iterable
    {
        yield 'verified against the system CA store, self-signed certificate' => ['verified', 'A'];
        yield 'fingerprint of another certificate' => ['pin A', 'B'];
        yield 'anonymous DH without anonymous()' => ['verified', 'anonymous'];
    }

    #[DataProvider('refusedHandshakes')]
    public function testRefusedHandshakeIsAConnectionErrorAndNothingIsSent(string $client, string $router): void
    {
        $fakeRouter = self::startRouter('ok', $router);

        try {
            self::connect($fakeRouter, self::tls($client));
            self::fail('ConnectionException expected');
        } catch (ConnectionException $e) {
            self::assertNotInstanceOf(TimeoutException::class, $e);
        }
        self::assertSame([], $fakeRouter->received(), 'the router must not receive the login');
    }

    /**
     * T7 over TLS
     *
     * @return iterable<string, array{key-of<FakeRouter::SCENARIOS>}>
     */
    public static function silences(): iterable
    {
        yield 'silence before the reply' => ['silence'];
        yield 'silence after the first byte of a two-byte length' => ['silence-in-length'];
    }

    /**
     * @param key-of<FakeRouter::SCENARIOS> $scenario
     */
    #[DataProvider('silences')]
    public function testSilenceOverTlsEndsWithIdleTimeoutAndClosesTheTransport(string $scenario): void
    {
        $router = self::startRouter($scenario, 'A');
        $transport = self::connect($router, self::tls('verified with CA A'), idleTimeout: 1.0);

        $started = hrtime(true);
        try {
            $transport->execute(new Command('/ip/address', 'print'));
            self::fail('TimeoutException expected');
        } catch (TimeoutException $e) {
            self::assertSame('idleTimeout', $e->limit);
        }
        $elapsed = self::secondsSince($started);
        self::assertGreaterThanOrEqual(1.0, $elapsed);
        self::assertLessThan(3.0, $elapsed);
        self::assertFalse($transport->isOpen());

        $started = hrtime(true);
        try {
            $transport->execute(new Command('/ip/address', 'print'));
            self::fail('ConnectionException expected');
        } catch (ConnectionException) {
        }
        self::assertLessThan(0.1, self::secondsSince($started));
        self::assertCount(2, $router->received(), 'the second call must not send anything');
    }

    /**
     * T8 over TLS
     *
     * @return iterable<string, array{key-of<FakeRouter::SCENARIOS>}>
     */
    public static function closedConnections(): iterable
    {
        yield 'closed before the first byte' => ['eof'];
        yield 'closed inside the content of a word' => ['eof-mid'];
        yield 'closed after the first byte of a two-byte length' => ['eof-in-length'];
    }

    /**
     * @param key-of<FakeRouter::SCENARIOS> $scenario
     */
    #[DataProvider('closedConnections')]
    public function testClosedConnectionOverTlsIsAConnectionErrorAtOnce(string $scenario): void
    {
        $router = self::startRouter($scenario, 'A');
        $transport = self::connect($router, self::tls('verified with CA A'));

        $started = hrtime(true);
        try {
            $transport->execute(new Command('/ip/address', 'print'));
            self::fail('ConnectionException expected');
        } catch (ConnectionException $e) {
            self::assertNotInstanceOf(TimeoutException::class, $e);
        }
        self::assertLessThan(1.0, self::secondsSince($started));
        self::assertFalse($transport->isOpen());
    }

    private static function tls(string $client): TlsOptions
    {
        return match ($client) {
            'verified' => TlsOptions::verified(),
            'verified with CA A' => TlsOptions::verified(cafile: self::$certificates['A']['certificate']),
            'pin A' => TlsOptions::fingerprint(self::$certificates['A']['sha256']),
            'anonymous' => TlsOptions::anonymous(),
            default => throw new \InvalidArgumentException('Unknown client: ' . $client),
        };
    }

    /**
     * Keep the router in a variable for the whole test: a destroyed FakeRouter stops its process.
     *
     * @param key-of<FakeRouter::SCENARIOS> $scenario
     * @param string $tls 'A' or 'B' (that certificate) or 'anonymous' (anonymous DH only)
     */
    private static function startRouter(string $scenario, string $tls): FakeRouter
    {
        return $tls === 'anonymous'
            ? FakeRouter::start($scenario, tlsAnonymous: true)
            : FakeRouter::start($scenario, tlsCertificate: self::$certificates[$tls === 'B' ? 'B' : 'A']['pem']);
    }

    private static function connect(FakeRouter $router, TlsOptions $tls, float $idleTimeout = 60.0): ApiTransport
    {
        return ApiTransport::connect('127.0.0.1', FakeRouter::USER, FakeRouter::PASSWORD, new ApiOptions(
            port: $router->port(),
            connectTimeout: 5.0,
            idleTimeout: $idleTimeout,
            tls: $tls,
        ));
    }

    /**
     * A self-signed certificate for 127.0.0.1 (EC P-256), written as a PEM with the key (for the router) and as a PEM
     * of the certificate alone (a CA file for the client).
     *
     * @return array{pem: string, certificate: string, sha256: string}
     */
    private static function createCertificate(string $name): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new \RuntimeException('openssl_pkey_new failed: ' . openssl_error_string());
        }
        $csrKey = $key; // passed by reference
        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $csrKey, ['digest_alg' => 'sha256']);
        if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
            throw new \RuntimeException('openssl_csr_new failed: ' . openssl_error_string());
        }
        $certificate = openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        if (
            $certificate === false
            || !openssl_x509_export($certificate, $certificatePem)
            || !openssl_pkey_export($key, $keyPem)
            || !is_string($certificatePem)
            || !is_string($keyPem)
        ) {
            throw new \RuntimeException('Cannot create the certificate: ' . openssl_error_string());
        }
        $sha256 = openssl_x509_fingerprint($certificate, 'sha256');
        if ($sha256 === false) {
            throw new \RuntimeException('openssl_x509_fingerprint failed');
        }
        $files = ['pem' => self::$dir . "/$name.pem", 'certificate' => self::$dir . "/$name.crt"];
        file_put_contents($files['pem'], $certificatePem . $keyPem);
        file_put_contents($files['certificate'], $certificatePem);

        return [...$files, 'sha256' => $sha256];
    }

    private static function secondsSince(int $hrtime): float
    {
        return (hrtime(true) - $hrtime) / 1e9;
    }
}
