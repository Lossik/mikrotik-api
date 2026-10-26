#!/bin/bash
# Explicit unpinned-image path. The SHA comes from MikroTik's HTTPS .sha256, never from the image itself.
set -euo pipefail
DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
[[ "$CHR_VERSION" =~ ^7\.[0-9]+(\.[0-9]+)?((rc|beta)[0-9]+)?$ ]] || exit 1
export CHR_CACHE="${CHR_CACHE:-$HOME/.cache/lossik-chr}"
export CHR_OFFICIAL_SHA256=""
result=failed
finish() {
  local rc=$?
  trap - EXIT
  if [ "$rc" = 78 ]; then result=unavailable; rc=0; fi
  php "$DIR/tested.php" record "$result" /app/.routeros-result.json || rc=1
  exit "$rc"
}
trap finish EXIT
sum="$CHR_CACHE/chr-$CHR_VERSION.img.zip.sha256"
if ! bash "$DIR/download.sh" "https://download.mikrotik.com/routeros/$CHR_VERSION/chr-$CHR_VERSION.img.zip.sha256" "$sum"; then
  echo 'Cannot obtain the official checksum; refusing to run an unverified image' >&2
  exit 1
fi
CHR_OFFICIAL_SHA256=$(php -r '
$text = trim(file_get_contents($argv[1]));
if (!preg_match("/^([a-f0-9]{64})(?:\\s+\\*?" . preg_quote("chr-" . $argv[2] . ".img.zip", "/") . ")?$/D", $text, $m)) {
    fwrite(STDERR, "Invalid official SHA256 response\n"); exit(1);
}
echo $m[1];
' "$sum" "$CHR_VERSION")
export CHR_OFFICIAL_SHA256
bash "$DIR/run-live.sh" --log-junit /app/.routeros-junit.xml
result=passed
