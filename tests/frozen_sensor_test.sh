#!/bin/bash
# A configured sensor that reads the same for hours while another source of
# the same fan moves (fcp_track_frozen) and its one warning per boot.

set -u

root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

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

hour=3600
gap=300
acpi=/sys/class/hwmon/hwmon2/temp1_input
# One loop round at the given time: an Asustor NAS fan on its CPU sensor and
# the ACPI thermal zone, which this board fills with 27.8 °C.
round() { # time cpu acpi [disk]
  local -a readings=("cpu|$2|1" "$acpi|$3|1")
  [[ -n "${4:-}" ]] && readings+=("disk:0|$4|0")
  fcp_track_frozen "$1" "$gap" "${readings[@]}"
}
# shellcheck disable=SC2154
found() { printf '%s;' "${fcp_frozen_found[@]}"; }
# Rounds every 2 minutes from start to end, the CPU swinging between lo and hi.
run() { # start end lo hi acpi
  local t cpu
  for (( t = $1; t <= $2; t += 120 )); do
    cpu=$(( $3 + (t / 120) % ($4 - $3 + 1) ))
    round "$t" "$cpu" "$5"
  done
}

run 0 $(( 6 * hour - 120 )) 48 71 27
expect_equal ';' "$(found)" "Under 6 hours unchanged is not yet a conclusion."
round $(( 6 * hour )) 55 27
expect_equal "$acpi|27|0|$(( 6 * hour ))|cpu|48|71;" "$(found)" \
  "Unchanged for 6 hours while the CPU moved 23 degrees: the sensor is named with the CPU's range."

round $(( 6 * hour + 120 )) 55 28
expect_equal ';' "$(found)" "One degree of change ends the stretch."
run $(( 6 * hour + 240 )) $(( 13 * hour )) 50 59 28
expect_equal ';' "$(found)" "Another source moving by less than 10 degrees is no evidence: a steady machine has steady sensors."

fcp_frozen_run=() fcp_frozen_range=()
run 0 $(( 3 * hour )) 48 71 27
run $(( 3 * hour + gap + 120 )) $(( 7 * hour )) 48 71 27
expect_equal ';' "$(found)" "A gap longer than the interval allows (the loop was not running) starts the stretch over."

fcp_frozen_run=() fcp_frozen_range=()
for (( t = 0; t <= 6 * hour; t += 120 )); do round "$t" $(( 40 + (t / 120) % 3 )) 27 $(( 30 + (t / hour) * 2 )); done
expect_equal "$acpi|27|0|$(( 6 * hour ))|disk:0|30|42;" "$(found)" \
  "A disk group that warmed by 12 degrees is evidence too, and the source that moved most is named."
fcp_frozen_run=() fcp_frozen_range=()
for (( t = 0; t <= 6 * hour; t += 120 )); do round "$t" $(( 48 + (t / 120) % 24 )) $(( 27 + (t / 120) % 2 )) 35; done
expect_equal ';' "$(found)" "A disk group is never judged itself: its reading is the hottest of several drives."

fcp_frozen_run=() fcp_frozen_range=()
for (( t = 0; t <= 6 * hour; t += 120 )); do
  fcp_track_frozen "$t" "$gap" "cpu||1" "$acpi|27|1"
done
expect_equal ';' "$(found)" "A source without a reading is no evidence."

# The tracking survives a loop restart, and is published for the page.
fcp_frozen_run=() fcp_frozen_range=()
run 0 $(( 4 * hour )) 48 71 27
fcp_frozen_save "$tmp/state"
fcp_frozen_run=() fcp_frozen_range=()
fcp_frozen_load "$tmp/state"
run $(( 4 * hour + 120 )) $(( 6 * hour )) 48 71 27
expect_equal "$acpi|27|0|$(( 6 * hour ))|cpu|48|71;" "$(found)" "A restarted loop must keep the hours already seen."
fcp_frozen_publish "$tmp/frozen_Bay"
expect_equal "$acpi|27|0|$(( 6 * hour ))|cpu|48|71" "$(cat "$tmp/frozen_Bay")" "The conclusion is published for the settings page."
round $(( 6 * hour + 120 )) 55 28
fcp_frozen_publish "$tmp/frozen_Bay"
expect_equal "" "$(cat "$tmp/frozen_Bay")" "A sensor that changed is no longer shown."
printf 'run|cpu|x|1|2\nrange|cpu>a|1\n' > "$tmp/bad_state"
fcp_frozen_load "$tmp/bad_state"
expect_equal 0 "$(( ${#fcp_frozen_run[@]} + ${#fcp_frozen_range[@]} ))" "A damaged state file is ignored."

# ===== The warning =====
printf '#!/bin/bash\nprintf "%%s\\n" "$@" >> "%s/calls"\n' "$tmp" > "$fcp_notify_bin"
chmod +x "$fcp_notify_bin"
cfg_file="$tmp/fan.cfg"
echo 'custom="Bay"' > "$cfg_file"
cpu_sensor=/sys/class/hwmon/hwmon4/temp1_input
conclusion="$acpi|27|0|$(( 6 * hour ))|cpu|48|71"
sent() { grep -c '^-s$' "$tmp/calls" 2>/dev/null; }

fcp_notify_frozen Bay "$tmp/flag" "$conclusion"
fcp_notify_frozen Bay "$tmp/flag" "$conclusion"
expect_equal 1 "$(sent)" "A frozen sensor is warned about once per boot, by default."
expect_equal 1 "$(grep -cF "$acpi has read 27 °C for 6 hours while the CPU sensor $cpu_sensor varied 48–71 °C." "$tmp/calls")" \
  "The warning names the sensor, how long, and what moved meanwhile."
fcp_notify_frozen Bay "$tmp/flag" "cpu|27|0|$(( 6 * hour ))|disk:0|30|42"
expect_equal 2 "$(sent)" "Another sensor of the same fan gets its own warning."
: > "$tmp/calls"
echo 'frozen_notify="0"' > "$cfg_file"
fcp_notify_frozen Bay "$tmp/off_flag" "$conclusion"
expect_equal 0 "$(sent)" "With the Frozen Sensors switch off, nothing is sent."
echo 'notify="0"' > "$cfg_file"
fcp_notify_frozen Bay "$tmp/off_flag" "$conclusion"
expect_equal 1 "$(sent)" "The switch is its own: Problem Alerts off does not silence it."
: > "$tmp/calls"
good_notify=$fcp_notify_bin fcp_notify_bin=false
fcp_notify_frozen Bay "$tmp/retry_flag" "$conclusion"
fcp_notify_bin=$good_notify
fcp_notify_frozen Bay "$tmp/retry_flag" "$conclusion"
expect_equal 1 "$(sent)" "A warning that failed to send is tried again on the next round."
fcp_notify_frozen Bay "$tmp/missing/flag" "$conclusion"
expect_equal 1 "$(sent)" "Without a flag file, no warning is sent, so rounds cannot repeat it."

if (( failures > 0 )); then
  printf '%d frozen sensor assertion(s) failed.\n' "$failures" >&2
  exit 1
fi
echo "frozen sensor tests passed"
