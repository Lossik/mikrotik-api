#!/bin/bash
# MikroTik CHR (Cloud Hosted Router) in QEMU, inside the current (Linux) container.
#
#   bash tools/chr/chr.sh install   install QEMU and unzip (apt) when qemu-system-x86_64 is missing
#   bash tools/chr/chr.sh start     download (cached, SHA256-checked) and boot a clean copy of the image
#   bash tools/chr/chr.sh wait      wait until the router answers /login on the API port
#   bash tools/chr/chr.sh wait-ssh  wait until the SSH port sends an "SSH-" banner line
#   bash tools/chr/chr.sh stop      stop QEMU (no-op when not running)
#   bash tools/chr/chr.sh status    print "running" or "not running"
#
# Environment: CHR_VERSION (default 7.24.5), CHR_CACHE (default $HOME/.cache/lossik-chr),
# CHR_BOOT_TIMEOUT (seconds, default 300: 'wait' counts from 'start', 'wait-ssh' waits that long on one connection),
# CHR_RUN_DIR (default /tmp/lossik-chr),
# CHR_PUBLISH=1 (debug only: forward ports on 0.0.0.0 instead of 127.0.0.1).
#
# Forwarded ports (container loopback): 18728 -> API 8728, 18729 -> API-SSL 8729, 18722 -> SSH 22.
set -euo pipefail

DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
CHR_VERSION="${CHR_VERSION:-7.24.5}"
CHR_CACHE="${CHR_CACHE:-$HOME/.cache/lossik-chr}"
CHR_BOOT_TIMEOUT="${CHR_BOOT_TIMEOUT:-300}"
CHR_RUN_DIR="${CHR_RUN_DIR:-/tmp/lossik-chr}"
PID_FILE="$CHR_RUN_DIR/qemu.pid"
SERIAL_LOG="$CHR_RUN_DIR/serial.log"
QEMU_OUT="$CHR_RUN_DIR/qemu.out"
BOOT_STAMP="$CHR_RUN_DIR/boot-start"
DOWNLOAD_URL="https://download.mikrotik.com/routeros/$CHR_VERSION"

log() { echo "chr.sh: $*"; }
die() { echo "chr.sh: ERROR: $*" >&2; exit 1; }

# A PID counts as alive when the process exists and is not a zombie
# (inside a container QEMU may be reparented to a PID 1 shell that reaps it late).
alive() {
	local pid=$1 state
	[ -n "$pid" ] || return 1
	kill -0 "$pid" 2>/dev/null || return 1
	state=$(awk '{print $3}' "/proc/$pid/stat" 2>/dev/null || true)
	[ "$state" != "Z" ]
}

running_pid() {
	local pid
	[ -f "$PID_FILE" ] || return 1
	pid=$(cat "$PID_FILE")
	alive "$pid" || return 1
	echo "$pid"
}

serial_tail() {
	if [ -f "$SERIAL_LOG" ]; then
		echo "--- last 20 lines of the CHR serial log ($SERIAL_LOG) ---"
		tail -n 20 "$SERIAL_LOG" | tr -d '\033' || true
		echo "--- end of serial log ---"
	fi
	if [ -s "$QEMU_OUT" ]; then
		echo "--- QEMU output ($QEMU_OUT) ---"
		tail -n 20 "$QEMU_OUT" || true
		echo "--- end of QEMU output ---"
	fi
}

cmd_install() {
	if command -v qemu-system-x86_64 >/dev/null && command -v unzip >/dev/null; then
		log "QEMU already installed: $(qemu-system-x86_64 --version | head -n 1)"
		return
	fi
	log "installing qemu-system-x86 and unzip (apt)"
	export DEBIAN_FRONTEND=noninteractive
	apt-get update -qq
	apt-get install -y -qq --no-install-recommends qemu-system-x86 unzip >/dev/null
	log "installed: $(qemu-system-x86_64 --version | head -n 1)"
}

expected_sha() {
	awk -v v="$CHR_VERSION" '$1 == v { print $3; exit }' "$DIR/versions.txt"
}

row_install() {
	awk -v v="$CHR_VERSION" '$1 == v { print $5; exit }' "$DIR/versions.txt"
}

