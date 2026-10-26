# tools/chr — MikroTik CHR for live tests

Boots a MikroTik Cloud Hosted Router (CHR, RouterOS 6.34 … 7.x) in QEMU **inside the same `php:8.2.35-cli` container**
that runs the PHP side, provisions it over the RouterOS API and runs a smoke test and the live test suite
(`phpunit --group live`) against it. The same command works locally, in GitLab CI and in GitHub Actions: QEMU forwards
the router ports to the container loopback, so no Docker ports are published and nothing runs on the host.

| container port (127.0.0.1) | router service |
|---|---|
| 18728 | API (8728) |
| 18729 | API-SSL (8729) |
| 18722 | SSH (22) |

The CHR image is **downloaded from download.mikrotik.com** (`chr-<version>.img.zip`), checked against the SHA256 in
`versions.txt` and cached. It is not redistributed: there is no CHR image of ours in any registry. A version missing
from `versions.txt`, an incomplete row, a failed download or a SHA256 mismatch (including a corrupted cached zip) is an
error, never a skip. Every run boots a fresh copy of the image in `/tmp`, so each run starts with a clean router.

## Files

| file | purpose |
|---|---|
| `run-live.sh` | the whole lifecycle. It first validates the row of `CHR_VERSION`, builds the plan of the run and **prints it**, then executes exactly that plan: PHP version check, `tools/ci.sh --deps-only`, `chr.sh install`, `start`, `wait`, `provision.php` (for an upgrade row: base image, `provision.php`, `upgrade.php`, `provision.php` again), `chr.sh wait-ssh`, `smoke.php` (on failure one more `provision.php` and `smoke.php`, then the run fails), `smoke.php --ssh-login`, `timeout $CHR_LIVE_TIMEOUT vendor/bin/phpunit --group live --exclude-group live-<other path> --fail-on-empty-test-suite` (a selection without a test is a failure, not a green run), `chr.sh stop` (also on failure); prints the duration of every step. Extra arguments are passed to PHPUnit. Run it from the repository root |
| `chr.sh` | `install` (apt: `qemu-system-x86`, `unzip`), `start` (refuses `upgrade:` rows — they are booted by `run-live.sh`), `wait` (API `/login`), `wait-ssh` (one patient connection until an `SSH-` banner line; a fresh CHR sends none for a while after the API is up, and every broken connection costs the router seconds), `stop`, `status` |
| `provision.php` | idempotent setup: admin password, read-only user `nopass` with empty password, services `api`/`api-ssl`/`ssh`, self-signed certificate `test` for `api-ssl` (assigning it is retried for up to 15 s); when the router has the package `ipv6` installed and disabled (every CHR 6.x image), enables it, reboots and waits until admin logs in and the package reports enabled (`CHR_REBOOT_TIMEOUT`) — the condition is the package state, not the version; with `--defer-reboot` (base image of an `upgrade:` row) it only schedules the enable, and the upgrade reboot applies it |
| `upgrade.php` | upgrade of a booted router with `routeros-x86-<version>.npk` (SFTP upload, reboot, wait); fails unless `/system/resource` reports the target version and every enabled package has exactly that version. SFTP via phpseclib when the repository has it, otherwise via `curl` (libssh2) with the credentials in a temporary config file |
| `smoke.php` | logs in and checks `/system/resource/print` reports `CHR_VERSION`; `--login-only` is the readiness probe of `chr.sh wait`, `--ssh-banner` of `chr.sh wait-ssh`; `--ssh-login` does one full SSH login and one command outside PHPUnit, so the live tests meet a warmed-up sshd (skipped with a message where the repository does not depend on phpseclib, i.e. mikrotik-api; a failure where it does) |
| `routeros.php` | minimal raw-socket RouterOS API client used by the PHP scripts; logs in with `/login =name= =password=` and answers the MD5 challenge of RouterOS before 6.43 |
| `versions.txt` | known CHR versions, see below |
| `tests/run.sh` | checks of the harness itself without a router (OH1–OH8: row validation, SHA256 check, both logins, patient banner wait, the printed and the executed plan, `--ssh-login`, the upgrade checks, enabling the `ipv6` package); the CI job runs it first |

All scripts are invoked as `bash <script>` / `php <script>`; they do not rely on the executable bit.

## versions.txt

One row per version: `<version> <channel> <sha256> <ssh-path> <install> <sha-source>`.

