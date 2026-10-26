#!/bin/bash
# Verified downloader's transport: 78 is reserved for an image removed by MikroTik.
# All other HTTP errors and exhausted network retries are hard failures.
set -euo pipefail
url=$1
target=$2
mkdir -p "$(dirname "$target")"
code=0
status=$(curl --silent --show-error --location --retry 3 --retry-all-errors \
  --connect-timeout 15 --max-time 300 --fail --output "$target.part" --write-out '%{http_code}' "$url") || code=$?
if [ "$code" -ne 0 ]; then
  rm -f "$target.part"
  if [ "$status" = 404 ] || [ "$status" = 410 ]; then
    echo "Unavailable: $url (HTTP $status)" >&2
    exit 78
  fi
  echo "Download failed: $url (curl $code, HTTP $status)" >&2
  exit 1
fi
mv "$target.part" "$target"