cmd_start() {
	local sha install zip actual t0 image_dir image accel bind
	if pid=$(running_pid); then
		die "CHR is already running (pid $pid); run 'chr.sh stop' first"
	fi

	sha=$(expected_sha)
	[ -n "$sha" ] || die "version $CHR_VERSION is not listed in $DIR/versions.txt"
	install=$(row_install)
	case "$install" in
		upgrade:*)
			die "version $CHR_VERSION has install=$install: there is no image to boot; start the base image" \
				"${install#upgrade:} (its own row) - 'CHR_VERSION=$CHR_VERSION bash tools/chr/run-live.sh' does the upgrade"
			;;
	esac

	mkdir -p "$CHR_CACHE" "$CHR_RUN_DIR"
	zip="$CHR_CACHE/chr-$CHR_VERSION.img.zip"
	if [ -f "$zip" ]; then
		log "using cached $zip"
	else
		t0=$(date +%s)
		log "downloading $DOWNLOAD_URL/chr-$CHR_VERSION.img.zip"
		rm -f "$zip.part"
		curl -fsSL --retry 3 -o "$zip.part" "$DOWNLOAD_URL/chr-$CHR_VERSION.img.zip" \
			|| { rm -f "$zip.part"; die "download of chr-$CHR_VERSION.img.zip failed"; }
		actual=$(sha256sum "$zip.part" | awk '{print $1}')
		if [ "$actual" != "$sha" ]; then
			rm -f "$zip.part"
			die "SHA256 mismatch for downloaded chr-$CHR_VERSION.img.zip: expected $sha, got $actual"
		fi
		mv "$zip.part" "$zip"
		log "downloaded in $(( $(date +%s) - t0 ))s"
	fi

	actual=$(sha256sum "$zip" | awk '{print $1}')
	[ "$actual" = "$sha" ] || die "SHA256 mismatch for cached $zip: expected $sha, got $actual (delete the file to download it again)"
	log "SHA256 OK ($sha)"

	command -v qemu-system-x86_64 >/dev/null || die "qemu-system-x86_64 not found; run 'chr.sh install' first"
	command -v unzip >/dev/null || die "unzip not found; run 'chr.sh install' first"

	t0=$(date +%s)
	rm -rf "$CHR_RUN_DIR/image"
	image_dir="$CHR_RUN_DIR/image"
	mkdir -p "$image_dir"
	unzip -q -o "$zip" -d "$image_dir"
	image="$image_dir/chr-$CHR_VERSION.img"
	[ -f "$image" ] || die "chr-$CHR_VERSION.img not found in $zip"
	log "unzipped a fresh copy to $image in $(( $(date +%s) - t0 ))s"

	accel=tcg
	[ -w /dev/kvm ] && accel=kvm
	bind=127.0.0.1
	[ "${CHR_PUBLISH:-}" = "1" ] && bind=0.0.0.0

	rm -f "$SERIAL_LOG" "$QEMU_OUT"
	date +%s >"$BOOT_STAMP"
	qemu-system-x86_64 -m 256 -smp 2 -nographic -monitor none -accel "$accel" \
		-serial "file:$SERIAL_LOG" \
		-drive "file=$image,format=raw,if=ide" \
		-nic "user,model=e1000,hostfwd=tcp:$bind:18728-:8728,hostfwd=tcp:$bind:18729-:8729,hostfwd=tcp:$bind:18722-:22" \
		</dev/null >"$QEMU_OUT" 2>&1 &
	echo $! >"$PID_FILE"
	sleep 1
	if ! alive "$(cat "$PID_FILE")"; then
		serial_tail
		rm -f "$PID_FILE"
		die "QEMU exited right after start"
	fi
	log "started CHR $CHR_VERSION (pid $(cat "$PID_FILE")), accel=$accel, ports on $bind: API 18728, API-SSL 18729, SSH 18722"
}

# Both waits share one budget: CHR_BOOT_TIMEOUT seconds from 'start' (from now when 'start' left no stamp).
boot_deadline() {
	local stamp
	stamp=$(cat "$BOOT_STAMP" 2>/dev/null || true)
	[ -n "$stamp" ] || stamp=$(date +%s)
	echo $(( stamp + CHR_BOOT_TIMEOUT ))
}

cmd_wait() {
	local t0 deadline attempt out pid
	t0=$(date +%s)
	deadline=$(boot_deadline)
	attempt=0
	while :; do
		attempt=$(( attempt + 1 ))
		pid=$(running_pid) || { serial_tail; die "QEMU is not running"; }
		if out=$(php "$DIR/smoke.php" --login-only 2>&1); then
			log "router answered /login after $(( $(date +%s) - t0 ))s (attempt $attempt): $out"
			return
		fi
		if [ "$(date +%s)" -ge "$deadline" ]; then
			echo "chr.sh: last attempt: $out" >&2
			serial_tail
			die "no /login reply within ${CHR_BOOT_TIMEOUT}s of start"
		fi
		sleep 3
	done
}

# A fresh CHR answers the API before its SSH service sends a banner (host keys are generated after boot), and the
# QEMU port forward accepts TCP connections either way, so readiness is the "SSH-" identification line itself.
cmd_wait_ssh() {
	local t0 out pid
	t0=$(date +%s)
	pid=$(running_pid) || { serial_tail; die "QEMU is not running"; }
	if out=$(php "$DIR/smoke.php" --ssh-banner 2>&1); then
		log "SSH sent its banner after $(( $(date +%s) - t0 ))s of waiting: $out"
		return
	fi
	echo "chr.sh: $out" >&2
	serial_tail
	die "no SSH banner within ${CHR_BOOT_TIMEOUT}s of waiting on one connection"
}

cmd_stop() {
	local pid i
	if [ ! -f "$PID_FILE" ]; then
		log "not running"
		return
	fi
	pid=$(cat "$PID_FILE")
	if alive "$pid"; then
		kill "$pid" 2>/dev/null || true
		for i in $(seq 1 20); do
			alive "$pid" || break
			sleep 0.5
		done
		if alive "$pid"; then
			kill -9 "$pid" 2>/dev/null || true
		fi
		log "stopped QEMU (pid $pid)"
	else
		log "not running (stale pid file removed)"
	fi
	rm -f "$PID_FILE" "$BOOT_STAMP"
	rm -rf "$CHR_RUN_DIR/image"
}

cmd_status() {
	local pid
	if pid=$(running_pid); then
		echo "running (pid $pid)"
	else
		echo "not running"
	fi
}

case "${1:-}" in
	install) cmd_install ;;
	start) cmd_start ;;
	wait) cmd_wait ;;
	wait-ssh) cmd_wait_ssh ;;
	stop) cmd_stop ;;
	status) cmd_status ;;
	serial-tail) serial_tail ;;
	*) echo "usage: bash $0 install|start|wait|wait-ssh|stop|status" >&2; exit 2 ;;
esac
