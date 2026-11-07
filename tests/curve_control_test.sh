#!/bin/bash

set -u

root=$(cd "$(dirname "$0")/.." && pwd)
# The entry points the loop and the manual run source, so the curve library is
# reached the same way they reach it.
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

# Each case starts with empty source state, as a new loop process does.
forget_state() {
  fcp_curve_eff=()
  fcp_curve_latch=()
  fcp_curve_fp=()
  fcp_curve_stamp=()
  fcp_curve_synced=()
  fcp_curve_synced_output=""
}

# Removes a source's curve fields, so it falls back to the legacy ramp.
clear_rule() {
  unset "${1}_curve" "${1}_curve_mode" "${1}_hysteresis" \
    "${1}_emergency_temp" "${1}_emergency_pwm"
}

# The legacy ramp runs from pwm (102) up to max (255).
pwm=102
max=255

# ===== Legacy straight ramp (no PREFIX_curve) =====
# Without a curve a source keeps the ramp it always had, integer math included.
clear_rule cpu
forget_state
fcp_curve_pwm cpu 37 35 45
expect_equal 132 "$curve_pwm" "A legacy ramp rounds down between LOW and HIGH."
fcp_curve_pwm cpu 44 35 45
expect_equal 239 "$curve_pwm" "The legacy ramp keeps its integer result near HIGH."
fcp_curve_pwm cpu 35 35 45
expect_equal 102 "$curve_pwm" "The legacy ramp starts at pwm when the reading equals LOW."
fcp_curve_pwm cpu 45 35 45
expect_equal 255 "$curve_pwm" "The legacy ramp reaches max when the reading equals HIGH."
fcp_curve_pwm cpu 30 35 45
expect_equal 102 "$curve_pwm" "A reading below LOW stays at pwm."
fcp_curve_pwm cpu 90 35 45
expect_equal 255 "$curve_pwm" "A reading above HIGH stays at max."
fcp_curve_pwm cpu 40 40 40
expect_equal 102 "$curve_pwm" "A legacy range with LOW equal to HIGH never divides by zero at LOW."
fcp_curve_pwm cpu 50 40 40
expect_equal 255 "$curve_pwm" "A legacy range with LOW equal to HIGH never divides by zero above it."
fcp_curve_pwm cpu 45 50 40
expect_equal 102 "$curve_pwm" "A reversed legacy range never divides by zero."
fcp_curve_pwm cpu 35 'x[$(touch /nonexistent)]' 45
expect_equal 255 "$curve_pwm" "A non-numeric legacy bound fails hot, never reaches arithmetic."

# ===== Curve settings without their curve are corrupt, not legacy =====
# Falling back to the legacy ramp would drop an emergency threshold that is
# still configured: the fan would stay at the normal cap instead of reaching it.
for field in curve_mode hysteresis emergency_temp emergency_pwm; do
  clear_rule cpu
  forget_state
  declare -g "cpu_$field=$([[ $field == curve_mode ]] && echo linear || echo 5)"
  fcp_curve_pwm cpu 40 35 45
  expect_equal 255 "$curve_pwm" "A source with cpu_$field but no curve fails hot; the legacy ramp would read 178."
done
clear_rule cpu
forget_state
fcp_curve_pwm cpu 40 35 45
expect_equal 178 "$curve_pwm" "A source with no curve settings at all is still a legacy ramp."
cpu_emergency_temp=""
fcp_curve_pwm cpu 40 35 45
expect_equal 255 "$curve_pwm" "A blank emergency threshold left without its curve is still a corrupt rule."
clear_rule cpu

# ===== Migrated two-point linear curve =====
# An existing config becomes "LOW:pwm,HIGH:max" and must read exactly as before.
clear_rule cpu
forget_state
cpu_curve="35:102,45:255"
fcp_curve_pwm cpu 37 35 45
expect_equal 132 "$curve_pwm" "A migrated two-point curve gives the legacy ramp value."
fcp_curve_pwm cpu 44 35 45
expect_equal 239 "$curve_pwm" "A migrated two-point curve gives the legacy value near HIGH."
fcp_curve_pwm cpu 30 35 45
expect_equal 102 "$curve_pwm" "A migrated two-point curve holds pwm below its first point."
fcp_curve_pwm cpu 50 35 45
expect_equal 255 "$curve_pwm" "A migrated two-point curve holds max above its last point."

# ===== Linear between points, endpoints saturate =====
clear_rule cpu
forget_state
cpu_curve="30:76,40:115,50:255"
cpu_curve_mode=linear
fcp_curve_pwm cpu 0 35 45
expect_equal 76 "$curve_pwm" "Below the first point the curve holds its first PWM."
fcp_curve_pwm cpu 30 35 45
expect_equal 76 "$curve_pwm" "At the first point the curve gives its PWM."
fcp_curve_pwm cpu 35 35 45
expect_equal 95 "$curve_pwm" "Linear mode interpolates between points, rounding down."
fcp_curve_pwm cpu 40 35 45
expect_equal 115 "$curve_pwm" "At a middle point the linear curve gives that point's PWM."
fcp_curve_pwm cpu 45 35 45
expect_equal 185 "$curve_pwm" "Linear mode interpolates the second segment."
fcp_curve_pwm cpu 50 35 45
expect_equal 255 "$curve_pwm" "At the last point the curve gives its PWM, 255 inclusive."
fcp_curve_pwm cpu 300 35 45
expect_equal 255 "$curve_pwm" "Above the last point the curve holds its last PWM."

