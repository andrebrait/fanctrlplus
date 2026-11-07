#!/bin/bash
# Execute the real loop against file-backed sensor/controller fixtures, not hwmon.
set -eu
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
custom="curve_test_$$"
cleanup() {
  rm -rf "$tmp" "/var/tmp/fanctrlplus2/faults_$custom"
  rm -f "/var/tmp/fanctrlplus2/curve_state_fanctrlplus2_$custom" "/var/tmp/fanctrlplus2/curve_state_fanctrlplus2_$custom.lock" "/var/tmp/fanctrlplus2/curves_fanctrlplus2_$custom" "/var/tmp/fanctrlplus2/temp_fanctrlplus2_$custom" "/var/tmp/fanctrlplus2/history_fanctrlplus2_$custom"
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
printf 'curve loop tests passed\n'
