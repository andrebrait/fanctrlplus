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

# ===== fcp_check_fan =====
hw="$tmp/hw"
mkdir -p "$hw"
fan() { # pwm readback, pwmN_enable ('' for none), fan RPM ('' for none)
  echo "$1" > "$hw/pwm1"
  if [[ -n "$2" ]]; then echo "$2" > "$hw/pwm1_enable"; else rm -f "$hw/pwm1_enable"; fi
  if [[ -n "$3" ]]; then echo "$3" > "$hw/fan1_input"; else rm -f "$hw/fan1_input"; fi
}
keys() { # fcp_check_fan's arguments; prints the problem keys found
  local fault
  fcp_check_fan "$@"
  for fault in "${fcp_faults[@]}"; do printf '%s;' "${fault%%|*}"; done
}

fcp_fan_seen_spinning=0
fan 255 1 1200
fcp_check_fan "$hw/pwm1" "$hw/fan1_input" 255 0
expect_equal 0 "${#fcp_faults[@]}" "A fan at its set speed and spinning has no problem."
expect_equal 1 "$fcp_fan_seen_spinning" "A fan reporting RPM is remembered as one with a working tachometer."

fan 120 1 1200
expect_equal 'speed;' "$(keys "$hw/pwm1" "$hw/fan1_input" 255 0)" "A controller reading back far from the set speed is a problem."
fan 250 1 1200
expect_equal '' "$(keys "$hw/pwm1" "$hw/fan1_input" 255 0)" "A readback within 10 points of the set speed is fine."
fan 154 1 1200
expect_equal '' "$(keys "$hw/pwm1" "$hw/fan1_input" 166 0)" "65% read back as 60% is within the general tolerance."
expect_equal 'speed;' "$(keys "$hw/pwm1" "$hw/fan1_input" 166 1)" "An nct6687 MSI fan set above 60% and held at 60% is a problem."
fcp_check_fan "$hw/pwm1" "$hw/fan1_input" 255 1
expect_equal 1 "$(grep -c 'msi_fan_brute_force' <<< "${fcp_faults[0]}")" "The nct6687 warning names the driver option that fixes it."
fcp_check_fan "$hw/pwm1" "$hw/fan1_input" 255 0
expect_equal 0 "$(grep -c 'msi_fan_brute_force' <<< "${fcp_faults[0]}")" "Other controllers do not get the nct6687 advice."

fan 120 2 1200
expect_equal 'mode;' "$(keys "$hw/pwm1" "$hw/fan1_input" 255 0)" "Out of manual mode, only the lost control is reported."
fan 255 '' 1200
expect_equal '' "$(keys "$hw/pwm1" "$hw/fan1_input" 255 0)" "A controller without an enable attribute is not checked for its mode."
expect_equal 'controller;' "$(keys "$hw/pwm9" "$hw/fan9_input" 255 0)" "A controller that no longer exists is reported."

fan 255 1 0
expect_equal 'stopped;' "$(keys "$hw/pwm1" "$hw/fan1_input" 255 0)" "A fan that spun before and reads 0 RPM at full speed has stopped."
fan 102 1 0
expect_equal 'stopped;' "$(keys "$hw/pwm1" "$hw/fan1_input" 102 0)" "40% is enough to expect a fan to spin."
fan 77 1 0
expect_equal '' "$(keys "$hw/pwm1" "$hw/fan1_input" 77 0)" "At 30% a fan may stop by design."
fan 255 1 ''
expect_equal '' "$(keys "$hw/pwm1" "$hw/fan1_input" 255 0)" "Without an RPM reading nothing is said about the fan stopping."
fcp_fan_seen_spinning=0
fan 255 1 0
expect_equal '' "$(keys "$hw/pwm1" "$hw/fan1_input" 255 0)" "A fan never seen spinning may have no tachometer."

# ===== fcp_track_faults =====
printf '#!/bin/bash\nprintf "%%s\\n" "$@" >> "%s/calls"\n' "$tmp" > "$fcp_notify_bin"
chmod +x "$fcp_notify_bin"
cfg_file="$tmp/fan.cfg"
echo 'notify="1"' > "$cfg_file"
dir="$tmp/faults" && mkdir -p "$dir"
speed='speed|is not reaching its set speed|Set to 100% but the controller reports 47%.'
stopped='stopped|has stopped|Set to 100% but the fan reports 0 RPM.'
sent() { grep -c "^$1\$" "$tmp/calls"; }
: > "$tmp/calls"

fcp_track_faults 0 Bay "$dir" "$speed"
fcp_track_faults 29 Bay "$dir" "$speed"
expect_equal 0 "$(sent warning)" "A problem shorter than 30 seconds does not warn."
fcp_track_faults 30 Bay "$dir" "$speed"
expect_equal 1 "$(sent warning)" "A problem lasting 30 seconds warns."
expect_equal 1 "$(sent 'Fan Bay is not reaching its set speed')" "The warning names the fan and the problem."
fcp_track_faults 60 Bay "$dir" "$speed"
expect_equal 1 "$(sent warning)" "An ongoing problem warns once."
unset fcp_fault_since && declare -gA fcp_fault_since=()
fcp_track_faults 70 Bay "$dir" "$speed"
fcp_track_faults 100 Bay "$dir" "$speed"
expect_equal 1 "$(sent warning)" "A restarted loop does not repeat a warning already sent."
fcp_track_faults 110 Bay "$dir"
expect_equal 1 "$(sent normal)" "A warned problem that is gone sends a recovery notice."
expect_equal 1 "$(sent 'This earlier warning has cleared: Fan Bay is not reaching its set speed.')" "The notice says which problem resolved."
fcp_track_faults 120 Bay "$dir"
expect_equal 1 "$(sent normal)" "A recovery is announced once."

