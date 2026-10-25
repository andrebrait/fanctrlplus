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

# ===== fcp_stuck_since =====
expect_equal 100 "$(fcp_stuck_since '' 100 255 154)" "Set to 100%, stuck at 60%: a stretch starts now."
expect_equal 100 "$(fcp_stuck_since 100 110 166 154)" "A new target above 60% keeps the stretch going."
expect_equal 100 "$(fcp_stuck_since 100 110 155 154)" "61% is above 60%; a reading of 154 rounds to 60%."
expect_equal '' "$(fcp_stuck_since 100 110 154 120)" "A target of 60% or less ends the stretch."
expect_equal '' "$(fcp_stuck_since 100 110 255 155)" "A reading above 60% ends the stretch."
expect_equal '' "$(fcp_stuck_since 100 110 255 '')" "An unreadable value ends the stretch."

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

# ===== fcp_missing_since / fcp_notify_missing (#21) =====
expect_equal 100 "$(fcp_missing_since '' 100 'storcli:c0:roc')" "An unread sensor starts a stretch now."
expect_equal 100 "$(fcp_missing_since 100 170 'storcli:c0:roc')" "A sensor still unread keeps the stretch's start."
expect_equal '' "$(fcp_missing_since 100 170 '')" "A round with every sensor read ends the stretch."
: > "$tmp/calls"
fcp_notify_missing HBA 'storcli:c0:roc, custom:ambient' "$tmp/missing_flag"
fcp_notify_missing HBA 'storcli:c0:roc' "$tmp/missing_flag"
expect_equal 1 "$(grep -c '^-s$' "$tmp/calls")" "One missing-sensor notification per fan until the flag is cleared at boot."
expect_equal 1 "$(grep -c 'from: storcli:c0:roc, custom:ambient\.' "$tmp/calls")" \
  "The notification names every unread sensor."
expect_equal 1 "$(grep -cx 'warning' "$tmp/calls")" "The missing-sensor notification is a warning."
fcp_notify_missing HBA 'storcli:c0:roc' "$tmp/missing/flag"
expect_equal 1 "$(grep -c '^-s$' "$tmp/calls")" "Without a missing-sensor flag file, no notification is sent, so ticks cannot repeat it."
: > "$tmp/calls"
good_notify=$fcp_notify_bin fcp_notify_bin=false
fcp_notify_missing HBA 'storcli:c0:roc' "$tmp/retry_flag"
fcp_notify_bin=$good_notify
fcp_notify_missing HBA 'storcli:c0:roc' "$tmp/retry_flag"
expect_equal 1 "$(grep -c '^-s$' "$tmp/calls")" "A notification that failed to send is tried again on the next tick."

if (( failures > 0 )); then
  exit 1
fi

echo "nct6687 stuck-fan tests passed"
