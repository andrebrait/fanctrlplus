#!/bin/bash
# Execute the real loop against file-backed sensor/controller fixtures, not hwmon.
set -eu
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
custom="curve_test_$$"
cleanup() {
  rm -rf "$tmp" "/var/tmp/fanctrlplus2/faults_$custom"
  rm -f "/var/tmp/fanctrlplus2/curve_state_fanctrlplus2_$custom" "/var/tmp/fanctrlplus2/curve_state_fanctrlplus2_$custom".tmp.* "/var/tmp/fanctrlplus2/curve_state_fanctrlplus2_$custom.lock" "/var/tmp/fanctrlplus2/curves_fanctrlplus2_$custom" "/var/tmp/fanctrlplus2/temp_fanctrlplus2_$custom" "/var/tmp/fanctrlplus2/history_fanctrlplus2_$custom"
}
trap cleanup EXIT
export FCP_TEST_ROOT="$root/src/usr/local/emhttp/plugins/fanctrlplus2" FCP_TEST_TMP="$tmp"
cat > "$tmp/env.sh" <<'SH'
source() {
  case "$1" in
    /usr/local/emhttp/plugins/fanctrlplus2/*) builtin source "$FCP_TEST_ROOT/${1#/usr/local/emhttp/plugins/fanctrlplus2/}" ;;
    *) builtin source "$1" ;;
  esac
}
logger() { :; }
flock() {
  if [[ "${FCP_TEST_LOCK_FAIL:-0}" == 1 && "$1" == -x ]]; then return 1; fi
  command flock "$@"
}
sleep() {
  [[ "$1" == 4 ]] && return 0
  cat "$FCP_TEST_TMP/pwm1" >> "$FCP_TEST_TMP/output"
  ticks=$((${ticks:-0} + 1))
  (( ticks == 2 )) && exit 0
  if [[ "${FCP_TEST_MANUAL:-0}" == 1 ]]; then
    (
      # A separate controller writer observes a transient hot reading.
      builtin source "$FCP_TEST_ROOT/scripts/curve_control.sh"
      fcp_curve_begin "$curve_state_file"
      fcp_curve_pwm cpu 40 30 40
      echo "$curve_pwm" > "$controller"
      fcp_curve_end "$curve_state_file" "$curve_pwm"
    )
    printf '35000\n' > "$FCP_TEST_TMP/temp1_input"
  else
    printf '40000\n' > "$FCP_TEST_TMP/temp1_input"
  fi
}
SH
run_case() {
  local emergency="$1" expected="$2" cap="$3"
  export FCP_TEST_MANUAL="${4:-0}"
  export FCP_TEST_LOCK_FAIL="${5:-0}"
  printf '35000\n' > "$tmp/temp1_input"
  printf '0\n' > "$tmp/pwm1"
  : > "$tmp/output"
  cat > "$tmp/fan.cfg" <<CFG
custom="$custom"
controller="$tmp/pwm1"
pwm="100"
max="$cap"
idle="0"
interval_sec="7"
notify="0"
syslog="0"
cpu_enable="1"
cpu_sensor="$tmp/temp1_input"
cpu_min_temp="30"
cpu_max_temp="40"
aux_enable="0"
disks=""
CFG
  if [[ "${6:-0}" != 1 ]]; then
    cat >> "$tmp/fan.cfg" <<CFG
cpu_curve="30:100,40:103"
cpu_curve_mode="stepped"
cpu_hysteresis="0"
cpu_emergency_temp="$emergency"
cpu_emergency_pwm="104"
CFG
  fi
  if ! BASH_ENV="$tmp/env.sh" bash "$FCP_TEST_ROOT/scripts/fanctrlplus2_loop.sh" "$tmp/fan.cfg"; then
    printf 'Fan control must continue when the RAM state lock is unavailable.\n' >&2
    return 1
  fi
  local actual
  actual=$(cat "$tmp/output")
  if [[ "$actual" != "$expected" ]]; then
    printf 'Actual loop PWM writes must follow each point/emergency exactly.\nExpected: %s\nActual: %s\n' "$expected" "$actual" >&2
    return 1
  fi
}
run_case '' $'100\n103' 255
run_case 40 $'100\n104' 100
run_case 40 $'100\n100' 100 1
run_case '' $'100\n103' 255 0 1
run_case '' $'101\n101' 103 0 0 1

# ===== Scripted rounds: failures between rounds =====
# Each line of $tmp/steps is one round, "RAW CONTROLLER SNAPSHOT": the CPU
# sensor's content; whether the controller takes writes (ok) or refuses them
# (fail: its path is a directory, so the kernel rejects the write); and whether
# the curve-state snapshot can be written (ok) or runtime storage is full (fail:
# its temporary file is /dev/full, so the kernel answers ENOSPC). Between rounds
# the loop's sleep records what the controller holds, then sets up the next one.
cat > "$tmp/step.sh" <<'SH'
#!/bin/bash
read -r raw ctl pub < <(sed -n "${1}p" "$FCP_TEST_STEPS")
[[ -n "$raw" ]] || exit 1
[[ -f "$FCP_TEST_TMP/pwm1" ]] && cp "$FCP_TEST_TMP/pwm1" "$FCP_TEST_TMP/pwm1.last"
rm -rf "$FCP_TEST_TMP/pwm1"
if [[ "$ctl" == fail ]]; then
  mkdir "$FCP_TEST_TMP/pwm1"
else
  cp "$FCP_TEST_TMP/pwm1.last" "$FCP_TEST_TMP/pwm1"
fi
printf '%s\n' "$raw" > "$FCP_TEST_TMP/temp1_input"
printf '%s\n' "$pub"
SH
chmod +x "$tmp/step.sh"
cat > "$tmp/steps_env.sh" <<'SH'
source() {
  case "$1" in
    /usr/local/emhttp/plugins/fanctrlplus2/*) builtin source "$FCP_TEST_ROOT/${1#/usr/local/emhttp/plugins/fanctrlplus2/}" ;;
    *) builtin source "$1" ;;
  esac
}
logger() { :; }
flock() {
  command flock "$@" || return
  if [[ "$1" == -x && "${FCP_TEST_PUBLISH:-ok}" == fail ]]; then
    ln -sf /dev/full "${curve_state_file}.tmp.$$"
  fi
}
sleep() {
  [[ "$1" == 4 ]] && return 0
  if [[ -d "$FCP_TEST_TMP/pwm1" ]]; then echo fail >> "$FCP_TEST_TMP/output"; else cat "$FCP_TEST_TMP/pwm1" >> "$FCP_TEST_TMP/output"; fi
  ticks=$((${ticks:-0} + 1))
  FCP_TEST_PUBLISH=$("$FCP_TEST_TMP/step.sh" $((ticks + 1))) || exit 0
}
SH
run_steps() {
  local why="$1" expected="$2" rule="$3" actual
  rm -f "/var/tmp/fanctrlplus2/curve_state_fanctrlplus2_$custom" "/var/tmp/fanctrlplus2/curve_state_fanctrlplus2_$custom".tmp.*
  : > "$tmp/output"
  rm -rf "$tmp/pwm1"
  printf '0\n' > "$tmp/pwm1"
  export FCP_TEST_STEPS="$tmp/steps"
  FCP_TEST_PUBLISH=$("$tmp/step.sh" 1)
  export FCP_TEST_PUBLISH
  cat > "$tmp/fan.cfg" <<CFG
custom="$custom"
controller="$tmp/pwm1"
pwm="100"
max="255"
idle="0"
interval_sec="7"
notify="0"
syslog="0"
cpu_enable="1"
cpu_sensor="$tmp/temp1_input"
cpu_min_temp="30"
cpu_max_temp="70"
aux_enable="0"
disks=""
$rule
CFG
  # The refused writes print their kernel errors; they only matter on failure.
  if ! BASH_ENV="$tmp/steps_env.sh" bash "$FCP_TEST_ROOT/scripts/fanctrlplus2_loop.sh" "$tmp/fan.cfg" 2>"$tmp/loop.err"; then
    printf '%s\nThe loop must run on through the failures.\n' "$why" >&2
    cat "$tmp/loop.err" >&2
    return 1
  fi
  actual=$(cat "$tmp/output")
  if [[ "$actual" != "$expected" ]]; then
    printf '%s\nExpected: %s\nActual: %s\n' "$why" "$expected" "$actual" >&2
    cat "$tmp/loop.err" >&2
    return 1
  fi
}
rule_latch='cpu_curve="30:76,70:200"
cpu_curve_mode="linear"
cpu_hysteresis="5"
cpu_emergency_temp="60"
cpu_emergency_pwm="255"'
rule_retry='cpu_curve="30:100,40:103"
cpu_curve_mode="stepped"
cpu_hysteresis="0"
cpu_emergency_temp="40"
cpu_emergency_pwm="104"'

# Activation at 60 with hysteresis 5, a full snapshot store at that moment, then
# recovery to 58: the emergency must hold until a valid 55 releases it.
printf '50000 ok ok\n60000 ok fail\n58000 ok ok\n55000 ok ok\n' > "$tmp/steps"
run_steps "A snapshot that cannot be written must not let the next round reload an older, unlatched one." \
  $'138\n255\n255\n169' "$rule_latch"

# A CPU input that reads 0, or nothing numeric, is a gap: the documented output
# for the missing round (the curve at 0 °C), and the latch survives it.
printf '60000 ok ok\n0 ok ok\n58000 ok ok\n55000 ok ok\n' > "$tmp/steps"
run_steps "A CPU reading of 0 must not clear the emergency latch." $'255\n76\n255\n169' "$rule_latch"
printf '60000 ok ok\nn/a ok ok\n58000 ok ok\n55000 ok ok\n' > "$tmp/steps"
run_steps "A CPU reading that is not a number must not clear the emergency latch." $'255\n76\n255\n169' "$rule_latch"

# A write the controller refuses is not applied output: the next round, still
# in emergency, writes again, rather than believing the fan already runs at 104.
printf '35000 ok ok\n40000 fail ok\n40000 ok ok\n' > "$tmp/steps"
run_steps "A failed controller write must be retried, not recorded as applied." $'100\nfail\n104' "$rule_retry"
printf '35000 fail ok\n35000 ok ok\n' > "$tmp/steps"
run_steps "A failed first write must leave the output unknown, so the next round writes." $'fail\n100' "$rule_retry"
printf 'curve loop tests passed\n'
