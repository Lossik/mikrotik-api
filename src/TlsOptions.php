<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api;

/**
 * How ApiTransport secures the connection with TLS (the api-ssl service of RouterOS, port 8729).
 *
 *     new ApiOptions(tls: TlsOptions::verified(cafile: '/etc/ssl/router-ca.pem'))
 *     new ApiOptions(tls: TlsOptions::fingerprint('5f3c…'))   // a self-signed router certificate, pinned
 *     new ApiOptions(tls: TlsOptions::unverified())           // DANGEROUS: encrypted, router not authenticated
 */
final readonly class TlsOptions
{
    /** The client cipher list of unverified(): ADH needs security level 0 in OpenSSL 1.1+ (2.x lacked it). */
    private const UNVERIFIED_CIPHERS = 'ADH:ALL:@SECLEVEL=0';

    /**
     * @param array<string, mixed> $ssl
     */
    private function __construct(private array $ssl, private bool $peerNameFromHost)
    {
    }

    /**
     * The certificate chain and the host name are verified (the default way to use TLS).
     *
     * @param string|null $cafile a PEM file of trusted certificates; null (with $capath null) = the system CA store
     * @param string|null $capath a directory of trusted certificates (hashed names, see `openssl rehash`)
     * @param string|null $peerName the name the certificate must carry; null = the host passed to connect()
     */
    public static function verified(?string $cafile = null, ?string $capath = null, ?string $peerName = null): self
    {
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        if ($cafile !== null) {
            $ssl['cafile'] = $cafile;
        }
        if ($capath !== null) {
            $ssl['capath'] = $capath;
        }
        if ($peerName !== null) {
            $ssl['peer_name'] = $peerName;
        }

        return new self($ssl, $peerName === null);
    }

    /**
     * Only the certificate with this SHA-256 fingerprint is accepted (pinning; meant for the self-signed certificate
     * of a router). The chain and the host name are not verified - a self-signed router certificate has neither.
     *
     * @param string $sha256 64 hex digits, as openssl_x509_fingerprint($certificate, 'sha256') returns them
     */
    public static function fingerprint(string $sha256): self
    {
        return new self([
            'verify_peer' => false,
            'verify_peer_name' => false,
            'peer_fingerprint' => ['sha256' => $sha256],
        ], false);
    }

    /**
     * DANGEROUS: verifies nothing. The connection is encrypted but the router is NOT authenticated - anyone in the
     * path can impersonate it and read the credentials. Use verified() or fingerprint() unless nothing else works.
     *
     * Offers anonymous Diffie-Hellman (ADH, TLS 1.2 only), which api-ssl uses when no certificate is set on the
     * router. It is not ADH-only: when the router has a certificate, the connection is made as well (ECDHE-RSA) and
     * the certificate is not checked.
     */
    public static function unverified(): self
    {
        return new self([
            'verify_peer' => false,
            'verify_peer_name' => false,
            'ciphers' => self::UNVERIFIED_CIPHERS,
        ], false);
    }

    /**
     * The `ssl` options of the stream context for a connection to the host.
     *
     * @internal used by ApiTransport
     * @return array<string, mixed>
     */
    public function contextOptions(string $host): array
    {
        return $this->peerNameFromHost ? [...$this->ssl, 'peer_name' => $host] : $this->ssl;
    }
}
