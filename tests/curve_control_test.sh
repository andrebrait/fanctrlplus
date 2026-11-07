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
calculate_disk_pwm
expect_equal 210 "$disk_pwm_val" "The linear group's curve at 45 wins over the stepped group at 37."
expect_equal 45 "$disk_max" "The winning group's reading is retained."
expect_equal "Pool" "$disk_group_name" "The winning group's name is retained."
expect_equal 1 "$disk_group_index" "The winning group's index is retained."
expect_equal "disk:0|37|100 disk:1|45|210" "${disk_curve_readings[*]}" "Every group's curve point is retained."

# An emergency on group 0 wins, and is not capped.
disk_group_0_emergency_temp=37
forget_state
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

if (( failures > 0 )); then
  exit 1
fi

echo "curve control tests passed"
