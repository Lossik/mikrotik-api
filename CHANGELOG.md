# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows
[Semantic Versioning](https://semver.org/).

## [2.1.1] - Unreleased

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
