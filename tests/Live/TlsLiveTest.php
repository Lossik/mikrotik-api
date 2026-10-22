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
 * foreign fingerprint, and anonymous Diffie-Hellman once the router has no certificate. The certificate `test` is set
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
     * N8, and whether the running RouterOS still offers ADH. Without a certificate it offers nothing else.
     */
    public function testRouterWithoutACertificateIsReachedOnlyAnonymously(): void
    {
        $raw = $this->raw();
        $service = $raw->rows('/ip/service/print', ['?name=api-ssl', '?dynamic=false', '.proplist=.id,certificate'])[0]
            ?? [];
        $certificate = $service['certificate'] ?? '';
        self::assertSame('test', $certificate);

        $version = null;
        try {
            $raw->rows('/ip/service/set', ['=.id=' . $service['.id'], '=certificate=none']);

            $version = $this->tlsClient(TlsOptions::anonymous())->version();
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
        $certificate = $raw->rows('/certificate/print', ['?name=test', '.proplist=fingerprint'])[0] ?? [];
        $raw->close();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $certificate['fingerprint'] ?? '');

        return $certificate['fingerprint'];
    }
}
