<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api;

/**
 * Connection options of ApiTransport. All times are in seconds.
 */
final class ApiOptions
{
    /**
     * @param int $port the API port (8728 without TLS)
     * @param float $connectTimeout the limit for opening the TCP connection
     * @param float $idleTimeout the longest silence between two bytes of a response (measured from the last received
     *                           byte, or from sending the command until the first one); slow responses that keep
     *                           sending data pass
     * @param float|null $responseTimeout the limit for a whole response, counted from sending the command; null = no
     *                                    limit (a peer that keeps sending one byte at a time is then never cut off)
     * @param float $cancelTimeout the limit for the device to finish a cancelled stream
     * @param string $charset the charset of the device: 'UTF-8' passes the bytes unchanged; any other charset
     *                        (e.g. 'windows-1250', the default of 2.x) converts every word with iconv both ways
     */
    public function __construct(
        public readonly int $port = 8728,
        public readonly float $connectTimeout = 10.0,
        public readonly float $idleTimeout = 60.0,
        public readonly ?float $responseTimeout = null,
        public readonly float $cancelTimeout = 10.0,
        public readonly string $charset = 'UTF-8',
    ) {
    }
}
