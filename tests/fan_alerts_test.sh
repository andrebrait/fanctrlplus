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
hw="$tmp/hw" spun="$tmp/fan1"
mkdir -p "$hw" "$spun"
fan() { # pwm readback, pwmN_enable ('' for none), fan RPM ('' for none)
  echo "$1" > "$hw/pwm1"
  if [[ -n "$2" ]]; then echo "$2" > "$hw/pwm1_enable"; else rm -f "$hw/pwm1_enable"; fi
  if [[ -n "$3" ]]; then echo "$3" > "$hw/fan1_input"; else rm -f "$hw/fan1_input"; fi
}
check() { # target [nct]; prints the problem keys, then the keys seen fine
  local fault
  fcp_check_fan "$hw/pwm1" "$hw/fan1_input" "$1" "${2:-0}" "$spun"
  for fault in "${fcp_faults[@]}"; do printf '%s;' "${fault%%|*}"; done
  printf '/%s' "${fcp_ok[*]}"
}

fan 255 1 0
expect_equal '/controller mode speed' "$(check 255)" "A fan never seen spinning may have no tachometer: 0 RPM is not judged."
fan 255 1 1200
expect_equal '/controller mode speed stopped' "$(check 255)" "A fan at its set speed and spinning is fine on every count."
expect_equal yes "$([[ -e "$spun/.spun" ]] && echo yes)" "A fan reporting RPM is remembered, across loop restarts, as having a tachometer."

fan 120 1 1200
expect_equal 'speed;/controller mode stopped' "$(check 255)" "A controller reading back far below the set speed is a problem."
fan 255 1 1200
expect_equal 'speed;/controller mode stopped' "$(check 120)" "A controller reading back far above the set speed is a problem."
fan 230 1 1200
expect_equal 'speed;/controller mode stopped' "$(check 255)" "A readback 10 points off is a problem."
fan 242 1 1200
expect_equal '/controller mode speed stopped' "$(check 255)" "A readback 5 points off is within the tolerance."
fan 240 1 1200
expect_equal 'speed;/controller mode stopped' "$(check 255)" "A readback 6 points off is a problem."
fan 154 1 1200
expect_equal '/controller mode speed stopped' "$(check 166)" "65% read back as 60% is within the general tolerance."
expect_equal '/controller mode speed stopped' "$(check 166 1)" "The MSI check respects the same 5-point tolerance."
expect_equal '/controller mode speed stopped' "$(check 155 1)" "A 1-point MSI mismatch is within the tolerance."
expect_equal 'speed;/controller mode stopped' "$(check 170 1)" "An MSI mismatch above 5 points is reported."
expect_equal '/controller mode speed stopped' "$(check 154 1)" "An nct6687 MSI fan set to 60% at 60% is fine."
fan 155 1 1200
expect_equal '/controller mode speed stopped' "$(check 166 1)" "An nct6687 MSI fan reading above 60% is not held."
fan 154 1 1200
fcp_check_fan "$hw/pwm1" "$hw/fan1_input" 255 1 "$spun"
expect_equal 1 "$(grep -c 'msi_fan_brute_force' <<< "${fcp_faults[0]}")" "The nct6687 warning names the driver option that fixes it."
fcp_check_fan "$hw/pwm1" "$hw/fan1_input" 255 0 "$spun"
expect_equal 0 "$(grep -c 'msi_fan_brute_force' <<< "${fcp_faults[0]}")" "Other controllers do not get the nct6687 advice."

