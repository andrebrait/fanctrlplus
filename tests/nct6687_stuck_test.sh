#!/bin/bash

set -u

root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

fcp_nct6687_module="$tmp/module"
fcp_notify_bin="$tmp/notify"
source "$root/src/usr/local/emhttp/plugins/fanctrlplus2/scripts/aux_sensors.sh"
source "$root/src/usr/local/emhttp/plugins/fanctrlplus2/scripts/disk_group_control.sh"

failures=0
expect_equal() {
  local expected="$1" actual="$2" message="$3"
  if [[ "$expected" == "$actual" ]]; then
    return
  fi
  printf '%s\nExpected: %s\nActual: %s\n' "$message" "$expected" "$actual" >&2
  failures=$((failures + 1))
}

# ===== fcp_nct6687_msi_channel =====
nct="$tmp/hwmon7" other="$tmp/hwmon3"
mkdir -p "$nct" "$other" "$fcp_nct6687_module/parameters"
echo nct6687 > "$nct/name"
echo it8728 > "$other/name"
channel() { fcp_nct6687_msi_channel "$1" && echo yes || echo no; }

expect_equal no "$(channel "$nct/pwm5")" "Without the driver loaded, no channel is watched."
echo default > "$fcp_nct6687_module/parameters/fan_config"
expect_equal no "$(channel "$nct/pwm5")" "The default register layout applies speed changes."
echo msi_alt1 > "$fcp_nct6687_module/parameters/fan_config"
expect_equal yes "$(channel "$nct/pwm3")" "The first system-fan channel is watched."
expect_equal no "$(channel "$nct/pwm2")" "The pump channel is not affected."
expect_equal no "$(channel "$other/pwm5")" "Another chip's channel is not watched."
echo nct6683 > "$other/name"
expect_equal yes "$(channel "$other/pwm5")" "The driver's other chip names are watched too."
touch "$nct/fan_control_watchdog"
expect_equal no "$(channel "$nct/pwm5")" "A driver running with brute force on is not watched."

# ===== fcp_stuck_ticks =====
expect_equal 1 "$(fcp_stuck_ticks 0 255 154)" "Set to 100%, stuck at 60%: one stuck tick."
expect_equal 2 "$(fcp_stuck_ticks 1 255 154)" "A second stuck tick in a row counts up."
expect_equal 1 "$(fcp_stuck_ticks 0 154 140)" "Just above 60%, the target is checked."
expect_equal 0 "$(fcp_stuck_ticks 1 153 120)" "A target of 60% or less is not checked."
expect_equal 1 "$(fcp_stuck_ticks 0 200 194)" "More than 5 below the target is stuck."
expect_equal 0 "$(fcp_stuck_ticks 1 200 195)" "Within 5 of the target counts as reached."
expect_equal 0 "$(fcp_stuck_ticks 1 200 '')" "An unreadable value resets the count."

# ===== fcp_notify_stuck =====
printf '#!/bin/bash\nprintf "%%s\\n" "$@" >> "%s/calls"\n' "$tmp" > "$fcp_notify_bin"
chmod +x "$fcp_notify_bin"
fcp_notify_stuck HardDriveFans 255 154 "$tmp/flag"
fcp_notify_stuck HardDriveFans 255 154 "$tmp/flag"
expect_equal 1 "$(grep -c '^-s$' "$tmp/calls")" "One notification per fan until the flag is cleared at boot."
expect_equal 1 "$(grep -c '^Set to 100% but the nct6687 controller reports 60%' "$tmp/calls")" \
  "The notification reports the set and actual speeds as percentages."
expect_equal 1 "$(grep -cx 'warning' "$tmp/calls")" "The notification is a warning."
fcp_notify_stuck HardDriveFans 255 154 "$tmp/missing/flag"
expect_equal 1 "$(grep -c '^-s$' "$tmp/calls")" "Without a flag file, no notification is sent, so ticks cannot repeat it."

if (( failures > 0 )); then
  exit 1
fi

echo "nct6687 stuck-fan tests passed"