# ===== Stepped: each point holds until the next point's temperature =====
cpu_curve_mode=stepped
fcp_curve_pwm cpu 0 35 45
expect_equal 76 "$curve_pwm" "Stepped mode holds the first PWM below the first point."
fcp_curve_pwm cpu 30 35 45
expect_equal 76 "$curve_pwm" "Stepped mode gives the first PWM at the first point."
fcp_curve_pwm cpu 39 35 45
expect_equal 76 "$curve_pwm" "Stepped mode does not rise before the next point."
fcp_curve_pwm cpu 40 35 45
expect_equal 115 "$curve_pwm" "Stepped mode rises exactly at the next point's temperature."
fcp_curve_pwm cpu 49 35 45
expect_equal 115 "$curve_pwm" "Stepped mode holds the middle PWM up to the last point."
fcp_curve_pwm cpu 50 35 45
expect_equal 255 "$curve_pwm" "Stepped mode reaches the last PWM at the last point."
fcp_curve_pwm cpu 300 35 45
expect_equal 255 "$curve_pwm" "Stepped mode holds the last PWM above the last point."

# ===== Flat sections =====
clear_rule cpu
forget_state
cpu_curve="30:100,40:100,50:200"
cpu_curve_mode=linear
fcp_curve_pwm cpu 35 35 45
expect_equal 100 "$curve_pwm" "A flat linear section stays flat."
fcp_curve_pwm cpu 40 35 45
expect_equal 100 "$curve_pwm" "The end of a flat section gives its PWM."
fcp_curve_pwm cpu 45 35 45
expect_equal 150 "$curve_pwm" "The rise after a flat section starts from its PWM."
cpu_curve_mode=stepped
fcp_curve_pwm cpu 45 35 45
expect_equal 100 "$curve_pwm" "A flat stepped section holds its PWM until the next point."
fcp_curve_pwm cpu 50 35 45
expect_equal 200 "$curve_pwm" "Stepped mode rises at the point after a flat section."

# ===== Raw 0 and 255 =====
clear_rule cpu
forget_state
cpu_curve="20:0,40:255"
cpu_curve_mode=linear
fcp_curve_pwm cpu 10 35 45
expect_equal 0 "$curve_pwm" "Raw 0 is a valid PWM and is output as 0."
fcp_curve_pwm cpu 20 35 45
expect_equal 0 "$curve_pwm" "A point at raw 0 gives 0."
fcp_curve_pwm cpu 30 35 45
expect_equal 127 "$curve_pwm" "Rising from raw 0 rounds down."
fcp_curve_pwm cpu 40 35 45
expect_equal 255 "$curve_pwm" "A point at raw 255 gives 255."

# ===== Normal max is a cap; points stay as stored =====
clear_rule cpu
forget_state
cpu_curve="30:76,40:115,50:255"
cpu_curve_mode=linear
max=200
fcp_curve_pwm cpu 45 35 45
expect_equal 185 "$curve_pwm" "A curve value under the cap is not clipped."
fcp_curve_pwm cpu 49 35 45
expect_equal 200 "$curve_pwm" "A curve value over the cap is clipped to max."
fcp_curve_pwm cpu 50 35 45
expect_equal 200 "$curve_pwm" "The last point is clipped to max."
max=255
fcp_curve_pwm cpu 49 35 45
expect_equal 241 "$curve_pwm" "Raising max restores the unclipped value; stored points never changed."
max=255

# ===== Hysteresis: cooling deadband, immediate rise =====
# Curve "40:100,60:200" is 100 + (E-40)*5, so each effective temperature is visible.
clear_rule cpu
forget_state
cpu_curve="40:100,60:200"
cpu_curve_mode=linear
cpu_hysteresis=5
fcp_curve_pwm cpu 60 35 45
expect_equal 200 "$curve_pwm" "The first reading sets the effective temperature."
fcp_curve_pwm cpu 58 35 45
expect_equal 200 "$curve_pwm" "A small cooling step inside the deadband holds the effective temperature."
fcp_curve_pwm cpu 56 35 45
expect_equal 200 "$curve_pwm" "Cooling to just inside the deadband still holds the effective temperature."
fcp_curve_pwm cpu 54 35 45
expect_equal 195 "$curve_pwm" "Cooling past the deadband follows actual plus hysteresis."
fcp_curve_pwm cpu 50 35 45
expect_equal 175 "$curve_pwm" "Further cooling follows actual plus hysteresis."
fcp_curve_pwm cpu 50 35 45
expect_equal 175 "$curve_pwm" "A repeated reading does not move the effective temperature."
fcp_curve_pwm cpu 53 35 45
expect_equal 175 "$curve_pwm" "A warmer reading inside the deadband never raises a cooling effective temperature."

forget_state
fcp_curve_pwm cpu 45 35 45
expect_equal 125 "$curve_pwm" "A cool start follows the reading."
fcp_curve_pwm cpu 42 35 45
expect_equal 125 "$curve_pwm" "A cooling reading holds the effective temperature inside the deadband."
fcp_curve_pwm cpu 48 35 45
expect_equal 140 "$curve_pwm" "A hot reading is followed at once, without waiting for the deadband."

# ===== Emergency latch, hysteresis 0 =====
# Curve "30:76,70:200" is 76 + (E-30)*124/40 in integer math.
clear_rule cpu
forget_state
cpu_curve="30:76,70:200"
cpu_curve_mode=linear
cpu_emergency_temp=60
cpu_emergency_pwm=255
max=200
fcp_curve_pwm cpu 59 35 45
expect_equal 165 "$curve_pwm" "Below the emergency threshold the normal curve applies."
fcp_curve_pwm cpu 60 35 45
expect_equal 255 "$curve_pwm" "Emergency activates at the threshold and is not capped by max."
fcp_curve_pwm cpu 59 35 45
expect_equal 165 "$curve_pwm" "With no hysteresis the emergency releases just below the threshold."
fcp_curve_pwm cpu 60 35 45
expect_equal 255 "$curve_pwm" "Emergency re-activates at the threshold after a release."

