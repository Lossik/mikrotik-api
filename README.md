# lossik/mikrotik-api

PHP client for the MikroTik RouterOS API (the binary API protocol on port 8728, optionally TLS on 8729), built on
[`lossik/device-communication`](https://github.com/Lossik/device-communication).

**2.x is maintenance only.** It receives fixes needed to run on supported PHP releases, nothing else. New
development happens in 3.0 (branch `feature/3.0`, to be released), which fixes the known issues of 2.x and changes
the API.

## Supported PHP

Only patched PHP releases are supported: **8.2.35+, 8.3.36+, 8.4.27+ and 8.5.12+** (CVE-2026-109618 and
CVE-2026-109619, "PHP2Shell"). `composer.json` requires `"php": "^8.2"`, so Composer also installs the package on
an unpatched PHP: Linux distributions backport the fixes without changing the version number, and Composer cannot
tell the two apart. Only the CI of this project enforces the patched minima. The PHP minimum is raised only in a
minor release, announced in the [changelog](CHANGELOG.md).

Required extensions: `iconv`; `openssl` only for TLS connections.

## Installation

```bash
composer require lossik/mikrotik-api:^2.1
```

## Usage

```php
use Lossik\Device\Mikrotik\Api\Connection;
use Lossik\Device\Mikrotik\Api\Options;

$options = new Options();
$options->timeout = 5;

$connection = (new Connection($options))->connect('192.0.2.1', 'admin', 'secret');
$addresses = $connection->Command('/ip/address')->get(['interface' => 'ether1']);
```

## Known issues

2.x has protocol defects that are fixed only in 3.0 (for example, the read loop never ends when the router closes
the connection or stops answering). They are listed in the [changelog](CHANGELOG.md#known-issues).

## License

MIT, see [LICENSE](LICENSE).
