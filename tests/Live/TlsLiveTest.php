<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests\Live;

use Lossik\DeviceCommunication\Client;
use Lossik\DeviceCommunication\ConnectionException;
use Lossik\DeviceCommunication\TimeoutException;
use Lossik\Mikrotik\Api\TlsOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Medium;

/**
 * The api-ssl service of a real RouterOS (plan L9, N8): a self-signed certificate verified by its fingerprint, a
 * foreign fingerprint, and unverified() with and without a certificate on the router. The certificate `test` is set
 * on api-ssl by tools/chr/provision.php.
 */
#[Group('live')]
#[Medium]
final class TlsLiveTest extends LiveTestCase
{
    public function testSelfSignedCertificateIsAcceptedByItsFingerprint(): void
    {
        $client = $this->tlsClient(TlsOptions::fingerprint($this->certificateFingerprint()));

        self::assertNotNull($client->version());
    }

    public function testForeignFingerprintIsRefused(): void
    {
        $this->assertHandshakeRefused(TlsOptions::fingerprint(hash('sha256', 'not the router')));
    }

    public function testSelfSignedCertificateIsNotTrustedWithoutAFingerprint(): void
    {
        $this->assertHandshakeRefused(TlsOptions::verified());
    }

    /**
     * N8, and whether the running RouterOS still offers ADH. unverified() verifies nothing: it connects with the
     * certificate present (ECDHE-RSA) as well as without it, where ADH is all the router offers.
     */
    public function testUnverifiedConnectsWithAndWithoutACertificate(): void
    {
        self::assertNotNull($this->tlsClient(TlsOptions::unverified())->version());

        $raw = $this->raw();
        // the static row; RouterOS 6.34, 6.49.18 and 7.12.2 send no `dynamic` in /ip/service, so `?dynamic=false`
        // matches nothing there and a missing `dynamic` counts as static
        $rows = $raw->rows('/ip/service/print', ['?name=api-ssl', '=.proplist=.id,certificate,dynamic']);
        $static = array_filter($rows, static fn (array $row): bool => ($row['dynamic'] ?? 'false') === 'false');
        $service = array_values($static)[0] ?? [];
        $certificate = $service['certificate'] ?? '';
        self::assertSame('test', $certificate);

        $version = null;
        try {
            $raw->rows('/ip/service/set', ['=.id=' . $service['.id'], '=certificate=none']);

            $version = $this->tlsClient(TlsOptions::unverified())->version();
            $this->assertHandshakeRefused(TlsOptions::verified());
        } finally {
            $raw->rows('/ip/service/set', ['=.id=' . ($service['.id'] ?? ''), '=certificate=' . $certificate]);
            $raw->close();
        }

        self::assertNotNull($version);
    }

    private function tlsClient(TlsOptions $tls): Client
    {
        return $this->client($this->env->options(tls: $tls));
    }

    /**
     * The handshake fails at once with ConnectionException: not by a timeout, and not later at the login.
     */
    private function assertHandshakeRefused(TlsOptions $tls): void
    {
        try {
            $this->env->connect($this->env->options(tls: $tls));
            self::fail('The TLS connection was accepted');
        } catch (ConnectionException $e) {
            self::assertNotInstanceOf(TimeoutException::class, $e);
        }
    }

    private function certificateFingerprint(): string
    {
        $raw = $this->raw();
        $certificate = $raw->rows('/certificate/print', ['?name=test', '=.proplist=fingerprint'])[0] ?? [];
        $raw->close();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $certificate['fingerprint'] ?? '');

        return $certificate['fingerprint'];
    }
}