| column | values |
|---|---|
| `channel` | the text in parentheses the router reports in `version` (`stable`, `long-term`, `testing`) |
| `sha256` | of `chr-<version>.img.zip`; for an `upgrade:` row of the `.npk` |
| `ssh-path` | `json` (RouterOS 7.17 and newer) or `text` — the console path the SSH library selects; the live suite excludes the group of the other path (`live-text` / `live-json`) |
| `install` | `image` (boot the official image) or `upgrade:<base>` (boot the image of the `<base>` row and upgrade it with the NPK) |
| `sha-source` | `official` (the server publishes `<file>.sha256`) or `computed` (it does not — HTTP 404 — and the hash was computed locally; a changed file on the server is then reported as a SHA256 mismatch) |

Lines starting with `#` and a trailing `# …` on a row are comments; any other extra column is an error. An `upgrade:`
row needs `sha-source=official` and a `<base>` row with `install=image`.

**RouterOS 6.49.23 is tested as an installation upgraded from 6.49.20.** Fresh official CHR images 6.49.21 and newer
lose their configuration in QEMU (provisioned users, certificates, after the first SSH contact or a reboot) — a defect
of the image, not of the libraries; the same version installed by upgrade keeps it. 6.49.19–6.49.22 are not tested.
On CHR 6.x the `ipv6` package is installed but disabled; `provision.php` enables it (one extra reboot per job: 30–65 s
measured on 6.34 … 6.49.18, once 304 s on 6.34; on an `upgrade:` row the upgrade reboot does it).

## Running locally

Docker is the only requirement. From the repository root:

PowerShell:

```powershell
docker run --rm -v "${PWD}:/app" -v "$HOME/.cache/lossik-chr:/cache" -e CHR_CACHE=/cache -e CHR_VERSION=7.24.5 -w /app php:8.2.35-cli bash tools/chr/run-live.sh
```

Git Bash on Windows (`MSYS_NO_PATHCONV=1` stops MSYS from rewriting the `-v`/`-w` paths; `pwd -W` gives a Windows path):

```bash
mkdir -p ~/.cache/lossik-chr
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" -v "$(cd ~/.cache/lossik-chr && pwd -W):/cache" -e CHR_CACHE=/cache -e CHR_VERSION=7.24.5 -w /app php:8.2.35-cli bash tools/chr/run-live.sh
```

Linux / macOS:

```bash
docker run --rm -v "$PWD:/app" -v "$HOME/.cache/lossik-chr:/cache" -e CHR_CACHE=/cache -e CHR_VERSION=7.24.5 -w /app php:8.2.35-cli bash tools/chr/run-live.sh
```

Another version: `-e CHR_VERSION=6.49.18`; `CHR_VERSION` is required, `run-live.sh` fails without it. The checks of the harness alone: `bash tools/chr/tests/run.sh` (no QEMU).

On a Linux host with KVM add `--device /dev/kvm`; `chr.sh start` prints which accelerator it used (`kvm` or `tcg`).
Without KVM (Windows, macOS, most CI runners) QEMU emulates the CPU (TCG): the router needs 30–90 s to boot (6.34 the
longest), the first SSH login can take up to about 40 s more.

## Environment

| variable | default | meaning |
|---|---|---|
| `CHR_VERSION` | none for `run-live.sh` (required; empty or unset is an error), `7.24.5` for `chr.sh` alone | RouterOS version; must have a complete row in `versions.txt` |
| `CHR_CACHE` | `$HOME/.cache/lossik-chr` | directory with the downloaded `chr-<version>.img.zip` and `routeros-x86-<version>.npk` |
| `CHR_BOOT_TIMEOUT` | `300` | seconds: `chr.sh wait` (reply to `/login`) counts them from `chr.sh start`; `chr.sh wait-ssh` waits that long on its one connection; also the limit of `smoke.php --ssh-login` and of the reboot wait in `upgrade.php` |
| `CHR_LIVE_TIMEOUT` | `300` for `ssh-path=json`, `600` for `text` | seconds for the PHPUnit live phase (`timeout`); a positive integer |
| `CHR_PASSWORD` | random per run (`run-live.sh`) | admin password set by `provision.php`; the PHP scripts refuse to run without it |
| `CHR_REBOOT_TIMEOUT` | `600` | seconds `provision.php` waits after the reboot that enables the `ipv6` package (6.34 once needed 304 s) |
| `CHR_RUN_DIR` | `/tmp/lossik-chr` | PID file, serial log and the booted image copy |
| `CHR_SSH_PORT` | `18722` | SSH port the PHP scripts connect to (the harness checks use it for a fake server) |
| `CHR_PUBLISH` | unset | debug only: `1` forwards the ports on `0.0.0.0` instead of `127.0.0.1`, so they can be published with `docker run -p 127.0.0.1:18728:18728 …` and reached from the host |