# ===== Emergency latch, hysteresis 5 =====
clear_rule cpu
forget_state
cpu_curve="30:76,70:200"
cpu_curve_mode=linear
cpu_hysteresis=5
cpu_emergency_temp=60
cpu_emergency_pwm=255
max=255
fcp_curve_pwm cpu 60 35 45
expect_equal 255 "$curve_pwm" "Emergency activates at the threshold."
fcp_curve_pwm cpu 58 35 45
expect_equal 255 "$curve_pwm" "Emergency holds while the reading is inside the hysteresis band."
fcp_curve_pwm cpu 56 35 45
expect_equal 255 "$curve_pwm" "Emergency holds one degree above the release point."
fcp_curve_pwm cpu 55 35 45
expect_equal 169 "$curve_pwm" "Emergency releases at threshold minus hysteresis; the curve holds its effective temperature 60."
fcp_curve_pwm cpu 54 35 45
expect_equal 165 "$curve_pwm" "After release the curve follows actual plus hysteresis."

# ===== Emergency disabled by an empty threshold =====
clear_rule cpu
forget_state
cpu_curve="30:76,70:200"
cpu_curve_mode=linear
cpu_emergency_temp=""
max=200
fcp_curve_pwm cpu 90 35 45
expect_equal 200 "$curve_pwm" "An empty emergency threshold disables emergency; the cap applies."
max=255

# ===== Independent source states =====
clear_rule cpu
clear_rule aux
forget_state
cpu_curve="30:76,70:200"
cpu_curve_mode=linear
cpu_hysteresis=5
cpu_emergency_temp=60
cpu_emergency_pwm=255
aux_curve="30:76,70:200"
aux_curve_mode=linear
fcp_curve_pwm cpu 60 35 45
expect_equal 255 "$curve_pwm" "The CPU source latches its emergency."
fcp_curve_pwm aux 40 35 45
expect_equal 107 "$curve_pwm" "The Aux source is not latched by the CPU source."
fcp_curve_pwm cpu 58 35 45
expect_equal 255 "$curve_pwm" "The CPU source keeps its own latch while Aux is read."
fcp_curve_pwm aux 20 35 45
expect_equal 76 "$curve_pwm" "The Aux source uses its own effective temperature."
fcp_curve_pwm cpu 50 35 45
expect_equal 153 "$curve_pwm" "The CPU source releases on its own readings, from its own effective temperature."
fcp_curve_pwm aux 50 35 45
expect_equal 138 "$curve_pwm" "The Aux source is not affected by the CPU source's effective temperature."
clear_rule aux

# ===== Changing the rule resets its state =====
clear_rule cpu
forget_state
cpu_curve="30:76,70:200"
cpu_curve_mode=linear
cpu_hysteresis=5
fcp_curve_pwm cpu 60 35 45
fcp_curve_pwm cpu 50 35 45
expect_equal 153 "$curve_pwm" "The effective temperature follows actual plus hysteresis before the change."
cpu_hysteresis=10
fcp_curve_pwm cpu 50 35 45
expect_equal 138 "$curve_pwm" "A changed hysteresis starts from the new reading, not the old effective temperature."

forget_state
cpu_hysteresis=5
fcp_curve_pwm cpu 60 35 45
cpu_curve="30:76,70:201"
fcp_curve_pwm cpu 50 35 45
expect_equal 138 "$curve_pwm" "A changed curve starts from the new reading, not the old effective temperature."

# ===== Manual run continues the loop's state =====
# The loop publishes its state after each tick; the manual run loads it. A fresh
# process would read these as different PWM values.
state_file=$(mktemp)
clear_rule cpu
forget_state
cpu_curve="30:76,70:200"
cpu_curve_mode=linear
cpu_hysteresis=5
cpu_emergency_temp=60
cpu_emergency_pwm=255
fcp_curve_pwm cpu 60 35 45
fcp_curve_pwm cpu 50 35 45
fcp_curve_pwm cpu 50 35 45
fcp_curve_publish "$state_file"

forget_state
fcp_curve_load "$state_file"
fcp_curve_pwm cpu 53 35 45
expect_equal 153 "$curve_pwm" "A manual run honors the loop's effective temperature of 55."

forget_state
fcp_curve_pwm cpu 53 35 45
expect_equal 147 "$curve_pwm" "Without the loop's state, the same reading starts fresh at 53."

forget_state
fcp_curve_pwm cpu 60 35 45
fcp_curve_publish "$state_file"
forget_state
fcp_curve_load "$state_file"
fcp_curve_pwm cpu 58 35 45
expect_equal 255 "$curve_pwm" "A manual run honors the loop's emergency latch."

forget_state
fcp_curve_pwm cpu 58 35 45
expect_equal 162 "$curve_pwm" "Without the loop's latch, the same reading is not an emergency."

# A state file from a different rule is ignored, so an edited curve starts fresh.
printf 'cpu|other rule|55|0\n' > "$state_file"
forget_state
fcp_curve_load "$state_file"
fcp_curve_pwm cpu 53 35 45
expect_equal 147 "$curve_pwm" "A state file written for another rule is ignored."

# A malformed line is skipped rather than parsed into the arithmetic.
printf 'cpu|30:76,70:200;linear;5;60;255|x[$(touch /nonexistent)]|0\n' > "$state_file"
forget_state
fcp_curve_load "$state_file"
fcp_curve_pwm cpu 53 35 45
expect_equal 147 "$curve_pwm" "A malformed state line is skipped and the source starts fresh."
rm -f "$state_file"

# Gives source PREFIX the rule these tests share: curve 30:76,70:200, linear,
# hysteresis 5, emergency at 60 with PWM 255. At 58 it is 162 unlatched.
set_rule() {
  clear_rule "$1"
  declare -g "${1}_curve=30:76,70:200" "${1}_curve_mode=linear" "${1}_hysteresis=5" \
    "${1}_emergency_temp=60" "${1}_emergency_pwm=255"
}
max=255

# ===== State belongs to the inputs the source reads =====
# Deleting group 0 renumbers the groups: group 1's disks become group 0 with the
# same curve, and must not inherit group 0's emergency latch.
set_rule disk_group_0
forget_state
disk_group_0_disks="ata-A,ata-B"
fcp_curve_pwm disk_group_0 60 35 45
expect_equal 255 "$curve_pwm" "A disk group latches its emergency."
disk_group_0_disks="ata-B,ata-A"
fcp_curve_pwm disk_group_0 58 35 45
expect_equal 255 "$curve_pwm" "The same disks in another order keep the group's latch."
disk_group_0_disks="ata-C"
fcp_curve_pwm disk_group_0 58 35 45
expect_equal 162 "$curve_pwm" "Other disks under the same group number start fresh, not latched."

