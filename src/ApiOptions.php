<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api;

/**
 * Connection options of ApiTransport. All times are in seconds.
 */
final class ApiOptions
{
    public const PORT = 8728;

    public const TLS_PORT = 8729;

    /**
     * @param int|null $port the API port; null = 8728, or 8729 with TLS (see effectivePort())
     * @param float $connectTimeout the limit for opening the TCP connection
     * @param float $idleTimeout the longest silence between two bytes of a response (measured from the last received
     *                           byte, or from sending the command until the first one); slow responses that keep
     *                           sending data pass
     * @param float|null $responseTimeout the limit for a whole response, counted from sending the command; null = no
     *                                    limit (a peer that keeps sending one byte at a time is then never cut off)
     * @param float $cancelTimeout the limit for the device to finish a cancelled stream
     * @param string $charset the charset of the device: 'UTF-8' passes the bytes unchanged; any other charset
     *                        (e.g. 'windows-1250', the default of 2.x) converts every word with iconv both ways
     * @param TlsOptions|null $tls TLS (api-ssl) and how the router is verified; null = plain TCP
     */
    public function __construct(
        public readonly ?int $port = null,
        public readonly float $connectTimeout = 10.0,
        public readonly float $idleTimeout = 60.0,
        public readonly ?float $responseTimeout = null,
        public readonly float $cancelTimeout = 10.0,
        public readonly string $charset = 'UTF-8',
        public readonly ?TlsOptions $tls = null,
    ) {
    }

    /**
     * The port to connect to: the one set, otherwise 8729 with TLS and 8728 without.
     */
    public function effectivePort(): int
    {
        return $this->port ?? ($this->tls !== null ? self::TLS_PORT : self::PORT);
    }
}
