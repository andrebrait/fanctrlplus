#!/bin/bash
# Run one user-supplied sensor script and relay its output and exit status.
# Shared by the PHP sensor discovery and the control loop, so both read a
# sensor identically.
#
#   custom_sensor.sh SCRIPT [TIMEOUT_SECONDS]
#
# The sensors.d directory lives on the Unraid flash drive, which is FAT32 and
# can never carry an execute bit, so SCRIPT is only required to be a readable
# file. It is copied afresh into a private directory on runtime storage, made
# executable there and run from the copy, which keeps its shebang line working
# and means an edit on flash applies to the very next reading. The copy never
# outlives the run, however it ends. Nothing is ever chmod'ed or run on flash.

set -u

script="${1:-}"
limit="${2:-5}"
# An immediate child of the sticky runtime directory avoids trusting the
# separately managed /var/tmp/fanctrlplus2 parent for executable payloads.
base="${FCP_CUSTOM_SENSOR_RUNTIME_DIR:-/var/tmp/fanctrlplus2-sensor-run-$EUID}"

[[ -f "$script" && -r "$script" ]] || exit 1
[[ "$limit" =~ ^[0-9]+(\.[0-9]+)?$ && "$limit" =~ [1-9] ]] || exit 1

umask 077
mkdir -p -m 700 -- "$base" 2>/dev/null || exit 1
# Only a private directory of ours may hold something about to be executed.
[[ -d "$base" && ! -L "$base" && -O "$base" ]] || exit 1
permissions=$(stat -c %a -- "$base" 2>/dev/null) || exit 1
(( (8#$permissions & 0022) == 0 )) || exit 1

work=$(mktemp -d -- "$base/run.XXXXXX" 2>/dev/null) || exit 1
trap 'rm -rf -- "$work"' EXIT
trap 'exit 143' HUP INT TERM

cp -- "$script" "$work/sensor" 2>/dev/null || exit 1
chmod 700 -- "$work/sensor" || exit 1

# -k: a script that ignores the polite signal is still gone shortly after.
timeout -k 1 "$limit" "$work/sensor" 2>/dev/null </dev/null