# The same holds for the state a snapshot carries between processes.
state_file=$(mktemp)
disk_group_0_disks="ata-A,ata-B"
forget_state
fcp_curve_pwm disk_group_0 60 35 45
fcp_curve_publish "$state_file"
forget_state
disk_group_0_disks="ata-B,ata-A"
fcp_curve_load "$state_file"
fcp_curve_pwm disk_group_0 58 35 45
expect_equal 255 "$curve_pwm" "A reordered disk list keeps the state a snapshot carries."
forget_state
disk_group_0_disks="ata-C"
fcp_curve_load "$state_file"
fcp_curve_pwm disk_group_0 58 35 45
expect_equal 162 "$curve_pwm" "A snapshot taken for other disks is not inherited."

# Disk ids end up in the pipe-separated snapshot, so a pipe in one must not tear it.
forget_state
disk_group_0_disks="ata-A|x,ata-B"
fcp_curve_pwm disk_group_0 60 35 45
fcp_curve_publish "$state_file"
forget_state
fcp_curve_load "$state_file"
fcp_curve_pwm disk_group_0 58 35 45
expect_equal 255 "$curve_pwm" "A disk id containing a pipe still round-trips through the snapshot."
rm -f "$state_file"
clear_rule disk_group_0
unset disk_group_0_disks

set_rule cpu
forget_state
cpu_sensor=/sys/hwmon/hwmon1/temp1_input
fcp_curve_pwm cpu 60 35 45
cpu_sensor=/sys/hwmon/hwmon2/temp1_input
fcp_curve_pwm cpu 58 35 45
expect_equal 162 "$curve_pwm" "A swapped CPU sensor starts fresh, not latched."
unset cpu_sensor

set_rule aux
forget_state
aux_sensor="/sys/a,/sys/b"
fcp_curve_pwm aux 60 35 45
aux_sensor="/sys/b,/sys/a"
fcp_curve_pwm aux 58 35 45
expect_equal 255 "$curve_pwm" "The same aux sensors in another order keep the latch."
aux_sensor="/sys/c"
fcp_curve_pwm aux 58 35 45
expect_equal 162 "$curve_pwm" "Other aux sensors start fresh, not latched."
unset aux_sensor
clear_rule aux

# ===== An unusable CPU reading is a gap, not a cool reading =====
# The gap keeps its documented output (the curve at 0 °C) but must not enter the
# state: a hot fan stays latched through it and resumes at once on recovery.
set_rule cpu
cpu_min_temp=35
cpu_max_temp=45
forget_state
fcp_cpu_pwm 60000
expect_equal 255 "$cpu_pwm_val" "A hot CPU latches its emergency."
expect_equal 1 "$cpu_usable" "A plain reading is usable."
expect_equal 60 "$cpu_temp" "A usable reading is whole degrees."
fcp_cpu_pwm 0
expect_equal 0 "$cpu_usable" "An input reading exactly 0 is an unpopulated sensor, not a reading."
expect_equal 76 "$cpu_pwm_val" "The gap keeps its documented output, the curve at 0 °C."
fcp_cpu_pwm 58000
expect_equal 255 "$cpu_pwm_val" "Recovery inside the hysteresis band resumes the latch the gap did not clear."
fcp_cpu_pwm 55000
expect_equal 169 "$cpu_pwm_val" "A valid cool reading releases the latch."

for garbage in "" "n/a" "0" "-0" "999999000" "-200000"; do
  forget_state
  fcp_cpu_pwm 60000
  fcp_cpu_pwm "$garbage"
  expect_equal 0 "$cpu_usable" "'$garbage' is not a CPU reading."
  fcp_cpu_pwm 58000
  expect_equal 255 "$cpu_pwm_val" "A gap of '$garbage' must not clear the emergency latch."
done

# A nonzero raw value that works out to 0 °C is a real reading, not a gap.
for cold in 400 -5000; do
  forget_state
  fcp_cpu_pwm 60000
  fcp_cpu_pwm "$cold"
  expect_equal 1 "$cpu_usable" "A raw value of $cold is a real, cold reading."
  expect_equal 0 "$cpu_temp" "A raw value of $cold works out to 0 °C."
  fcp_cpu_pwm 58000
  expect_equal 162 "$cpu_pwm_val" "A real cold reading releases the latch, unlike a gap."
done

# The legacy ramp has no state, and a gap reads at 0 °C as before.
clear_rule cpu
forget_state
pwm=102
fcp_cpu_pwm 0
expect_equal 102 "$cpu_pwm_val" "A legacy source in a gap reads its minimum speed."
clear_rule cpu
unset cpu_min_temp cpu_max_temp

# ===== A snapshot that cannot be written keeps the newer state =====
# Runtime storage that fills after the emergency activates must not let the next
# round reload the older, unlatched snapshot. The failure is real: the
# snapshot's temporary file is /dev/full, which the kernel refuses with ENOSPC.
state_dir=$(mktemp -d)
state="$state_dir/state"
set_rule cpu
forget_state

fcp_curve_begin "$state"
fcp_curve_pwm cpu 50 35 45
fcp_curve_end "$state" 138
rc=$?
expect_equal 0 "$rc" "A snapshot on working storage is published."
older=$(cat "$state")
fcp_curve_begin "$state"
fcp_curve_end "$state"
expect_equal "@output|138||" "$(grep '^@output' "$state")" "Ending without an output keeps the one the snapshot had."