`run-live.sh` also exports the settings for the live tests: `MIKROTIK_LIVE_SSH_PATH` (the `ssh-path` column),
`MIKROTIK_LIVE_HOST`, `MIKROTIK_LIVE_PORT_API`, `MIKROTIK_LIVE_PORT_TLS`, `MIKROTIK_LIVE_PORT_SSH`, `MIKROTIK_LIVE_USER`,
`MIKROTIK_LIVE_PASS`.

Only patched PHP releases are supported: `run-live.sh` fails on PHP below 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12 and on
any PHP outside 8.2–8.5.

## CI

- **GitLab CI**: the per-push smoke remains **7.24.5** and **6.49.18**. The old weekly jobs have been removed;
  there is one source of the full matrix, versions.txt, on GitHub.
- **GitHub CI** (ci.yml): every push to master, 3.x or feature/**, and every PR, runs every distinct version in
  versions.txt with fail-fast disabled, PHP 8.2.35 and KVM. The row controls text/json and image/upgrade exactly as
  for a manual run. A push to routeros-tested does not trigger CI.

## New RouterOS releases

routeros-latest.yml checks MikroTik's HTTPS NEWESTa7.stable, NEWESTa7.testing and NEWESTa7.long-term endpoints
at 05:17 UTC daily. For a manual check use the **Latest RouterOS ? Run workflow** button on master, or:

```sh
gh workflow run routeros-latest.yml --ref master
```

A version already in versions.txt or in routeros-tested is skipped, including recorded failures. If several channels
point at the same version, it runs once and the record lists all those channels. To retry a recorded failure,
maintainers must deliberately remove its record from the data branch before dispatching again.
The official chr-<version>.img.zip.sha256 is fetched over HTTPS, then the image is checked before booting.
latest-run.sh supplies CHR_OFFICIAL_SHA256 for this explicit unpinned path; a pinned version always uses the
committed checksum even if that variable is set. No checksum verification is disabled.

Only the recording job has contents:write. It creates/updates the literal routeros-tested ref as an orphan data
branch with tested.jsonl (version, channels, official SHA256, UTC date, result and run URL) and last-check.txt;
master is never written. GitHub's contents permission cannot itself be restricted to a single branch, so the writer
hardcodes that ref. Scheduled/manual runs are restricted to master; PRs never run the data writer.

At release time, fetch the data branch and import into your release/feature branch (do **not** merge the orphan):

```sh
git fetch github routeros-tested
git show FETCH_HEAD:tested.jsonl > /tmp/routeros-tested.jsonl
php tools/chr/import-tested /tmp/routeros-tested.jsonl
# Optional target copy for a dry run:
php tools/chr/import-tested /tmp/routeros-tested.jsonl /tmp/versions.txt
```

Only passed records with an official SHA256 are imported. Import is idempotent, never downgrades a channel, and
replaces uncommented heads of that channel. A row with a trailing # reason is a behavior boundary and is preserved
verbatim, as are the oldest supported versions and the upgrade's base image (annotated in versions.txt).
New 7.x versions use install=image, sha-source=official and json from 7.17 (otherwise text).
Review the diff and commit versions.txt with the release; the workflow never edits it.

A new release's failed live run stays red and opens a GitHub issue, or comments on an existing open issue for the
same version. The issue includes the run URL, channels and the tail of the failing log; full logs/JUnit are artifacts.
Enable GitHub Actions failure email notifications: scheduled-run notifications go to the last user who changed the
cron expression. The ordinary code-change matrix uses red checks, without failure issues.

HTTP 404/410 for an image/NPK is reported as unavailable,
with a deduplicated issue suggesting manual removal from the matrix. On PRs it is reported in the job log, since
fork tokens cannot create issues. Network errors are retried and then fail normally; a missing official checksum or
a SHA256 mismatch always fails.
No backup image is kept. When removing a boundary, state the version, reason and lost live coverage openly in
README/CHANGELOG; if necessary move the oldest-supported version. The workflow never removes versions itself.

GitHub disables scheduled workflows in public repositories after 60 days without repository activity. The data
writer refreshes last-check.txt at most once per 30 days even with no new version. Check Actions if scheduling is
nevertheless disabled; a maintainer must re-enable it. Schedule timing is best effort, not a release SLA.

When a run fails, `run-live.sh` prints the tail of the CHR serial log before stopping QEMU.