# RPM evidence is based on the current reading, not a stale earlier sample.
fcp_response_start 77 255 1000 "$((SECONDS - 30))"
fan 77 1 3300
fcp_check_fan "$hw/pwm1" "$hw/fan1_input" 255 0 "$spun"
expect_equal 1 "$(grep -c 'RPM.*respond' <<< "${fcp_faults[0]}")" "A responding fan qualifies the mismatch."
fan 77 1 1050
fcp_check_fan "$hw/pwm1" "$hw/fan1_input" 255 0 "$spun"
expect_equal 0 "$(grep -c 'RPM.*respond' <<< "${fcp_faults[0]}")" "An old response cannot qualify a current mismatch."
fcp_response_start 77 255 1000 "$SECONDS"
fan 77 1 3300
fcp_check_fan "$hw/pwm1" "$hw/fan1_input" 255 0 "$spun"
expect_equal 0 "$(grep -c 'RPM.*respond' <<< "${fcp_faults[0]}")" "A fan is given time to settle."
fcp_response_start 255 77 3300 "$((SECONDS - 30))"
fan 255 1 1000
fcp_check_fan "$hw/pwm1" "$hw/fan1_input" 77 0 "$spun"
expect_equal 1 "$(grep -c 'RPM.*respond' <<< "${fcp_faults[0]}")" "A decrease can qualify a mismatch."
fcp_response_start 77 255 1000 "$((SECONDS - 30))"
fan 77 1 900
fcp_check_fan "$hw/pwm1" "$hw/fan1_input" 255 0 "$spun"
expect_equal 0 "$(grep -c 'RPM.*respond' <<< "${fcp_faults[0]}")" "A response in the wrong direction is not evidence."
fcp_response_start 77 90 1000 "$((SECONDS - 30))"
fan 0 1 3300
fcp_check_fan "$hw/pwm1" "$hw/fan1_input" 90 0 "$spun"
expect_equal 0 "$(grep -c 'RPM.*respond' <<< "${fcp_faults[0]}")" "A small PWM change cannot establish a response."
fcp_response_start 77 255 '' "$((SECONDS - 30))"
fan 77 1 3300
fcp_check_fan "$hw/pwm1" "$hw/fan1_input" 255 0 "$spun"
expect_equal 0 "$(grep -c 'RPM.*respond' <<< "${fcp_faults[0]}")" "A missing baseline cannot establish a response."
fcp_response_start 77 128 1000 "$((SECONDS - 30))"
fan 0 1 1199
fcp_check_fan "$hw/pwm1" "$hw/fan1_input" 128 0 "$spun"
expect_equal 0 "$(grep -c 'RPM.*respond' <<< "${fcp_faults[0]}")" "RPM changes below 20% are inconclusive."
fan 0 1 1200
fcp_check_fan "$hw/pwm1" "$hw/fan1_input" 128 0 "$spun"
expect_equal 1 "$(grep -c 'RPM.*respond' <<< "${fcp_faults[0]}")" "A 20% RPM change after a 20% PWM step qualifies."
fcp_response_start 77 127 1000 "$((SECONDS - 30))"
fcp_check_fan "$hw/pwm1" "$hw/fan1_input" 127 0 "$spun"
expect_equal 0 "$(grep -c 'RPM.*respond' <<< "${fcp_faults[0]}")" "A PWM step below 20% is inconclusive."
fcp_response_start 77 255 0 "$((SECONDS - 30))"
fcp_check_fan "$hw/pwm1" "$hw/fan1_input" 255 0 "$spun"
expect_equal 0 "$(grep -c 'RPM.*respond' <<< "${fcp_faults[0]}")" "A zero RPM baseline cannot establish a response."
fan foo 1 1200
expect_equal '/controller mode stopped' "$(check 255)" "An unreadable speed is not judged."

