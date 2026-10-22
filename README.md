# lossik/mikrotik-api

PHP client for the MikroTik RouterOS API (the binary API protocol on port 8728, optionally TLS on 8729), built on
[`lossik/device-communication`](https://github.com/Lossik/device-communication).

**3.0 is in development on this branch and not released.** It has a new namespace (`Lossik\Mikrotik\Api\`), is
built on `lossik/device-communication` 3.0 and is not usable yet; installation and usage will be documented here
when it is. For the released version see the `2.x` branch and `composer require lossik/mikrotik-api:^2.1`.

## Supported PHP

Only patched PHP releases are supported: **8.2.35+, 8.3.36+, 8.4.27+ and 8.5.12+** (CVE-2026-109618 and
CVE-2026-109619, "PHP2Shell"). `composer.json` requires `"php": "^8.2"`; only the CI of this project enforces the
patched minima.

## License

MIT, see [LICENSE](LICENSE).