fcp_curve_begin "$state"
fcp_curve_pwm cpu 60 35 45
expect_equal 255 "$curve_pwm" "Emergency activates."
ln -s /dev/full "$state.tmp.$$"
fcp_curve_end "$state" 255 2>/dev/null
rc=$?
expect_equal 1 "$rc" "A snapshot that cannot be written is reported, not swallowed."
expect_equal "$older" "$(cat "$state")" "A failed publish leaves the previous snapshot intact."
expect_equal absent "$([[ -e "$state.tmp.$$" || -L "$state.tmp.$$" ]] && echo present || echo absent)" "The partial snapshot is removed."
if flock -n "$state.lock" true; then competing=acquired; else competing=blocked; fi
expect_equal acquired "$competing" "A failed publish still releases the lock."

fcp_curve_begin "$state"
fcp_curve_pwm cpu 58 35 45
expect_equal 255 "$curve_pwm" "The next round keeps the latch the failed snapshot held, not the older state."
fcp_curve_end "$state" 255
rc=$?
expect_equal 0 "$rc" "Once storage works again the snapshot is published."

fcp_curve_begin "$state"
fcp_curve_pwm cpu 55 35 45
expect_equal 169 "$curve_pwm" "The latch releases at the valid cool reading."
fcp_curve_end "$state" 169

# After a successful publish the process follows the shared snapshot again, so
# the state another run published in the meantime is not overwritten from memory.
(
  source "$root/src/usr/local/emhttp/plugins/fanctrlplus2/scripts/curve_control.sh"
  set_rule cpu
  max=255
  fcp_curve_begin "$state"
  fcp_curve_pwm cpu 40 35 45
  fcp_curve_end "$state" 120
)
fcp_curve_begin "$state"
fcp_curve_pwm cpu 58 35 45
expect_equal 162 "$curve_pwm" "A process that has published follows the snapshot, so another run's state is adopted."
fcp_curve_end "$state" 162
rm -rf "$state_dir"
clear_rule cpu

# ===== A sample taken before the state it meets cannot undo that state =====
# Sources are sampled before the curve-state lock is taken, so another run can
# publish a newer observation while this one waits. A sample carries the time its
# observation began (the optional last argument, microseconds). Against state from
# a newer observation it may still heat the fan or enter the emergency, but it
# never cools it or clears the latch. Rule: curve 30:76,70:200 linear, hysteresis
# 5, emergency 60 at PWM 255; the unlatched curve gives 91 at 35, 107 at 40, 122
# at 45 and 169 at 60.
set_rule cpu
max=255
forget_state
fcp_curve_pwm cpu 60 35 45 2000
expect_equal 255 "$curve_pwm" "A hot observation latches the emergency."
fcp_curve_pwm cpu 35 35 45 1000
expect_equal 255 "$curve_pwm" "An older cool sample cannot release a newer emergency latch."
fcp_curve_pwm cpu 58 35 45 1500
expect_equal 255 "$curve_pwm" "Nor can an older sample inside the hysteresis band."
expect_equal 2000 "${fcp_curve_stamp[cpu]}" "An older sample does not move the state's observation time."
fcp_curve_pwm cpu 35 35 45 3000
expect_equal 107 "$curve_pwm" "A valid newer cool sample releases the latch normally."
expect_equal 3000 "${fcp_curve_stamp[cpu]}" "A newer sample moves the observation time."
fcp_curve_pwm cpu 60 35 45 4000
fcp_curve_pwm cpu 35 35 45 4000
expect_equal 255 "$curve_pwm" "Equal timestamps cannot prove that a cooling observation is newer."
fcp_curve_pwm cpu 35 35 45 4001
expect_equal 107 "$curve_pwm" "A strictly newer cool observation releases normally after a timestamp tie."

# The effective temperature is not lowered by an older cool sample either.
cpu_emergency_temp=""
forget_state
fcp_curve_pwm cpu 60 35 45 2000
expect_equal 169 "$curve_pwm" "A hot observation sets the effective temperature."
fcp_curve_pwm cpu 40 35 45 1000
expect_equal 169 "$curve_pwm" "An older cool sample cannot lower the effective temperature."
fcp_curve_pwm cpu 40 35 45 3000
expect_equal 122 "$curve_pwm" "A newer cool sample follows actual plus hysteresis."
set_rule cpu

# An older hot sample is acted on, conservatively, and the next valid newer cool
# sample releases it.
forget_state
fcp_curve_pwm cpu 35 35 45 2000
expect_equal 91 "$curve_pwm" "A cool observation follows the curve."
fcp_curve_pwm cpu 60 35 45 1000
expect_equal 255 "$curve_pwm" "An older hot sample still enters the emergency."
expect_equal 2000 "${fcp_curve_stamp[cpu]}" "An older hot sample does not move the observation time."
fcp_curve_pwm cpu 58 35 45 1500
expect_equal 255 "$curve_pwm" "An older sample in the band keeps the latch it entered."
fcp_curve_pwm cpu 40 35 45 2500
expect_equal 122 "$curve_pwm" "A valid newer cool sample releases it."

# A call without a stamp is an ordinary sequential evaluation and keeps the time.
forget_state
fcp_curve_pwm cpu 60 35 45 2000
fcp_curve_pwm cpu 35 35 45
expect_equal 107 "$curve_pwm" "A sample without a stamp releases normally."
expect_equal 2000 "${fcp_curve_stamp[cpu]}" "A sample without a stamp leaves the observation time."
forget_state
fcp_curve_pwm cpu 60 35 45 2000
fcp_curve_pwm cpu 35 35 45 'x[$(touch /nonexistent)]'
expect_equal 107 "$curve_pwm" "A stamp that is not a bounded number counts as no stamp, and is never evaluated."

# A gap in the readings neither moves the observation time nor releases a latch.
forget_state
fcp_curve_pwm cpu 60 35 45 2000
fcp_curve_pwm cpu 0 35 45 peek
expect_equal 2000 "${fcp_curve_stamp[cpu]}" "A peek does not move the observation time."
fcp_cpu_pwm 0 9000
expect_equal 76 "$cpu_pwm_val" "A gap keeps its documented output."
expect_equal 2000 "${fcp_curve_stamp[cpu]}" "A gap does not move the observation time, however late it is stamped."
fcp_cpu_pwm 35000 1500
expect_equal 255 "$cpu_pwm_val" "An older sample after a gap still cannot release the latch."
fcp_cpu_pwm 35000 9500
expect_equal 107 "$cpu_pwm_val" "A newer sample after a gap releases it."

