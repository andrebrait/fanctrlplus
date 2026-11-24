#!/bin/bash
# A controller fan control took goes back to the mode it had before, once no
# Enabled fan drives it.
set -u
root="$(cd "$(dirname "$0")/.." && pwd)"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
fcp_bios_mode_dir="$tmp/modes"
source "$root/src/usr/local/emhttp/plugins/fanctrlplus2/scripts/disk_group_control.sh"
logger() { :; }
failures=0
expect() { [[ "$2" == "$3" ]] || { echo "$1 Expected: $2 Actual: $3" >&2; failures=$((failures + 1)); }; }
cfgs="$tmp/cfg"; mkdir -p "$cfgs"
fan() { printf 'custom="%s"\nservice="%s"\ncontroller="%s"\n' "$1" "$2" "$3" > "$cfgs/fanctrlplus2_$1.cfg"; }
chip="$tmp/devices/hwmon/hwmon2"; mkdir -p "$chip"
take() {  # fan control drives $1, which starts in mode $2
  echo "$2" > "${1}_enable"; controller=$1 controller_enable=${1}_enable
  fcp_write_pwm 90; fcp_write_pwm 100
}

take "$chip/pwm1" 5
expect "Fan control must take the controller in manual mode." "1 100" "$(< "$chip/pwm1_enable") $(< "$chip/pwm1")"
fan CPU 1 "$chip/pwm1"
fcp_give_back_unused "$cfgs"
expect "A controller an Enabled fan drives must stay manual." "1" "$(< "$chip/pwm1_enable")"
fan CPU 2 "$chip/pwm1"
fcp_give_back_unused "$cfgs"
expect "Monitor only must restore the mode the controller had first, not a later one." "5" "$(< "$chip/pwm1_enable")"
echo 1 > "$chip/pwm1_enable"
fcp_give_back_unused "$cfgs"
expect "A controller given back must not be given back again." "1" "$(< "$chip/pwm1_enable")"

# Moved to another controller in the same save: the old one goes back too.
take "$chip/pwm1" 2
fan CPU 2 "$chip/pwm2"
fcp_give_back_unused "$cfgs"
expect "A controller no fan drives any more must go back to its mode." "2" "$(< "$chip/pwm1_enable")"

# Already manual when fan control took it: left as it is.
take "$chip/pwm1" 1
fcp_give_back_unused "$cfgs"
expect "A controller fan control found in manual mode must stay manual." "1" "$(< "$chip/pwm1_enable")"

# A /sys/class/hwmon path names a number, whatever chip holds it (#41).
class="$tmp/class/hwmon/hwmon2"; mkdir -p "$class"
take "$class/pwm1" 2
fcp_give_back_unused "$cfgs"
expect "A controller saved by its hwmon number must not be written." "1" "$(< "$class/pwm1_enable")"

(( failures == 0 )) || exit 1
echo "BIOS mode tests passed"
