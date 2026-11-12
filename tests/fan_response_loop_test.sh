#!/bin/bash
# Exercise the real loop with a controller whose PWM readback ignores a change.
set -eu
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
name="response_test_$$"
state() {
  rm -rf "/var/tmp/fanctrlplus2/faults_$name"
  rm -f "/var/tmp/fanctrlplus2/curve_state_fanctrlplus2_$name"* "/var/tmp/fanctrlplus2/curves_fanctrlplus2_$name" "/var/tmp/fanctrlplus2/temp_fanctrlplus2_$name" "/var/tmp/fanctrlplus2/history_fanctrlplus2_$name"
}
trap 'rm -rf "$tmp"; state' EXIT
export FCP_TEST_ROOT="$root/src/usr/local/emhttp/plugins/fanctrlplus2" FCP_TEST_TMP="$tmp"
cat > "$tmp/env.sh" <<'SH'
source() {
  case "$1" in
    /usr/local/emhttp/plugins/fanctrlplus2/*) builtin source "$FCP_TEST_ROOT/${1#/usr/local/emhttp/plugins/fanctrlplus2/}" ;;
    *) builtin source "$1" ;;
  esac
  if [[ "$1" == */disk_group_control.sh ]]; then
    # The first write lands; the chip ignores later ones. With an enable file it
    # behaves like nct6775-core, which reports manual output at 255 as mode 0.
    fcp_write_pwm() {
      printf '%s\n' "$1" >> "$FCP_TEST_TMP/writes"
      if [[ -f "$controller_enable" ]]; then
        [[ "$1" == 255 ]] && echo 0 > "$controller_enable" || echo 1 > "$controller_enable"
      fi
      [[ $(wc -l < "$FCP_TEST_TMP/writes") == 1 ]] && echo "$1" > "$controller" || true
    }
  fi
}
logger() { :; }
sleep() {
  if [[ "$1" == 4 ]]; then
    (( ${ticks:-0} == 1 )) && echo "$FCP_TEST_RPM2" > "$FCP_TEST_TMP/fan1_input"
    return 0
  fi
  ticks=$((${ticks:-0} + 1))
  if (( ticks == 1 )); then
    SECONDS=40
    echo "$FCP_TEST_TEMP2" > "$FCP_TEST_TMP/temp1_input"
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
export fcp_notify_bin="$tmp/notify"

# run temp1 temp2 rpm1 rpm2 enable ('' for none): the loop starts at temp1 and
# rpm1, and after its first write sees temp2 and, once settled, rpm2.
run() {
  state
  rm -f "$tmp/notifications" "$tmp/writes" "$tmp/pwm1_enable"
  echo "$1" > "$tmp/temp1_input"
  echo 0 > "$tmp/pwm1"
  echo "$3" > "$tmp/fan1_input"
  [[ -z "$5" ]] || echo "$5" > "$tmp/pwm1_enable"
  FCP_TEST_TEMP2=$2 FCP_TEST_RPM2=$4 BASH_ENV="$tmp/env.sh" bash "$FCP_TEST_ROOT/scripts/fanctrlplus2_loop.sh" "$tmp/fan.cfg"
}

run 30000 40000 1000 3300 ''
grep -q 'RPM changed from 1000 to 3300' "$tmp/notifications" || { echo 'The real loop did not report the responding fan.' >&2; exit 1; }
[[ $(grep -c '^warning$' "$tmp/notifications") == 1 ]] || { echo 'The changing target caused an extra warning.' >&2; exit 1; }

run 40000 30000 3300 1000 1
grep -q 'RPM changed from 3300 to 1000' "$tmp/notifications" || { echo 'Leaving full speed reported as mode 0 lost the RPM baseline.' >&2; exit 1; }
echo 'fan response loop test passed'