fan 120 2 0
expect_equal 'mode;/controller' "$(check 255)" "Out of manual mode, only the lost control is reported and nothing else judged."
fan 255 '' 1200
expect_equal '/controller mode speed stopped' "$(check 255)" "A controller without an enable attribute has no mode to lose."
fan 255 1 1200
: > "$hw/pwm1_enable"
expect_equal '/controller' "$(check 255)" "An enable attribute without a value leaves the mode and everything after it unchecked."
fcp_check_fan "$hw/pwm9" "$hw/fan9_input" 255 0 "$spun"
expect_equal 'controller/' "${fcp_faults[0]%%|*}/${fcp_ok[*]}" "A missing controller is reported and nothing else judged."
# Before any write succeeds there is no target, but presence and mode still matter.
fan 255 2 1200
expect_equal 'mode;/controller' "$(check '')" "Lost control is reported before the first successful write."
fan 255 1 0
expect_equal '/controller mode' "$(check '')" "Without a target, speed and stopped checks are skipped."
fcp_check_fan "$hw/pwm9" "$hw/fan9_input" '' 0 "$spun"
expect_equal 'controller/' "${fcp_faults[0]%%|*}/${fcp_ok[*]}" "A missing controller is reported before the first successful write."

fan 255 1 0
expect_equal 'stopped;/controller mode speed' "$(check 255)" "A fan that spun before and reads 0 RPM at full speed has stopped."
fan 105 1 0
expect_equal 'stopped;/controller mode speed' "$(check 105)" "Above 40% a fan is expected to spin."
fan 102 1 0
expect_equal '/controller mode speed' "$(check 102)" "At the default Min Speed of 40% a fan may stop by design: not judged."
fan 255 1 ''
expect_equal '/controller mode speed' "$(check 255)" "Without an RPM reading the fan's spinning is not judged."
mkdir -p "$tmp/hw2" && echo 255 > "$tmp/hw2/pwm1" && echo 0 > "$tmp/hw2/fan1_input"
fcp_check_fan "$tmp/hw2/pwm1" "$tmp/hw2/fan1_input" 255 0 "$spun"
expect_equal '/controller mode speed' "/${fcp_ok[*]}${fcp_faults[*]}" "A fan moved to another controller does not inherit the old one's tachometer: 0 RPM is not judged."

# ===== fcp_track_faults =====
printf '#!/bin/bash\nprintf "%%s\\n" "$@" >> "%s/calls"\n' "$tmp" > "$fcp_notify_bin"
chmod +x "$fcp_notify_bin"
cfg_file="$tmp/fan.cfg"
echo 'notify="1"' > "$cfg_file"
dir="$tmp/faults" && mkdir -p "$dir"
speed='speed|is not reaching its set speed|Set to 100% but the controller reports 47%.'
stopped='stopped|has stopped|Set to 100% but the fan reports 0 RPM.'
sent() { grep -c "^$1\$" "$tmp/calls"; }
round() { # time, then problems ("key|subject|details") and keys seen fine
  local now="$1" arg
  shift
  fcp_faults=() fcp_ok=()
  for arg in "$@"; do
    if [[ "$arg" == *'|'* ]]; then fcp_faults+=("$arg"); else fcp_ok+=("$arg"); fi
  done
  fcp_track_faults "$now" Bay "$dir"
}
: > "$tmp/calls"

round 0 "$speed"
round 29 "$speed"
expect_equal 0 "$(sent warning)" "A problem shorter than 30 seconds does not warn."
round 30 "$speed"
expect_equal 1 "$(sent warning)" "A problem lasting 30 seconds warns."
expect_equal 1 "$(sent 'Fan Bay is not reaching its set speed')" "The warning names the fan and the problem."
round 60 "$speed"
expect_equal 1 "$(sent warning)" "An ongoing problem warns once."
fcp_fault_since=() fcp_clear_since=()
round 70 "$speed"
round 100 "$speed"
expect_equal 1 "$(sent warning)" "A restarted loop does not repeat a warning already sent."
round 110
round 200
expect_equal 0 "$(sent normal)" "A problem that cannot be checked has not recovered."
round 210 speed
round 239 speed
expect_equal 0 "$(sent normal)" "A problem gone for less than 30 seconds has not recovered."
round 240 speed
expect_equal 1 "$(sent normal)" "A warned problem gone for 30 seconds sends a recovery notice."
expect_equal 1 "$(sent 'This earlier warning has cleared: Fan Bay is not reaching its set speed.')" "The notice says which problem resolved."
round 300 speed
expect_equal 1 "$(sent normal)" "A recovery is announced once."