# A changed binding is another identity: nothing is held against it.
forget_state
cpu_sensor=/sys/a/temp1_input
fcp_curve_pwm cpu 60 35 45 2000
cpu_sensor=/sys/b/temp1_input
fcp_curve_pwm cpu 35 35 45 1000
expect_equal 91 "$curve_pwm" "A swapped sensor starts fresh, whatever the older state held."
expect_equal 1000 "${fcp_curve_stamp[cpu]}" "A fresh state takes the sample's time."
unset cpu_sensor

# Every source is ordered the same way.
set_rule aux
forget_state
fcp_curve_pwm aux 60 35 45 2000
fcp_curve_pwm aux 35 35 45 1000
expect_equal 255 "$curve_pwm" "An older aux sample cannot release a newer aux latch."
clear_rule aux

set_rule disk_group_0
disk_group_count=1
disk_group_0_name=Pool
disk_group_0_disks=ata-A
forget_state
fcp_disk_temps=([0]=60)
fcp_disk_stamps=([0]=2000)
calculate_disk_pwm
expect_equal 255 "$disk_pwm_val" "A hot disk group latches its emergency."
fcp_disk_temps=([0]=35)
fcp_disk_stamps=([0]=1000)
calculate_disk_pwm
expect_equal 255 "$disk_pwm_val" "An older disk sample cannot release a newer disk latch."
fcp_disk_stamps=([0]=3000)
calculate_disk_pwm
expect_equal 107 "$disk_pwm_val" "A newer disk sample releases it."
fcp_disk_temps=()
fcp_disk_stamps=()
clear_rule disk_group_0
unset disk_group_count disk_group_0_name disk_group_0_disks

# ===== Observation times =====
fcp_stamp_now; first=$fcp_now
fcp_stamp_now; second=$fcp_now
expect_equal yes "$([[ "$first" =~ ^[1-9][0-9]{9,15}$ ]] && echo yes || echo no)" "The observation time is a bounded number of microseconds."
expect_equal yes "$( (( second >= first )) && echo yes || echo no )" "Observation times do not go backwards."
expect_equal yes "$([[ "$(unset EPOCHREALTIME; fcp_stamp_now; printf '%s' "$fcp_now")" =~ ^[1-9][0-9]{9}000000$ ]] && echo yes || echo no)" "Without EPOCHREALTIME the time falls back to whole seconds."

# ===== The snapshot carries the observation time =====
state_file=$(mktemp)
set_rule cpu
forget_state
fcp_curve_pwm cpu 60 35 45 2000
fcp_curve_publish "$state_file"
expect_equal 2000 "$(grep '^cpu|' "$state_file" | cut -d'|' -f5)" "The published snapshot carries the observation time."
forget_state
fcp_curve_load "$state_file"
expect_equal 2000 "${fcp_curve_stamp[cpu]}" "A load restores the observation time."
fcp_curve_pwm cpu 35 35 45 1000
expect_equal 255 "$curve_pwm" "A process holding an older sample cannot undo the state a snapshot carries."

printf 'cpu|x|55|0|x[$(touch /nonexistent)]\n' > "$state_file"
forget_state
fcp_curve_load "$state_file"
expect_equal "" "${fcp_curve_eff[cpu]-}" "A snapshot line with a malformed observation time is skipped, never evaluated."

# A snapshot written before observation times existed has none and is still read.
forget_state
fcp_curve_pwm cpu 60 35 45
fp="${fcp_curve_fp[cpu]}"
printf 'cpu|%s|60|1\n' "$fp" > "$state_file"
forget_state
fcp_curve_load "$state_file"
fcp_curve_pwm cpu 58 35 45 1000
expect_equal 255 "$curve_pwm" "A snapshot line without an observation time keeps its latch, from time 0."
rm -f "$state_file"

# A clock rollback must not let an already sampled old temperature clear a latch.
# After rebasing the clock, the next freshly sampled round can release normally.
state_dir=$(mktemp -d)
state="$state_dir/state"
forget_state
fcp_curve_begin "$state"
fcp_curve_pwm cpu 60 35 45 9999999999999999
fcp_curve_end "$state" 255
forget_state
fcp_curve_begin "$state"
expect_equal 0 "${fcp_curve_stamp[cpu]}" "A clock rollback rebases the observation time."
fcp_curve_pwm cpu 35 35 45 1000
expect_equal 255 "$curve_pwm" "A clock rollback protects the in-flight round from releasing an emergency."
fcp_curve_end "$state" 255
fcp_curve_begin "$state"
fcp_stamp_now
fcp_curve_pwm cpu 35 35 45 "$fcp_now"
expect_equal 107 "$curve_pwm" "A fresh round after clock rebasing can release the latch."
fcp_curve_end "$state" 107
rm -rf "$state_dir"

# ===== Unpublished state is merged with what others published, hot side up =====
# While a snapshot cannot be written this process's memory is newer than the file
# in some ways and older in others. Whatever the file gained since this process
# last synced with it is merged: the hotter temperature, and the latch if either
# side holds it. What the file did not change says nothing new, so a latch that
# was released here stays released.
state_dir=$(mktemp -d)
state="$state_dir/state"
fail_next_publish() { ln -s /dev/full "$state.tmp.$$"; }
# Another process runs one sample, observed at time $2, against the shared snapshot.
other_process() {
  (
    source "$root/src/usr/local/emhttp/plugins/fanctrlplus2/scripts/curve_control.sh"
    set_rule cpu
    max=255
    fcp_curve_begin "$state"
    fcp_curve_pwm cpu "$1" 35 45 "$2"
    fcp_curve_end "$state" "$3"
  )
}