fcp_track_faults 200 Bay "$dir" "$stopped"
fcp_track_faults 210 Bay "$dir"
fcp_track_faults 220 Bay "$dir" "$stopped"
fcp_track_faults 240 Bay "$dir" "$stopped"
fcp_track_faults 245 Bay "$dir"
expect_equal 1 "$(sent warning)" "A problem that clears starts a new stretch, and one never warned about clears silently."

: > "$tmp/calls"
echo 'notify="0"' > "$cfg_file"
fcp_track_faults 300 Bay "$dir" "$stopped"
fcp_track_faults 330 Bay "$dir" "$stopped"
expect_equal 0 "$(sent warning)" "With Problem Alerts off, nothing is sent."
echo 'notify="1"' > "$cfg_file"
fcp_track_faults 340 Bay "$dir" "$stopped"
expect_equal 1 "$(sent warning)" "Switched back on, an ongoing problem warns without a restart."
echo 'notify="0"' > "$cfg_file"
fcp_track_faults 350 Bay "$dir"
expect_equal 0 "$(sent normal)" "With Problem Alerts off, a recovery is not announced."
expect_equal '' "$(ls "$dir")" "A recovery while alerts are off still closes the incident."
echo 'notify="1"' > "$cfg_file"

: > "$tmp/calls"
good_notify=$fcp_notify_bin fcp_notify_bin=false
fcp_track_faults 400 Bay "$dir" "$stopped"
fcp_track_faults 430 Bay "$dir" "$stopped"
fcp_notify_bin=$good_notify
fcp_track_faults 440 Bay "$dir" "$stopped"
expect_equal 1 "$(sent warning)" "A warning that failed to send is tried again on the next round."
fcp_notify_bin=false
fcp_track_faults 450 Bay "$dir"
fcp_notify_bin=$good_notify
fcp_track_faults 460 Bay "$dir"
expect_equal 1 "$(sent normal)" "A recovery notice that failed to send is tried again on the next round."

# ===== fcp_track_missing / fcp_notify_missing (#21) =====
overdue() { fcp_track_missing "$@"; (( ${#fcp_missing_overdue[@]} )) && printf '%s;' "${fcp_missing_overdue[@]}"; }
expect_equal '' "$(overdue 100 A)" "A sensor unread just now is not overdue."
fcp_track_missing 100 A
expect_equal '' "$(overdue 159 A)" "59 seconds unread is within the grace period."
expect_equal 'A;' "$(overdue 160 A)" "60 seconds unread is overdue."
# Codex's case: alternating failures must not add up for any one sensor.
fcp_track_missing 0
fcp_track_missing 0 A
fcp_track_missing 30 B
expect_equal '' "$(overdue 60 A)" "A sensor that read in between starts a new stretch."
fcp_track_missing 60 A
expect_equal '' "$(overdue 90 A B)" "A sensor unread again restarts its own clock."
fcp_track_missing 90 A B
expect_equal 'A;' "$(overdue 120 A B)" "Each sensor is overdue on its own clock."
fcp_track_missing 200
expect_equal '' "$(overdue 200 A)" "A round with every sensor read forgets them all."
expect_equal '' "$(overdue 300)" "No unread sensor, nothing overdue."

: > "$tmp/calls"
fcp_notify_missing HBA "$tmp/missing_flag" storcli:c0:roc custom:ambient
fcp_notify_missing HBA "$tmp/missing_flag" storcli:c0:roc
expect_equal 1 "$(grep -c '^-s$' "$tmp/calls")" "One missing-sensor notification per fan until the flag is cleared at boot."
expect_equal 1 "$(grep -c 'from: storcli:c0:roc, custom:ambient\.' "$tmp/calls")" \
  "The notification names every overdue sensor."
expect_equal 1 "$(grep -cx 'warning' "$tmp/calls")" "The missing-sensor notification is a warning."
fcp_notify_missing HBA "$tmp/missing/flag" storcli:c0:roc
expect_equal 1 "$(grep -c '^-s$' "$tmp/calls")" "Without a missing-sensor flag file, no notification is sent, so ticks cannot repeat it."
: > "$tmp/calls"
good_notify=$fcp_notify_bin fcp_notify_bin=false
fcp_notify_missing HBA "$tmp/retry_flag" storcli:c0:roc
fcp_notify_bin=$good_notify
fcp_notify_missing HBA "$tmp/retry_flag" storcli:c0:roc
expect_equal 1 "$(grep -c '^-s$' "$tmp/calls")" "A notification that failed to send is tried again on the next tick."
: > "$tmp/calls"
echo 'notify="0"' > "$cfg_file"
fcp_notify_missing HBA "$tmp/off_flag" storcli:c0:roc
expect_equal 0 "$(grep -c '^-s$' "$tmp/calls")" "With Problem Alerts off, a missing sensor is not reported."
echo 'notify="1"' > "$cfg_file"
fcp_notify_missing HBA "$tmp/off_flag" storcli:c0:roc
expect_equal 1 "$(grep -c '^-s$' "$tmp/calls")" "Switched back on, a missing sensor is still reported."

if (( failures > 0 )); then
  exit 1
fi

echo "fan alert tests passed"
