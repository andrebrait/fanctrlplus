#!/bin/bash
# Exercise the real loop with a controller whose PWM readback stays low.
set -eu
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
name="response_test_$$"
cleanup() {
  rm -rf "$tmp" "/var/tmp/fanctrlplus2/faults_$name"
  rm -f "/var/tmp/fanctrlplus2/curve_state_fanctrlplus2_$name"* "/var/tmp/fanctrlplus2/curves_fanctrlplus2_$name" "/var/tmp/fanctrlplus2/temp_fanctrlplus2_$name" "/var/tmp/fanctrlplus2/history_fanctrlplus2_$name"
}
trap cleanup EXIT
export FCP_TEST_ROOT="$root/src/usr/local/emhttp/plugins/fanctrlplus2" FCP_TEST_TMP="$tmp"
cat > "$tmp/env.sh" <<'SH'
source() {
  case "$1" in
    /usr/local/emhttp/plugins/fanctrlplus2/*) builtin source "$FCP_TEST_ROOT/${1#/usr/local/emhttp/plugins/fanctrlplus2/}" ;;
    *) builtin source "$1" ;;
  esac
  if [[ "$1" == */disk_group_control.sh ]]; then
    fcp_write_pwm() { printf '%s\n' "$1" >> "$FCP_TEST_TMP/writes"; echo 0 > "$controller"; }
  fi
}
logger() { :; }
sleep() {
  if [[ "$1" == 4 ]]; then
    (( ${ticks:-0} == 1 )) && echo 3300 > "$FCP_TEST_TMP/fan1_input"
    return 0
  fi
  ticks=$((${ticks:-0} + 1))
  if (( ticks == 1 )); then
    SECONDS=40
    echo 40000 > "$FCP_TEST_TMP/temp1_input"
  elif (( ticks == 2 )); then
    SECONDS=80
  else
    exit 0
  fi
}
SH
cat > "$tmp/notify" <<SH
#!/bin/bash
printf '%s\n' "\$@" >> "$tmp/notifications"
SH
chmod +x "$tmp/notify"
cat > "$tmp/fan.cfg" <<CFG
custom="$name"
controller="$tmp/pwm1"
pwm="77"
max="255"
idle="77"
interval_sec="30"
notify="1"
syslog="0"
cpu_enable="1"
cpu_sensor="$tmp/temp1_input"
cpu_min_temp="30"
cpu_max_temp="40"
cpu_curve="30:77,40:255"
cpu_curve_mode="linear"
cpu_hysteresis="0"
aux_enable="0"
disks=""
CFG
echo 30000 > "$tmp/temp1_input"
echo 77 > "$tmp/pwm1"
echo 1000 > "$tmp/fan1_input"
export fcp_notify_bin="$tmp/notify"
BASH_ENV="$tmp/env.sh" bash "$FCP_TEST_ROOT/scripts/fanctrlplus2_loop.sh" "$tmp/fan.cfg"
grep -q 'RPM changed from 1000 to 3300' "$tmp/notifications" || { echo 'The real loop did not report the responding fan.' >&2; exit 1; }
[[ $(grep -c '^warning$' "$tmp/notifications") == 1 ]] || { echo 'The changing target caused an extra warning.' >&2; exit 1; }
echo 'fan response loop test passed'
