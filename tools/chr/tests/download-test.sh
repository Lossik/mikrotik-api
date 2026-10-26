#!/bin/bash
set -euo pipefail
DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/bin" "$tmp/cache"
# Only the external HTTP service is replaced; downloader, chr.sh and NPK verification remain real.
cat >"$tmp/bin/curl" <<'EOF'
#!/bin/bash
while [ "$#" -gt 0 ]; do
  if [ "$1" = --output ]; then printf 'image bytes' >"$2"; fi
  shift
done
printf '%s' "$TEST_HTTP"
exit "$TEST_CURL_RC"
EOF
chmod +x "$tmp/bin/curl"
export PATH="$tmp/bin:$PATH"
for spec in '404 22 78' '410 22 78' '500 22 1' '000 28 1' '200 0 0'; do
  read -r TEST_HTTP TEST_CURL_RC expected <<<"$spec"
  export TEST_HTTP TEST_CURL_RC
  rc=0
  bash "$DIR/download.sh" https://download.mikrotik.com/example "$tmp/download" >"$tmp/log" 2>&1 || rc=$?
  [ "$rc" = "$expected" ] || { cat "$tmp/log"; echo "HTTP $TEST_HTTP: expected $expected, got $rc" >&2; exit 1; }
  [ ! -e "$tmp/download.part" ] || { echo 'Partial download leaked' >&2; exit 1; }
done
export TEST_HTTP=404 TEST_CURL_RC=22
rc=0
CHR_VERSION=7.24.5 CHR_CACHE="$tmp/cache" CHR_RUN_DIR="$tmp/run" bash "$DIR/chr.sh" start >"$tmp/log" 2>&1 || rc=$?
[ "$rc" = 78 ] || { cat "$tmp/log"; echo 'Image 404 lost its unavailable status' >&2; exit 1; }
rc=0
CHR_CACHE="$tmp/cache" bash -c 'source "$1/run-live.sh"; verify_npk 6.49.23 "$2"' _ "$DIR" "$tmp/cache" >"$tmp/log" 2>&1 || rc=$?
[ "$rc" = 78 ] || { cat "$tmp/log"; echo 'NPK 404 lost its unavailable status' >&2; exit 1; }

# The explicit new-version route must verify SHA256, and must never override an existing pin.
printf 'image bytes' >"$tmp/cache/chr-7.24.5.img.zip"
sha=$(sha256sum "$tmp/cache/chr-7.24.5.img.zip" | awk '{print $1}')
rc=0
CHR_VERSION=7.24.5 CHR_OFFICIAL_SHA256="$sha" CHR_CACHE="$tmp/cache" CHR_RUN_DIR="$tmp/run" \
  bash "$DIR/chr.sh" start >"$tmp/log" 2>&1 || rc=$?
[ "$rc" = 1 ] && grep -Fq 'SHA256 mismatch' "$tmp/log" || { cat "$tmp/log"; echo 'Pin overridden' >&2; exit 1; }
printf 'different bytes' >"$tmp/cache/chr-7.26beta1.img.zip"
rc=0
CHR_VERSION=7.26beta1 CHR_OFFICIAL_SHA256="$sha" CHR_CACHE="$tmp/cache" CHR_RUN_DIR="$tmp/run" \
  bash "$DIR/chr.sh" start >"$tmp/log" 2>&1 || rc=$?
[ "$rc" = 1 ] && grep -Fq 'SHA256 mismatch' "$tmp/log" || { cat "$tmp/log"; echo 'Unpinned checksum bypassed' >&2; exit 1; }
echo 'download: HTTP classification, image/NPK wiring and checksum enforcement OK'