# This process latched while it could not publish; the other published a cool
# sample in the meantime, without ever having seen that latch.
set_rule cpu
forget_state
fcp_curve_begin "$state"
fcp_curve_pwm cpu 50 35 45 1000
fcp_curve_end "$state" 138
fcp_curve_begin "$state"
fcp_curve_pwm cpu 60 35 45 2000
fail_next_publish
fcp_curve_end "$state" 255 2>/dev/null
expect_equal 1 "$fcp_curve_unpublished" "A failed publish is remembered."
other_process 35 3000 91
fcp_curve_begin "$state"
fcp_curve_pwm cpu 58 35 45 4000
expect_equal 255 "$curve_pwm" "An emergency this process could not publish is not discarded by another run's snapshot."
expect_equal 91 "$fcp_curve_output" "The applied output another run published is taken up."
fcp_curve_end "$state" 255

# This process was cool and could not publish; the other published an emergency.
forget_state
rm -f "$state"
fcp_curve_begin "$state"
fcp_curve_pwm cpu 50 35 45 1000
fcp_curve_end "$state" 138
fcp_curve_begin "$state"
fcp_curve_pwm cpu 40 35 45 2000
fail_next_publish
fcp_curve_end "$state" 122 2>/dev/null
other_process 60 3000 255
fcp_curve_begin "$state"
fcp_curve_pwm cpu 58 35 45 4000
expect_equal 255 "$curve_pwm" "A newer emergency another run published is not replaced by older local memory."
expect_equal 255 "$fcp_curve_output" "The output the other run applied is taken up."

# A latch released here stays released while the file has not changed since the
# last sync, even though every publish keeps failing.
fail_next_publish
fcp_curve_end "$state" 255 2>/dev/null
fcp_curve_begin "$state"
fcp_curve_pwm cpu 40 35 45 5000
expect_equal 122 "$curve_pwm" "A valid newer cool sample releases the merged latch."
fail_next_publish
fcp_curve_end "$state" 122 2>/dev/null
fcp_curve_begin "$state"
fcp_curve_pwm cpu 40 35 45 6000
expect_equal 122 "$curve_pwm" "An unchanged older snapshot does not latch the fan again."
expect_equal 122 "$fcp_curve_output" "Nor does it replace the output applied here."
fcp_curve_end "$state" 122
expect_equal 0 "$fcp_curve_unpublished" "A successful publish ends unpublished mode."
expect_equal 0 "$(grep '^cpu|' "$state" | cut -d'|' -f4)" "The published snapshot carries the release."

# Rounds that ran without the lock are unpublished too: the memory they advanced
# is not replaced by the file's, nor does it replace a hotter file.
forget_state
rm -f "$state"
fcp_curve_begin "$state"
fcp_curve_pwm cpu 50 35 45 1000
fcp_curve_end "$state" 138
# Another open of the lock file, held here, conflicts with the one begin opens.
exec 9>"$state.lock"
flock -x 9
fcp_curve_lock_wait=1 fcp_curve_begin "$state"
expect_equal 0 "$fcp_curve_shared" "A held lock is not taken."
fcp_curve_pwm cpu 60 35 45 2000
flock -u 9
exec 9>&-
other_process 35 3000 91
fcp_curve_begin "$state"
fcp_curve_pwm cpu 58 35 45 4000
expect_equal 255 "$curve_pwm" "An emergency latched while the lock was unavailable survives the next snapshot."
fcp_curve_end "$state" 255
rm -rf "$state_dir"
clear_rule cpu

# ===== Malformed custom data fails hot (255), never low =====
clear_rule cpu
bad_curves=(
  "30:76"
  "30:76,40:115,50:255,60:255,70:255,80:255,90:255,100:255,110:255"
  "40:76,30:115"
  "30:200,40:100"
  "30:76,40:300"
  "30:76,301:255"
  "30:76,40:115,"
  " 30:76,40:115"
  "030:76,40:115"
  "0x1E:76,40:115"
  "30:76,30:115"
)
for bad in "${bad_curves[@]}"; do
  forget_state
  cpu_curve="$bad"
  cpu_curve_mode=linear
  unset cpu_hysteresis cpu_emergency_temp cpu_emergency_pwm
  fcp_curve_pwm cpu 35 35 45
  expect_equal 255 "$curve_pwm" "A malformed curve '$bad' must fail hot."
done

forget_state
cpu_curve="30:76,70:200"
cpu_curve_mode=wobbly
fcp_curve_pwm cpu 35 35 45
expect_equal 255 "$curve_pwm" "An unknown curve mode fails hot."

for hyst in 21 x -1 08; do
  forget_state
  cpu_curve_mode=linear
  cpu_hysteresis="$hyst"
  fcp_curve_pwm cpu 35 35 45
  expect_equal 255 "$curve_pwm" "A hysteresis of '$hyst' fails hot."
done
unset cpu_hysteresis

for em in 301 abc 08; do
  forget_state
  cpu_emergency_temp="$em"
  cpu_emergency_pwm=255
  fcp_curve_pwm cpu 35 35 45
  expect_equal 255 "$curve_pwm" "An emergency threshold of '$em' fails hot."
done

forget_state
cpu_curve="30:76,70:200"
cpu_emergency_temp=60
cpu_emergency_pwm=199
fcp_curve_pwm cpu 35 35 45
expect_equal 255 "$curve_pwm" "An emergency PWM below the last curve PWM fails hot."

forget_state
cpu_hysteresis=5
cpu_emergency_temp=3
cpu_emergency_pwm=220
fcp_curve_pwm cpu 2 35 45
expect_equal 76 "$curve_pwm" "A low emergency threshold is valid before activation."
fcp_curve_pwm cpu 3 35 45
expect_equal 220 "$curve_pwm" "The low emergency threshold activates normally."
fcp_curve_pwm cpu 0 35 45
expect_equal 220 "$curve_pwm" "An unreachable release threshold keeps emergency cooling active."

