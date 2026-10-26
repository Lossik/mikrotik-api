#!/bin/bash
# OH5 helper: runs the plan of run-live.sh for version $1 through execute_plan (as main() does) with the commands
# replaced by stubs that print what they were called with, and prints that trace.
#   $1 version, $2 how many times the plain smoke.php fails, further arguments are passed on to execute_plan.
# Exit status is the one of execute_plan under set -e, as in main().
set -euo pipefail

# shellcheck source=../run-live.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/run-live.sh"

TRACE_VERSION="$1"
SMOKE_FAILS="$2"
shift 2
SMOKES=0

trace() {
	local line="$*"
	echo "${line//"$DIR"/\$DIR}"
}
php() {
	trace php "$@"
	if [ "$*" = "$DIR/smoke.php" ]; then
		SMOKES=$(( SMOKES + 1 ))
		[ "$SMOKES" -gt "$SMOKE_FAILS" ]
	fi
}
bash() { trace bash "$@" "[CHR_VERSION=$CHR_VERSION]"; }
timeout() { trace timeout "$@"; }
verify_npk() { trace verify_npk "$@"; }
step() { echo "step: $*"; }
step_done() { :; }

export CHR_VERSION="$TRACE_VERSION"
export CHR_CACHE=/cache
export CHR_PASSWORD=pw
plan_live_run "$TRACE_VERSION" "$VERSIONS_FILE"
execute_plan "$@"
echo "env: MIKROTIK_LIVE_SSH_PATH=$MIKROTIK_LIVE_SSH_PATH CHR_LIVE_TIMEOUT=$CHR_LIVE_TIMEOUT" \
	"MIKROTIK_LIVE_HOST=$MIKROTIK_LIVE_HOST MIKROTIK_LIVE_PORT_API=$MIKROTIK_LIVE_PORT_API" \
	"MIKROTIK_LIVE_PORT_TLS=$MIKROTIK_LIVE_PORT_TLS MIKROTIK_LIVE_PORT_SSH=$MIKROTIK_LIVE_PORT_SSH" \
	"MIKROTIK_LIVE_USER=$MIKROTIK_LIVE_USER MIKROTIK_LIVE_PASS=$MIKROTIK_LIVE_PASS"
