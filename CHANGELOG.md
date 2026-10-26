# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows
[Semantic Versioning](https://semver.org/).

## [3.0.0] - Unreleased

A rewrite on top of `lossik/device-communication` 3.0, a transport-independent contract. No class of 2.x remains, so
mixed code fails with "Class not found" instead of changing behaviour silently. The upgrade guide (in Czech) is
`docs/upgrade-3.0.cs.md`.

### Changed

- **New namespace `Lossik\Mikrotik\Api\`.** `ApiTransport::connect($host, $user, $password, ApiOptions)` replaces
  `Connection`, `Options` and `Command`; commands run through `Client` and `Menu` of device-communication.
  `Options` (`port`, `ssl`, `sslPort`, `timeout`, `localCharset`, `remoteCharset`) becomes `ApiOptions` (`port`,
  `connectTimeout`, `idleTimeout`, `responseTimeout`, `cancelTimeout`, `charset`, `tls`) and `TlsOptions`.
- Requires `lossik/device-communication` `^3.0`, `ext-openssl` (2.x: only suggested) and `ext-iconv`.
- **Supported are only patched PHP releases: 8.2.35+, 8.3.36+, 8.4.27+ and 8.5.12+**, as in 2.1.0.
- Policy: the PHP minimum is raised only in a minor release, with a note in this changelog.
- **Menu API** (`Menu`, from device-communication): `print`, `first`, `add`, `update`, `delete`, `run`, `listen` and
  `Client::version()`. `get($where, $callback)` becomes `print(Query)` plus a filter in PHP, `command()` becomes
  `run()`, `del` becomes `delete`.
  - `first()` returns `null` when nothing matches (2.x `getOneItem()`: `[]`).
  - `add()` returns the ID as `?string` whenever the router sends `=ret=`, also when it arrives in another TCP
    segment than `!done`; `null` when it sends none (2.x: the ID only when both came in one segment, otherwise `[]`).
  - `update()` and `delete()` return the number of items; when the query matches nothing, nothing is sent (2.x sent
    an empty `.id=`).
- **Removed**: the deprecated aliases `addItem`, `updateItem`, `updateItems`, `updateOneItem`, `getItem`,
  `getItems`, `getOneItem`, `delItem`, `delItems`, `delOneItem`; the magic command methods (`__call`); `FormatHelper`;
  the static helpers `Definition::arrayChangeKeyName()` and `Definition::isIterable()`.
- **The `~` key prefix is gone.** In 2.x `Connection::comm()` sent `k~v`, which RouterOS does not support, and
  `Command::get()` filtered by prefix on the client for a value starting with `~`. `Query` has no such condition;
  filter the rows in PHP (`array_filter()` with `str_starts_with()`).
- **Typed exceptions** instead of one `RuntimeException` with numeric codes: `ConnectionException` (unreachable,
  TLS handshake, EOF, a call on a closed transport), `TimeoutException` (`->limit`), `FatalException`,
  `ProtocolException`, `AuthenticationException` and `TrapException` (`->category`, `->traps()`: all traps of the
  response, where 2.x kept the first message only). After a `ConnectionException` or a subclass the transport is
  closed and every further call throws `ConnectionException`; a `TrapException` leaves it open.
- **Time limits** replace the single `Options::$timeout` (3 s, used for connecting and for reads): `connectTimeout`
  10 s (`TimeoutException` with `->limit === 'connectTimeout'`), `idleTimeout` 60 s (silence while a response is
  read), `responseTimeout` (off by default; not applied to `listen`) and `cancelTimeout` 10 s. A router that goes
  silent or closes the connection ends the call with an exception instead of blocking it.
- **Charset**: `UTF-8`, passed through unchanged, is the default (2.x: `WINDOWS-1250`). Any other `charset` (e.g.
  `windows-1250`) converts every word both ways. A character the charset cannot represent is an error:
  `\InvalidArgumentException` before anything is sent (the connection stays open), or `ProtocolException` when it
  comes from the router; 2.x dropped or transliterated it silently (`//IGNORE//TRANSLIT`). Data stored through 2.x
  with the old default are cp1250 bytes: read them with `charset: 'windows-1250'`.
- **TLS** is verified by default: `TlsOptions::verified()` (chain and host name), `TlsOptions::fingerprint()` (a
  pinned self-signed certificate) or, explicitly, `TlsOptions::unverified()`, which encrypts but does not
  authenticate the router and is **dangerous**. `ApiOptions::$tls` selects TLS and the port 8729 unless `port`
  says otherwise. 2.x always offered `ADH:ALL` and checked nothing; that did not even connect to a router without
  a certificate on OpenSSL 1.1.0+.
- **Replies are matched by `.tag`.** A reply without a `.tag` (except `!fatal`) or with a foreign one is a
  `ProtocolException`. Every RouterOS of the test matrix (6.34 … 7.25rc3) tags every sentence it sends except
  `!fatal`.
- **Supported RouterOS**: the whole 7.x line, tested at its boundaries, and 6.x from 6.34 (the first CHR image);
  other versions go the same way without a guarantee, nothing is refused because of the version. The README lists
  what is tested on every CI run, weekly, only by hand and not at all.

### Fixed

The known issues of 2.x:

- **Values** are sent and read whole: `=comment=a=b=c` is `a=b=c` (2.x: `a`), a multi-line value stays one word, and
  leading and trailing spaces are kept (2.x split on newlines and trimmed).
- **A reply is read sentence by sentence up to `!done`**, not up to what PHP's stream buffer holds: a reply split
  across TCP segments is read whole, the `!done` after a `!trap` is not left for the next command, `=ret=` of `add`
  is never lost, and the login reply is read the same way.
- **The read loop ends**: silence is a `TimeoutException`, EOF (also inside a word or a word length) a
  `ConnectionException`, with no endless loop; a pause inside a multi-byte word length no longer throws `TypeError`.
- **After `!fatal` or `close()`** the transport reports itself closed (`isOpen()`), and a command on it throws
  `ConnectionException` instead of running into the read loop or `TypeError`.

### Added

- Every command carries a `.tag`; **`listen`** streams the rows of a long-running command and is cancelled with
  `/cancel` (the callback returns `false`), bounded by `idleTimeout` and `cancelTimeout`. Rows of `/interface`
  come without the state before `listen`, a removed item as `.id` and `.dead`.
- `Query` conditions `where`, `has`, `hasNot`, `less`, `greater`, `and`, `or`, `not`, sent as the stack words of
  the API (`?k=v`, `?k`, `?-k`, `?<k=v`, `?>k=v`, `?#&`, `?#|`, `?#!`).
- A live test suite against a real CHR in QEMU (`tools/chr`): RouterOS 7.24.5 and 6.49.18 on every CI run; 7.23.8,
  7.17.2, 7.16.2, 7.12.2, 7.1, 6.49.23 (installed as an upgrade from 6.49.20), 6.43, 6.42.12, 6.34 and 7.25rc3 (testing
  channel, allowed to fail) weekly. On CHR 6.x the harness enables the `ipv6` package.

### Notes

- RouterOS before 6.43 logs in with the MD5 challenge, as in 2.x, automatically: the client sends the login of 6.43
  and newer first and answers the challenge of an older router on the same connection. The live suite logs in this
  way on 6.34 and 6.42.12; 6.43 accepts both logins. The first `/login` sentence carries the password in clear text
  (the old router ignores it and sends the challenge), so use TLS on any network you do not trust.
- Known limits: one command at a time per connection; no TCP keepalive (it would need `ext-sockets`), so a router
  that died without closing the connection is noticed only through an `idleTimeout`; without `responseTimeout` a
  peer that sends one byte at a time is never cut off; `less()`/`greater()` with an IPv4 address compare as text on
  RouterOS 6.42.12 … 7.25rc3 (the console, and so `lossik/mikrotik-ssh`, as addresses); `has()` is the existence of
  a property, also with an empty value (`lossik/mikrotik-ssh`: a non-empty value). See the README.
- When the router reboots under a running `listen`, the call ends normally, with `ConnectionException` or, rarely,
  with `TimeoutException`; it does not hang, and the next call on the transport throws `ConnectionException`.

## [2.1.1] - 2026-10-22

Fixes of the 2.1.0 audit. `src/` and the public API are unchanged.

### Fixed

- `composer.json` allowed `lossik/device-communication` 2.0.0, which lacks the `Command` class this package extends;
  the minimum is now `^2.0.1` (later 2.x releases stay allowed).

### Tests and CI

- New CI job `lowest` (`tools/ci.sh --prefer-lowest`) runs the suite against the lowest allowed version of every
  dependency, which would have caught the wrong minimum above.
- `EofDeprecationTest` fails when the client dies (fatal error) instead of being stopped by the test after the
  watch window.
- New `DefinitionLengthTest` covers every shape of a word length prefix (1 to 5 bytes) and a connection closed after
  each byte of it.

## [2.1.0] - 2026-10-22

Maintenance release of the 2.x line: it runs on patched PHP and is MIT licensed. The public API is unchanged and
the protocol defects of 2.x are **not** fixed (see Known issues; they are fixed in 3.0).

### Changed

- Requires PHP `^8.2` (was `>=7.1`). Users on older PHP stay on 2.0.x.
- **Supported are only patched PHP releases: 8.2.35+, 8.3.36+, 8.4.27+ and 8.5.12+** (CVE-2026-109618 and
  CVE-2026-109619, "PHP2Shell"). Composer cannot tell a patched build from an unpatched one, because Linux
  distributions backport the fixes without changing the version number, so `composer.json` says `^8.2` and only
  the CI of this project enforces the patched minima.
- Policy: the PHP minimum is raised only in a minor release, with a note in this changelog.
- Licensed under the MIT license (`LICENSE`).
- `ext-iconv` is now declared in `require` (every word is converted between charsets); `ext-openssl` is suggested,
  it is needed only for TLS connections (`Options::$ssl = true`).

### Fixed

- PHP 8.4+: no deprecation for the implicitly nullable parameter of `Connection::__construct()`
  (now `?Options $options = null`).
- PHP 8.5: no `ord(): Providing an empty string is deprecated` while reading word lengths; an empty read counts as
  0, exactly as `ord('')` did on PHP 8.2-8.4.

### Known issues

Not fixed in 2.x; fixed in 3.0.

- **The read loop never ends when the router goes silent or closes the connection.** A reply is read until
  `!done`/`!trap`/`!fatal`; after a read timeout the client waits again, and at EOF it spins with full CPU.
  Before 2.1.0 the spin flooded the log with `ord()` deprecations on PHP 8.5; **since 2.1.0 it spins silently**.
  A connection closed inside a word hangs the same way.
- **`!trap` split from its `!done`:** when `!done` arrives in a later TCP segment, reading stops after `!trap`;
  the leftover `!done` is read by the next command, and from then on every command on the connection returns the
  reply of the previous one.
- **End of a reply is decided by PHP's stream buffer** (`unread_bytes`), not by the protocol: a reply split across
  TCP segments can be cut short, e.g. `!done` and `=ret=` of `add` in two segments lose the new item's ID; the
  login reply is read the same way.
- **Values containing `=` are truncated:** `=comment=a=b=c` is returned as `a`.
- **Values are split on newlines and trimmed when sent:** a multi-line value (e.g. a script source) becomes several
  words, leading and trailing spaces are lost.
- **Magic command methods with arguments** (`$command->print([...])` through `__call`) send `=0=Array`.
- **The `~` key prefix in `Connection::comm()`** sends `k~v`, which the RouterOS API does not support (it has no
  regular expressions). `Command::get()` with a value starting with `~` filters by prefix on the client instead.
- **TLS:** the client offers only `ADH:ALL` ciphers and does not verify the router's certificate. Without a
  certificate on the router the handshake fails (OpenSSL 1.1.0+ excludes anonymous ciphers at its default
  security level); with a certificate it works, but the router's identity is not checked.
- **No `.tag` support:** commands cannot run concurrently on one connection; streaming commands (`listen`,
  `monitor` without `once`) and `/cancel` are not available.
- **Errors:** of several `!trap` sentences only the first message is used, `=category=` is dropped; the exception
  carries neither the category nor an ID.
- **After `!fatal`** the connection still reports itself connected and logged in; the next command runs into the
  endless read loop.
- **A command after `disconnect()`** throws `TypeError` (an `\Error`, not an `\Exception`), and `isLoged()` stays
  `true`.
- **A read timeout inside a multi-byte word length** (words of 128 bytes or more, the router pausing longer than
  `Options::$timeout` between the length bytes) throws `TypeError` on PHP 8.1+.
- `FormatHelper::unprettySpeed()` throws `TypeError` on every PHP version and mishandles the `G`/`M`/`k` suffixes.

## [2.0.8] and older

Released without a changelog; see the git history.