forget_state
fcp_curve_pwm cpu "" 35 45
expect_equal 255 "$curve_pwm" "A non-numeric reading fails hot."

clear_rule cpu
forget_state

# ===== Curve text is data, never code =====
marker_dir=$(mktemp -d)
marker="$marker_dir/ran"

forget_state
cpu_curve='30:76,40:x[$(touch '"$marker"')]'
fcp_curve_pwm cpu 35 35 45
expect_equal 255 "$curve_pwm" "A curve token with a command substitution fails hot."

forget_state
cpu_curve="30:76,70:200"
cpu_hysteresis='x[$(touch '"$marker"')]'
fcp_curve_pwm cpu 35 35 45
expect_equal 255 "$curve_pwm" "A hysteresis with a command substitution fails hot."

forget_state
unset cpu_hysteresis
cpu_emergency_temp='a[$(touch '"$marker"')]'
fcp_curve_pwm cpu 35 35 45
expect_equal 255 "$curve_pwm" "An emergency threshold with a command substitution fails hot."

forget_state
cpu_emergency_temp=60
cpu_emergency_pwm='b[$(touch '"$marker"')]'
fcp_curve_pwm cpu 35 35 45
expect_equal 255 "$curve_pwm" "An emergency PWM with a command substitution fails hot."

clear_rule cpu
forget_state
fcp_curve_pwm cpu 35 'x[$(touch '"$marker"')]' 45
expect_equal 255 "$curve_pwm" "A legacy bound with a command substitution fails hot."

expect_equal "absent" "$([[ -e "$marker" ]] && echo present || echo absent)" \
  "No curve field may run a command."
rm -rf "$marker_dir"

# ===== Disk groups: the winning curve decides =====
# Group 0 is stepped and reads 37; group 1 is linear and reads 45.
disk_group_max_temp() {
  case "$1" in
    array) echo 37 ;;
    pool) echo 45 ;;
  esac
}

pwm=102
max=255
disk_group_count=2
disk_group_0_name="Array"
disk_group_0_disks="array"
disk_group_0_low=35
disk_group_0_high=45
disk_group_0_curve="35:100,40:150,45:255"
disk_group_0_curve_mode=stepped
disk_group_1_name="Pool"
disk_group_1_disks="pool"
disk_group_1_low=30
disk_group_1_high=50
disk_group_1_curve="30:76,50:255"
disk_group_1_curve_mode=linear
disks="array,pool"

forget_state
sample_disk_temps
calculate_disk_pwm
expect_equal 210 "$disk_pwm_val" "The linear group's curve at 45 wins over the stepped group at 37."
expect_equal 45 "$disk_max" "The winning group's reading is retained."
expect_equal "Pool" "$disk_group_name" "The winning group's name is retained."
expect_equal 1 "$disk_group_index" "The winning group's index is retained."
expect_equal "disk:0|37|100 disk:1|45|210" "${disk_curve_readings[*]}" "Every group's curve point is retained."

# An emergency on group 0 wins, and is not capped.
disk_group_0_emergency_temp=37
forget_state
sample_disk_temps
calculate_disk_pwm
expect_equal 255 "$disk_pwm_val" "A group's emergency wins the arbitration."
expect_equal "Array" "$disk_group_name" "The emergency group is the winner."
expect_equal "disk:0|37|255 disk:1|45|210" "${disk_curve_readings[*]}" "The emergency PWM is reported for its group."
unset disk_group_0_emergency_temp

# A manual run can activate emergency cooling between loop ticks. The next
# process must retain that latch, not overwrite it with its own old snapshot.
state_dir=$(mktemp -d)
if declare -F fcp_curve_begin >/dev/null; then
  for temperature in 60 58; do
    (
      source "$root/src/usr/local/emhttp/plugins/fanctrlplus2/scripts/curve_control.sh"
      cpu_curve="30:76,70:200" cpu_curve_mode=linear cpu_hysteresis=5
      cpu_emergency_temp=60 cpu_emergency_pwm=255 max=200
      fcp_curve_begin "$state_dir/state"
      fcp_curve_pwm cpu "$temperature" 30 70
      fcp_curve_end "$state_dir/state"
      printf '%s\n' "$curve_pwm" > "$state_dir/result"
    )
  done
  expect_equal 255 "$(cat "$state_dir/result")" "A second process must retain an emergency activated by the first."
  fcp_curve_begin "$state_dir/state"
  if flock -n "$state_dir/state.lock" true; then competing=acquired; else competing=blocked; fi
  expect_equal blocked "$competing" "An overlapping writer must not acquire a held curve-state lock."
  fcp_curve_end "$state_dir/state"
  if flock -n "$state_dir/state.lock" true; then competing=acquired; else competing=blocked; fi
  expect_equal acquired "$competing" "The transaction must release its lock after publishing."
else
  expect_equal present missing "Loop and manual Run need a serialized shared curve-state transaction."
fi
rm -rf "$state_dir"

# ===== A merge sees an output another run left unknown =====
# This process applied 255 but could not publish it. A Run Now then saved its
# state with the output pending, set the fan, and could not record the result.
# The merge must not keep this process's 255: the fan may be elsewhere.
state_dir=$(mktemp -d)
state="$state_dir/state"
forget_state
fcp_curve_output=255
fcp_curve_synced_output=255
fcp_curve_unpublished=1
printf 'cpu|x|60|1|1\n' > "$state"
fcp_curve_begin "$state"
expect_equal -1 "$fcp_curve_output" "A merged snapshot with no recorded output leaves the output unknown."
fcp_curve_end "$state" -1
fcp_curve_unpublished=1
printf '@output|255||\ncpu|x|60|1|1\n' > "$state"
fcp_curve_output=255
fcp_curve_synced_output=255
fcp_curve_begin "$state"
expect_equal 255 "$fcp_curve_output" "An unchanged recorded output is kept through a merge."
fcp_curve_end "$state" 255
rm -rf "$state_dir"

if (( failures > 0 )); then
  exit 1
fi

echo "curve control tests passed"
