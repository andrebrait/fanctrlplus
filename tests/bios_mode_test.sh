#!/bin/bash
# A fan set to Monitor only gets its controller back in the mode it had before
# fan control first took it.
set -u
root="$(cd "$(dirname "$0")/.." && pwd)"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
fcp_bios_mode_dir="$tmp/modes"
source "$root/src/usr/local/emhttp/plugins/fanctrlplus2/scripts/disk_group_control.sh"
logger() { :; }
failures=0
expect() { [[ "$2" == "$3" ]] || { echo "$1 Expected: $2 Actual: $3" >&2; failures=$((failures + 1)); }; }

controller="$tmp/devices/hwmon/hwmon2/pwm1" controller_enable="$tmp/devices/hwmon/hwmon2/pwm1_enable"
mkdir -p "${controller%/*}"
echo 5 > "$controller_enable"; echo 120 > "$controller"

fcp_write_pwm 90
fcp_write_pwm 100
expect "Fan control must take the controller in manual mode." "1 100" "$(< "$controller_enable") $(< "$controller")"
fcp_give_back "$controller"
expect "Monitor only must restore the mode the controller had first, not a later one." "5" "$(< "$controller_enable")"
echo 1 > "$controller_enable"
fcp_give_back "$controller"
expect "A controller given back must not be given back again." "1" "$(< "$controller_enable")"

# A controller already manual when fan control took it is left as it is.
fcp_write_pwm 90
fcp_give_back "$controller"
expect "A controller fan control found in manual mode must stay manual." "1" "$(< "$controller_enable")"

# A /sys/class/hwmon path names a number, whatever chip holds it (#41).
class="$tmp/class/hwmon/hwmon2/pwm1"
mkdir -p "${class%/*}"; echo 1 > "${class}_enable"; echo 2 > "$(fcp_bios_mode_file "$class")"
fcp_give_back "$class"
expect "A controller saved by its hwmon number must not be written." "1" "$(< "${class}_enable")"

(( failures == 0 )) || exit 1
echo "BIOS mode tests passed"
