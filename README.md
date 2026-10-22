# lossik/mikrotik-api

PHP client for the MikroTik RouterOS API (the binary API protocol on port 8728, optionally TLS on 8729), built on
[`lossik/device-communication`](https://github.com/Lossik/device-communication): `ApiTransport` is the API
implementation of its `Transport`, and you talk to the router through its `Client` and `Menu`.

**3.0 is in development on this branch and not released.** It has a new namespace (`Lossik\Mikrotik\Api\`) and is
built on `lossik/device-communication` 3.0. For the released version see the `2.x` branch and
`composer require lossik/mikrotik-api:^2.1`. This README describes 3.0; the changes against 2.x are in the
[changelog](CHANGELOG.md).

A REST transport is planned as a separate package on top of `lossik/device-communication`; it is not part of this
package.

## Requirements

- **PHP**: see [Supported PHP](#supported-php).
- **Extensions**: `ext-openssl` (TLS) and `ext-iconv` (charset conversion), both declared in `composer.json`.
- **RouterOS**: the whole 7.x line is supported; the live test suite runs against CHR **7.24.5** and **7.23.8**. The
  API protocol itself has not changed since 6.43. Routers before 6.43 answer the login with an MD5 challenge; the
  client answers it, but this is **implemented and verified against a fake router only, not through this library
  against a real RouterOS before 6.43** (a bare-socket client was checked on CHR 6.42.12). Anything older than 6.x
  is not verified. On plain TCP the password travels in clear text in the first sentence of every login (the
  pre-6.43 router ignores it and sends the challenge), so use [TLS](#tls) unless the network is trusted.

## Installation

3.0 has not been released yet, so there is nothing to install. It needs PHP `^8.2`, `ext-openssl`, `ext-iconv` and
`lossik/device-communication` `^3.0` (see `composer.json`); installation instructions come with the release.

## Quick start

<!-- run: ok -->
```php
use Lossik\DeviceCommunication\Client;
use Lossik\DeviceCommunication\Query;
use Lossik\Mikrotik\Api\ApiTransport;

$transport = ApiTransport::connect('192.0.2.1', 'admin', 'secret');   // plain TCP, port 8728
$client = new Client($transport);

// rows are lists of string => string; the proplist limits the returned properties
$addresses = $client->menu('/ip/address')->print(Query::where('interface', 'ether1'), proplist: ['.id', 'address']);

// conditions combine: ?name=ether1 ?name=ether2 ?#|
$some = $client->menu('/interface')->print(Query::or(Query::where('name', 'ether1'), Query::where('name', 'ether2')));

$row = $client->menu('/queue/tree')->first(Query::where('name', 'q1'));   // ?array: null = nothing matches

// update() and delete() print the matching .id values first, then send one set / remove with .id=*1,*2
// (only the first match with onlyFirst: true); they return the number of items and send nothing when none match
$changed = $client->menu('/ip/firewall/nat')->update(Query::where('comment', 'x'), ['disabled' => 'yes']);
$removed = $client->menu('/ppp/secret')->delete(Query::where('name', 'u1'), onlyFirst: true);

// any other verb of a menu; the ID of an item goes to the attribute .id
$client->menu('/system/identity')->run('set', ['name' => 'R1']);
$client->menu('/interface')->run('disable', ['.id' => '*5']);

$version = $client->version();   // /system/resource/print .proplist=version; null when the router returns none

$transport->close();
```

`add()` returns the ID the router assigns to the new item (`=ret=`), or `null` when it returns none:

<!-- run: done-ret-split -->
```php
use Lossik\DeviceCommunication\Client;
use Lossik\Mikrotik\Api\ApiTransport;

$client = new Client(ApiTransport::connect('192.0.2.1', 'admin', 'secret'));
$id = $client->menu('/ip/arp')->add(['address' => '192.0.2.2', 'interface' => 'ether1']);   // "*9A"
```

Values are strings as RouterOS sends them (`true`, `false`, `1500`, `10.0.0.1/24`). A value is sent as one word
whole, so `=`, newlines and edge spaces survive. The menu may be written `/ip/address` or `ip address`. Queries have
no prefix or regular expression condition (the API has none): filter with `array_filter()` and `str_starts_with()`.
The shared contract (`Client`, `Menu`, `Query`, `Command`, `Result`) is documented in
[`lossik/device-communication`](https://github.com/Lossik/device-communication).

## Options

`ApiTransport::connect(string $host, string $user, string $password, ApiOptions $options = new ApiOptions())`.
All times are seconds, all arguments of `ApiOptions` are named and optional:

| option | default | meaning |
|---|---|---|
| `port` | `null` | the API port; `null` = **8728**, or **8729** when `tls` is set (`ApiOptions::effectivePort()`) |
| `connectTimeout` | `10.0` | time allowed for opening the connection; a failure that took that long is a `TimeoutException` with `->limit === 'connectTimeout'` (2.x: 3 s) |
| `idleTimeout` | `60.0` | the longest silence of the router while a response is read: counted from sending the command until its first byte and from every received byte on. A slow response that keeps sending data passes. Also the write timeout |
| `responseTimeout` | `null` | the limit for a whole response, counted from sending the command; `null` = none. Not applied to `listen` |
| `cancelTimeout` | `10.0` | how long the router has to finish a cancelled stream |
| `charset` | `'UTF-8'` | the charset of the router, see [Charset](#charset) |
| `tls` | `null` | `TlsOptions`; `null` = plain TCP, see [TLS](#tls) |

The limits apply to the login as well. `idleTimeout` is a silence limit, not a duration limit: **without
`responseTimeout` a peer that sends one byte now and then is never cut off**, so set it when you cannot trust the
network.

<!-- run: ok -->
```php
use Lossik\Mikrotik\Api\ApiOptions;
use Lossik\Mikrotik\Api\ApiTransport;

$options = new ApiOptions(connectTimeout: 5.0, idleTimeout: 30.0, responseTimeout: 120.0);
$transport = ApiTransport::connect('192.0.2.1', 'admin', 'secret', $options);
```

## TLS

Set `tls` to use the `api-ssl` service (port 8729 unless `port` says otherwise):

| factory | what it checks |
|---|---|
| `TlsOptions::verified(?string $cafile = null, ?string $capath = null, ?string $peerName = null)` | the certificate chain (the system CA store, or the given CA file or directory) and that the certificate carries the host name; `peerName` = the name it must carry, default the host passed to `connect()` |
| `TlsOptions::fingerprint(string $sha256)` | only the certificate with this SHA-256 fingerprint (64 hex digits) is accepted; chain and host name are not checked. For the self-signed certificate of a router; `/certificate print` shows its `fingerprint` |
| `TlsOptions::unverified()` | **nothing** - see below |

<!-- run: tls-verified -->
```php
use Lossik\Mikrotik\Api\ApiOptions;
use Lossik\Mikrotik\Api\ApiTransport;
use Lossik\Mikrotik\Api\TlsOptions;

// a certificate from your own CA
$options = new ApiOptions(tls: TlsOptions::verified(cafile: '/etc/ssl/router-ca.pem', peerName: 'router.example.com'));
$transport = ApiTransport::connect('192.0.2.1', 'admin', 'secret', $options);
```

<!-- run: tls-fingerprint -->
```php
use Lossik\Mikrotik\Api\ApiOptions;
use Lossik\Mikrotik\Api\ApiTransport;
use Lossik\Mikrotik\Api\TlsOptions;

// the self-signed certificate of the router, pinned ($fingerprint: 64 hex digits)
$options = new ApiOptions(tls: TlsOptions::fingerprint($fingerprint));
$transport = ApiTransport::connect('192.0.2.1', 'admin', 'secret', $options);
```

**`TlsOptions::unverified()` is dangerous.** The connection is encrypted but the router is **not authenticated**:
anyone on the path can impersonate it and read the credentials. It offers anonymous Diffie-Hellman (ADH, TLS 1.2
only, with the OpenSSL security level lowered for the connection), which is what `api-ssl` of a router without a
certificate uses. It is **not** limited to that case: when the router has a certificate, the connection succeeds
as well (ECDHE-RSA was negotiated) and the certificate is not checked at all. Use it only where `verified()` and
`fingerprint()` are not possible. A router without a certificate on `api-ssl` has nothing to verify or pin:
`verified()` is refused there (`ConnectionException`, measured on 7.24.5); give the router a certificate instead.

<!-- run: tls-unverified -->
```php
use Lossik\Mikrotik\Api\ApiOptions;
use Lossik\Mikrotik\Api\ApiTransport;
use Lossik\Mikrotik\Api\TlsOptions;

$options = new ApiOptions(tls: TlsOptions::unverified());   // DANGEROUS: no authentication of the router
$transport = ApiTransport::connect('192.0.2.1', 'admin', 'secret', $options);
```

A failed handshake is a `ConnectionException` whose message carries the reason from OpenSSL (`certificate verify
failed`, `peer_fingerprint match failure`, ...); nothing is sent to the router.

**IPv6 hosts: pass the address without brackets** (`'2001:db8::1'`, `'::1'`). The address is put into brackets for
the socket and the name `verified()` checks stays the bare address. If you write the brackets yourself (`'[::1]'`),
the socket address is used as written, but the name `verified()` checks is the host exactly as written, `[::1]`,
which is not a name a certificate carries: give `peerName` explicitly in that case. `fingerprint()` and
`unverified()` do not use the name.

## Errors

Every runtime error implements the marker interface `Lossik\DeviceCommunication\CommunicationException`:

```
CommunicationException                     (interface)
|- ConnectionException                     closes the transport
|    |- TimeoutException                   ->limit: 'connectTimeout' | 'idleTimeout' | 'responseTimeout' | 'cancelTimeout'
|    |- FatalException                     ->reason (!fatal, e.g. after /quit)
|    '- ProtocolException
|- AuthenticationException                 wrong user name or password; from connect() only
'- TrapException                           the router refused the command; the connection stays open
\UnsupportedOperationException             not a CommunicationException (\LogicException); not thrown by ApiTransport
```

| exception | when | after it |
|---|---|---|
| `ConnectionException` | the router is unreachable, the TLS handshake failed, the router closed the connection (EOF, also in the middle of a word), a call on a closed transport | closed |
| `TimeoutException` | `->limit` names the option that ran out | closed |
| `FatalException` | `!fatal`: the router ends the session | closed |
| `ProtocolException` | a reply with a missing or foreign `.tag`, a control byte in place of a word length, a sentence without a type, an unknown sentence type, a malformed login challenge, a word from the router that is not valid in the configured charset | closed |
| `AuthenticationException` | wrong credentials (also at the pre-6.43 login); no transport is returned | - |
| `TrapException` | `!trap`: `->getMessage()` is the message of the first trap, `->category` its `category` (`?int`), `->traps()` all traps of the response with their attributes | **open** |
| `\InvalidArgumentException` | a word of the command cannot be converted to `charset`; nothing was sent | open |

After any `ConnectionException` (or subclass) every further call throws `ConnectionException` without I/O; create a
new transport. `$transport->isOpen()` tells whether it is still usable. An unknown command makes RouterOS send two
`!trap` sentences (measured: the first with `category` 0), so `traps()` has two entries.

<!-- run: trap-multi -->
```php
use Lossik\DeviceCommunication\Client;
use Lossik\DeviceCommunication\ConnectionException;
use Lossik\DeviceCommunication\TimeoutException;
use Lossik\DeviceCommunication\TrapException;
use Lossik\Mikrotik\Api\ApiTransport;

$transport = ApiTransport::connect('192.0.2.1', 'admin', 'secret');
$client = new Client($transport);

try {
    $client->menu('/ip/nonexistent')->print();
} catch (TrapException $e) {
    // refused: the message and category of the router; the connection is still usable
    $report = [$e->getMessage(), $e->category, count($e->traps()), $transport->isOpen()];
} catch (TimeoutException $e) {
    $report = ['timeout', $e->limit];   // the transport is closed
} catch (ConnectionException $e) {
    $report = ['connection lost'];      // the transport is closed
}
```

<!-- run: silence -->
```php
use Lossik\DeviceCommunication\Client;
use Lossik\DeviceCommunication\TimeoutException;
use Lossik\Mikrotik\Api\ApiOptions;
use Lossik\Mikrotik\Api\ApiTransport;

$transport = ApiTransport::connect('192.0.2.1', 'admin', 'secret', new ApiOptions(idleTimeout: 2.0));
try {
    (new Client($transport))->menu('/ip/address')->print();   // the router answers nothing
} catch (TimeoutException $e) {
    $limit = $e->limit;                // 'idleTimeout', after 2 s
    $stillOpen = $transport->isOpen();  // false
}
```

## Streaming

`listen` runs the `listen` command of a menu and hands every row to the callback as it arrives. It returns when the
router finishes the command, or when the callback returns `false` (or throws): the client then sends `/cancel`,
reads the replies until both the command and the `/cancel` are done, and the transport is usable again (an exception
of the callback is rethrown after that). The `interrupted` trap of the cancelled command is not an error.

<!-- run: listen -->
```php
use Lossik\DeviceCommunication\Client;
use Lossik\Mikrotik\Api\ApiTransport;

$transport = ApiTransport::connect('192.0.2.1', 'admin', 'secret');
$client = new Client($transport);

$seen = [];
$client->menu('/interface')->listen(function (array $row) use (&$seen): bool {
    $seen[] = $row;

    return count($seen) < 2;   // false = cancel
}, idleTimeout: 120.0);

$version = $client->version();   // the connection works after the cancel
```

- **Shape of the rows** (measured on `/interface`, 7.24.5 and 7.23.8): there is no row for the state before `listen`
  started; a row carries the item, not only the changed property; a removed item arrives as `.id` and
  `.dead` = `'true'` and nothing else.
- **`idleTimeout`** of `listen` (default `null`) is the longest silence of the router; when it runs out, `listen`
  throws `TimeoutException` (`->limit === 'idleTimeout'`) and the transport is closed. It is **not** the
  `idleTimeout` of `ApiOptions`, and `responseTimeout` does not apply to a stream.
- **There is no keepalive.** TCP keepalive would need `ext-sockets`, which the package does not use. With
  `idleTimeout: null` a router that died without closing the connection (a pulled cable, a firewall that dropped the
  state) is never noticed. Over an unreliable path give `listen` an `idleTimeout` longer than the quietest period
  you expect, and reconnect on `TimeoutException`; a healthy but silent stream ends that way too.
- **`cancelTimeout`** (10 s) bounds how long the router may take to finish a cancelled command; after that
  `TimeoutException` (`->limit === 'cancelTimeout'`) and the transport is closed.
- **A rebooting router** ends `listen` in no fixed way (measured: it returns normally after `!done`, or throws
  `ConnectionException`, or, rarely, `TimeoutException`). What holds in every case: with an `idleTimeout`, `listen`
  ends within it; it does not hang; and the next call on that transport throws `ConnectionException`.
- While `listen` runs, **no other command may use that transport**, including one issued from the callback; open a
  second connection for that.

## Charset

By default (`charset: 'UTF-8'`) the bytes of every word pass unchanged in both directions. RouterOS 7 stores and
returns the bytes it receives, and its console shows them as UTF-8 (measured on 7.23.8 and 7.24.5; not measured on
6.x). Any other charset (e.g. `'windows-1250'`) converts every word with `iconv` in both directions.

2.x defaulted to `WINDOWS-1250`. If you stored data through 2.x with that default, the router holds cp1250 bytes
that are not valid UTF-8: read them with `charset: 'windows-1250'` (and write with it, to keep the old encoding).

A character the charset cannot represent is an error instead of the silent `//IGNORE//TRANSLIT` of 2.x:

- in a command: `\InvalidArgumentException` before anything is sent; the connection stays open (from `connect()`:
  the user name or password cannot be converted, no connection is returned);
- in a reply of the router: `ProtocolException`, the transport is closed.

<!-- run: charset -->
```php
use Lossik\DeviceCommunication\Client;
use Lossik\Mikrotik\Api\ApiOptions;
use Lossik\Mikrotik\Api\ApiTransport;

$transport = ApiTransport::connect('192.0.2.1', 'admin', 'secret', new ApiOptions(charset: 'windows-1250'));
$client = new Client($transport);

$client->menu('/ip/firewall/address-list')->run('add', ['list' => 'x', 'address' => '192.0.2.9', 'comment' => 'Příliš žluťoučký kůň']);
try {
    $client->menu('/ip/firewall/address-list')->run('add', ['list' => 'x', 'address' => '192.0.2.10', 'comment' => '✓']);
} catch (\InvalidArgumentException) {
    $stillOpen = $transport->isOpen();   // true; nothing was sent
}
```

## Known limits

- **One command at a time per connection.** The transport has no queue and no locks; do not share it between
  concurrent users and do not issue a command from inside a `listen` callback. Use one connection per consumer.
- **`responseTimeout` is off by default**: a peer that sends one byte at a time is never cut off (see Options).
- **No keepalive** (see Streaming): a dead peer without FIN is noticed only through an `idleTimeout`.
- **Long silent commands**: `/system/backup/save` and a large `/tool/fetch` can stay silent for longer than the
  default `idleTimeout` of 60 s; raise `idleTimeout` for such a connection.
- **`Query::where('key', '')` matches nothing** (RouterOS answers `!empty`), it does not mean "any value". A
  property with an empty value (an empty `comment`) is not returned by `print` at all: the key is missing from the row.
- **Replies are always matched by `.tag`**: RouterOS 7.23.8 and 7.24.5 tag every sentence except `!fatal`. A router
  that left a sentence untagged would end the connection with `ProtocolException`.
- **Not covered by the live tests**: IPv6 hosts, `listen` over TLS, RouterOS before 7.23.8 through this library.

## Tests

`composer test` runs the unit suite against a fake RouterOS server (`tests/Support/FakeRouter.php`); `composer ci`
adds `phpcs` and PHPStan. The live suite runs against a real CHR in QEMU inside one `php` container, with Docker as
the only requirement: see [tools/chr/README.md](tools/chr/README.md).

## Supported PHP

Only patched PHP releases are supported: **8.2.35+, 8.3.36+, 8.4.27+ and 8.5.12+** (CVE-2026-109618 and
CVE-2026-109619, "PHP2Shell"). `composer.json` requires `"php": "^8.2"`; only the CI of this project enforces the
patched minima.

## License

MIT, see [LICENSE](LICENSE).
