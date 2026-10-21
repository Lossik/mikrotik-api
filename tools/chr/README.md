# tools/chr — MikroTik CHR for live tests

Boots a MikroTik Cloud Hosted Router (CHR, RouterOS 7.x) in QEMU **inside the same `php:8.2.35-cli` container** that runs
the PHP side, provisions it over the RouterOS API and runs a smoke test against it. The same command works locally,
in GitLab CI and in GitHub Actions: QEMU forwards the router ports to the container loopback, so no Docker ports are
published and nothing runs on the host.

| container port (127.0.0.1) | router service |
|---|---|
| 18728 | API (8728) |
| 18729 | API-SSL (8729) |
| 18722 | SSH (22) |

The CHR image is **downloaded from download.mikrotik.com** (`chr-<version>.img.zip`), checked against the SHA256 in
`versions.txt` and cached. It is not redistributed: there is no CHR image of ours in any registry. A version missing
from `versions.txt`, a failed download or a SHA256 mismatch (including a corrupted cached zip) is an error, never a
skip. Every run boots a fresh copy of the image in `/tmp`, so each run starts with a clean router.

## Files

| file | purpose |
|---|---|
| `run-live.sh` | the whole lifecycle: PHP version check, `chr.sh install`, `start`, `wait`, `provision.php`, `smoke.php`, `chr.sh stop` (also on failure); prints the duration of every step |
| `chr.sh` | `install` (apt: `qemu-system-x86`, `unzip`), `start`, `wait`, `stop`, `status` |
| `provision.php` | idempotent setup: admin password, read-only user `nopass` with empty password, services `api`/`api-ssl`/`ssh`, self-signed certificate `test` for `api-ssl` |
| `smoke.php` | logs in and checks `/system/resource/print` reports `CHR_VERSION`; `--login-only` is the readiness probe of `chr.sh wait` |
| `routeros.php` | minimal raw-socket RouterOS API client used by the two PHP scripts |
| `versions.txt` | known CHR versions with their official SHA256 |

All scripts are invoked as `bash <script>` / `php <script>`; they do not rely on the executable bit.

## Running locally

Docker is the only requirement. From the repository root:

PowerShell:

```powershell
docker run --rm -v "${PWD}:/app" -v "$HOME/.cache/lossik-chr:/cache" -e CHR_CACHE=/cache -w /app php:8.2.35-cli bash tools/chr/run-live.sh
```

Git Bash on Windows (`MSYS_NO_PATHCONV=1` stops MSYS from rewriting the `-v`/`-w` paths; `pwd -W` gives a Windows path):

```bash
mkdir -p ~/.cache/lossik-chr
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" -v "$(cd ~/.cache/lossik-chr && pwd -W):/cache" -e CHR_CACHE=/cache -w /app php:8.2.35-cli bash tools/chr/run-live.sh
```

Linux / macOS:

```bash
docker run --rm -v "$PWD:/app" -v "$HOME/.cache/lossik-chr:/cache" -e CHR_CACHE=/cache -w /app php:8.2.35-cli bash tools/chr/run-live.sh
```

On a Linux host with KVM add `--device /dev/kvm`; `chr.sh start` prints which accelerator it used (`kvm` or `tcg`).
Without KVM (Windows, macOS, most CI runners) QEMU emulates the CPU (TCG) and the router needs about a minute to boot.

## Environment

| variable | default | meaning |
|---|---|---|
| `CHR_VERSION` | `7.24.5` | RouterOS version; must be listed in `versions.txt` |
| `CHR_CACHE` | `$HOME/.cache/lossik-chr` | directory with the downloaded `chr-<version>.img.zip` |
| `CHR_BOOT_TIMEOUT` | `300` | seconds `chr.sh wait` waits for a reply to `/login` |
| `CHR_PASSWORD` | random per run (`run-live.sh`) | admin password set by `provision.php`; the PHP scripts refuse to run without it |
| `CHR_RUN_DIR` | `/tmp/lossik-chr` | PID file, serial log and the booted image copy |
| `CHR_PUBLISH` | unset | debug only: `1` forwards the ports on `0.0.0.0` instead of `127.0.0.1`, so they can be published with `docker run -p 127.0.0.1:18728:18728 …` and reached from the host |

`run-live.sh` also exports the connection settings for the live tests: `MIKROTIK_LIVE_HOST`, `MIKROTIK_LIVE_PORT_API`,
`MIKROTIK_LIVE_PORT_TLS`, `MIKROTIK_LIVE_PORT_SSH`, `MIKROTIK_LIVE_USER`, `MIKROTIK_LIVE_PASS`.

Only patched PHP releases are supported: `run-live.sh` fails on PHP below 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12 and on
any PHP outside 8.2–8.5.

## CI

- **GitLab CI** (`.gitlab-ci.yml`, job `chr-smoke`): `image: php:8.2.35-cli`, `script: bash tools/chr/run-live.sh`,
  `CHR_CACHE` inside the project directory so the GitLab `cache:` can keep the image zip between pipelines.
  No services, no Docker-in-Docker; QEMU runs with TCG in the job container.
- **GitHub Actions** (`.github/workflows/ci.yml`, job `chr`): enables KVM via a udev rule, caches `.cache/chr` and runs
  the same `docker run … php:8.2.35-cli bash tools/chr/run-live.sh`, adding `--device /dev/kvm` only when the host
  has a writable `/dev/kvm`.

When a run fails, `run-live.sh` prints the tail of the CHR serial log before stopping QEMU.