: > "$tmp/calls"
round 400 "$stopped"
round 430 "$stopped"
round 440 stopped
round 460 "$stopped"
round 470 stopped
round 490 "$stopped"
expect_equal 1 "$(sent warning)" "A flapping problem warns once."
expect_equal 0 "$(sent normal)" "A flapping problem is not announced as recovered."
round 500 stopped
round 515
round 520 stopped
round 549 stopped
expect_equal 0 "$(sent normal)" "A round that cannot check restarts the recovery clock."
round 550 stopped
expect_equal 1 "$(sent normal)" "Fine for 30 seconds in a row, the problem has recovered."
round 600 "$speed"
round 610 speed
round 630 "$speed"
expect_equal 1 "$(sent warning)" "A problem that clears starts a new stretch, and one never warned about clears silently."

: > "$tmp/calls"
echo 'notify="0"' > "$cfg_file"
round 700 "$stopped"
round 730 "$stopped"
expect_equal 0 "$(sent warning)" "With Problem Alerts off, nothing is sent."
echo 'notify=1' > "$cfg_file"
round 740 "$stopped"
expect_equal 1 "$(sent warning)" "Switched back on, an ongoing problem warns without a restart."
echo ' notify=0' > "$cfg_file"
round 750 stopped
round 780 stopped
expect_equal 0 "$(sent normal)" "With Problem Alerts off, in any format the cfg accepts, a recovery is not announced."
expect_equal '' "$(ls "$dir")" "A recovery while alerts are off still closes the incident."
echo 'custom="Bay"' > "$cfg_file"
round 800 "$stopped"
round 830 "$stopped"
expect_equal 2 "$(sent warning)" "A cfg without the switch, as on every upgraded fan, has alerts on."
rm "$cfg_file"
round 840 stopped
round 870 stopped
expect_equal 1 "$(sent normal)" "Without a readable cfg, alerts stay on."
echo 'notify="1"' > "$cfg_file"

: > "$tmp/calls"
good_notify=$fcp_notify_bin fcp_notify_bin=false
round 900 "$stopped"
round 930 "$stopped"
fcp_notify_bin=$good_notify
round 940 "$stopped"
expect_equal 1 "$(sent warning)" "A warning that failed to send is tried again on the next round."
fcp_notify_bin=false
round 950 stopped
round 980 stopped
fcp_notify_bin=$good_notify
round 990 stopped
expect_equal 1 "$(sent normal)" "A recovery notice that failed to send is tried again on the next round."
: > "$tmp/calls"
fcp_fault_since=() fcp_clear_since=()
fcp_faults=("$speed") fcp_ok=()
fcp_track_faults 1000 Bay "$tmp/nodir"
fcp_track_faults 1030 Bay "$tmp/nodir"
fcp_track_faults 1040 Bay "$tmp/nodir"
expect_equal 0 "$(sent warning)" "Without a flag directory no warning is sent, so rounds cannot repeat it."
round 1100 "$speed"
round 1130 "$speed"
rm -f "$dir/speed"
round 1140 "$speed"
expect_equal 2 "$(sent warning)" "A warning whose flag is missing, as after an interrupted send, is sent again."

# ===== fcp_warn_not_started =====
: > "$tmp/calls"
fcp_warn_not_started "$cfg_file" Bay '' "$tmp/unstarted"
fcp_warn_not_started "$cfg_file" Bay '' "$tmp/unstarted"
expect_equal 1 "$(sent 'Fan Bay has lost its PWM controller')" "A fan that cannot start warns once, until the loop sees its controller back."
fcp_faults=() fcp_ok=(controller)
fcp_track_faults 0 Bay "$tmp/unstarted"
fcp_track_faults 30 Bay "$tmp/unstarted"
expect_equal 1 "$(sent normal)" "Once the fan starts with its controller, the loop announces the recovery."

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
