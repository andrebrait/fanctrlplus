#!/bin/bash
# rc.fanctrlplus2 start at boot (#41): waits for emhttpd, which Unraid starts
# once every plugin has installed, then gives a missing controller a grace
# period before warning. Runs a copy of the rc script with its absolute paths
# pointed into a temporary directory, and stubs for pgrep -x emhttpd, php and
# logger.

set -u

root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
cleanup() {
  pkill -f "$tmp/" 2>/dev/null
  rm -rf "$tmp"
}
trap cleanup EXIT

scripts="$root/src/usr/local/emhttp/plugins/fanctrlplus2/scripts"
mkdir -p "$tmp/cfg" "$tmp/run" "$tmp/hw" "$tmp/bin" "$tmp/var"

printf '#!/bin/bash\nexec sleep 300\n' > "$tmp/loop.sh"
printf '#!/bin/bash\nprintf "%%s\\n" "$*" >> %q\n' "$tmp/notify.log" > "$tmp/notify"
printf '#!/bin/bash\nexit 0\n' > "$tmp/bin/php"
printf '#!/bin/bash\nexit 0\n' > "$tmp/bin/logger"
cat > "$tmp/bin/pgrep" <<EOF
#!/bin/bash
if [[ "\$1" == -x && "\$2" == emhttpd ]]; then [[ -e $tmp/emhttpd_up ]]; exit; fi
exec /usr/bin/pgrep "\$@"
EOF
chmod +x "$tmp/loop.sh" "$tmp/notify" "$tmp/bin/"*
export PATH="$tmp/bin:$PATH" fcp_notify_bin="$tmp/notify" fcp_bios_mode_dir="$tmp/modes"

rc="$tmp/rc.fanctrlplus2"
sed -e "s#^cfg_path=.*#cfg_path=$tmp/cfg#" \
    -e "s#^loop_script=.*#loop_script=$tmp/loop.sh#" \
    -e "s#^dashboard_updater=.*#dashboard_updater=$tmp/none#" \
    -e "s#^pid_dir=.*#pid_dir=$tmp/run#" \
    -e "s#^rc_script=.*#rc_script=$rc#" \
    -e "s#^config_lock=.*#config_lock=$tmp/run/lock#" \
    -e "s#^boot_wait_seconds=.*#boot_wait_seconds=3#" \
    -e "s#^boot_retry_seconds=.*#boot_retry_seconds=1#" \
    -e "s#^hwmon_dir=.*#hwmon_dir=$tmp/hwmon#" \
    -e "s#^user_stopped=.*#user_stopped=$tmp/run/user_stopped#" \
    -e "s#^source .*#source $scripts/disk_group_control.sh#" \
    -e "s#^  script=.*#  script=$tmp/none#" \
    -e "s#/var/tmp/\$plugin#$tmp/var#g" \
    "$scripts/rc.fanctrlplus2" > "$rc"
chmod +x "$rc"

failures=0
expect_equal() {
  local expected="$1" actual="$2" message="$3"
  [[ "$expected" == "$actual" ]] && return
  printf '%s\nExpected: %s\nActual: %s\n' "$message" "$expected" "$actual" >&2
  failures=$((failures + 1))
}

fan() {
  printf 'service="1"\ncustom="%s"\ncontroller="%s"\n' "$1" "$tmp/hw/$2" > "$tmp/cfg/fanctrlplus2_$1.cfg"
}
fan A pwm1
fan B pwm2

reset() {
  "$rc" stop >/dev/null 2>&1
  pkill -f "$tmp/loop.sh" 2>/dev/null
  rm -rf "$tmp/run/"* "$tmp/var/"* "$tmp/notify.log" "$tmp/emhttpd_up" "$tmp/hw/"* "$tmp/hwmon"
  mkdir -p "$tmp/hwmon/hwmon1"
  touch "$tmp/hw/pwm1"
}
running() { ls "$tmp/run" | grep -o '^fanctrlplus2_[A-Z]' | cut -d_ -f2 | xargs; }
warnings() { grep -c 'has lost its PWM controller' "$tmp/notify.log" 2>/dev/null || echo 0; }
# Waits up to 10 seconds for running() to print $1.
wait_running() { local i; for i in {1..50}; do [[ "$(running)" == "$1" ]] && return; sleep 0.2; done; }

# ===== Outside boot: start does not fail for a missing controller =====
reset
touch "$tmp/emhttpd_up"
"$rc" start >/dev/null 2>&1
expect_equal 0 $? "A missing controller is warned about, not a failed start: a restore would roll back."
expect_equal A "$(running)" "The fan with a controller starts."
expect_equal 1 "$(warnings)" "Outside boot, a missing controller warns at once."

# ===== A /sys/class controller names an index, not a device =====
reset
touch "$tmp/emhttpd_up"
mkdir -p "$tmp/class/hwmon/hwmon3"
touch "$tmp/class/hwmon/hwmon3/pwm2"
fan B ../class/hwmon/hwmon3/pwm2
"$rc" start >/dev/null 2>&1
expect_equal A "$(running)" "A fan saved by its hwmon number is not started until its controller is picked again."
expect_equal 1 "$(warnings)" "The fan saved by its hwmon number is warned about."
echo 2 > "$tmp/class/hwmon/hwmon3/pwm2_enable"
echo 77 > "$tmp/class/hwmon/hwmon3/pwm2"
touch "$tmp/run/fanctrlplus2_B.pid"  # as if B ran before an upgrade kept its class path
"$rc" stop >/dev/null 2>&1
expect_equal '2 77' "$(cat "$tmp/class/hwmon/hwmon3/pwm2_enable") $(cat "$tmp/class/hwmon/hwmon3/pwm2")" "Stop must not set 50% on a hwmon number that may belong to another chip."
reset
"$rc" start >/dev/null 2>&1
touch "$tmp/emhttpd_up"
wait_running A
# B is warned about right after A starts, in the same pass.
for _ in {1..25}; do [[ "$(warnings)" == 1 ]] && break; sleep 0.2; done
expect_equal 1 "$(warnings)" "At boot, a fan saved by its hwmon number warns at once: no driver will fix it."
expect_equal 1 "$(grep -c 'saved by its hwmon number' "$tmp/notify.log")" "The warning says to select the controller again."
for _ in {1..50}; do pgrep -f "$rc boot\$" >/dev/null || break; sleep 0.2; done
expect_equal 0 "$(pgrep -fc "$rc boot\$")" "A fan saved by its hwmon number does not keep the boot start retrying."
fan B pwm2

# ===== Boot, the driver loads late =====
reset
"$rc" start >/dev/null 2>&1
sleep 1
expect_equal '' "$(running)" "Before emhttpd runs, plugins are still installing and nothing starts."
touch "$tmp/emhttpd_up"
wait_running A
expect_equal A "$(running)" "Once emhttpd runs, the fan with a controller starts."
pid_a=$(cat "$tmp/run/fanctrlplus2_A.pid")
touch "$tmp/hw/pwm2"
sleep 2
expect_equal A "$(running)" "Without a new chip in the hwmon list, the boot start does not retry."
mkdir "$tmp/hwmon/hwmon2"
touch "$tmp/hwmon/hwmon2/pwm2"
wait_running 'A B'
expect_equal 'A B' "$(running)" "A controller that appears within the grace period starts its fan."
expect_equal "$pid_a" "$(cat "$tmp/run/fanctrlplus2_A.pid")" "The retry leaves running fans alone."
expect_equal 0 "$(warnings)" "A driver that loads within the grace period warns nothing (#41)."

# ===== Boot, a late driver whose channels sit on the parent device =====
# A long grace period, so a start before it ends comes from the retry.
rc_long="$tmp/rc_long.fanctrlplus2"
sed -e "s#^boot_wait_seconds=.*#boot_wait_seconds=30#" -e "s#^rc_script=.*#rc_script=$rc_long#" \
    "$rc" > "$rc_long"
chmod +x "$rc_long"
reset
"$rc_long" start >/dev/null 2>&1
touch "$tmp/emhttpd_up"
wait_running A
touch "$tmp/hw/pwm2"
mkdir -p "$tmp/hwmon/hwmon2/device"
touch "$tmp/hwmon/hwmon2/device/pwm2"
wait_running 'A B'
expect_equal 'A B' "$(running)" "A channel appearing on the parent device triggers a retry within the grace period."
pkill -f "$rc_long boot\$"

# ===== Boot, the driver never loads =====
reset
"$rc" start >/dev/null 2>&1
touch "$tmp/emhttpd_up"
wait_running A
expect_equal 0 "$(warnings)" "No warning during the grace period."
sleep 5
expect_equal 1 "$(warnings)" "A controller still missing after the grace period warns once."

# ===== Boot, emhttpd never starts =====
reset
"$rc" start >/dev/null 2>&1
wait_running A
expect_equal A "$(running)" "Without emhttpd, fans start once the wait runs out."

# ===== A stop during the boot wait =====
reset
"$rc" start >/dev/null 2>&1
"$rc" start >/dev/null 2>&1
expect_equal 1 "$(pgrep -fc "$rc boot\$")" "A second start during the boot wait does not add another."
"$rc" stop >/dev/null 2>&1
touch "$tmp/emhttpd_up"
sleep 3
expect_equal '' "$(running)" "A stop during the boot wait is not undone."
expect_equal 0 "$(pgrep -fc "$rc boot\$")" "The stop ends the boot start."

# ===== A stop after fan control ran, even without emhttpd =====
reset
"$rc" start >/dev/null 2>&1
wait_running A
echo 2 > "$tmp/hw/pwm1_enable"
"$rc" stop >/dev/null 2>&1
expect_equal '1 128' "$(cat "$tmp/hw/pwm1_enable") $(cat "$tmp/hw/pwm1")" "Once fan control ran, stop sets 50% even when emhttpd never started."

# ===== Stop sets 50% only for fans whose loop ran =====
reset
"$rc" start >/dev/null 2>&1
touch "$tmp/emhttpd_up"
wait_running A
pkill -f "$rc boot\$"  # B's retry has not run yet
touch "$tmp/hw/pwm2"
echo 2 > "$tmp/hw/pwm2_enable"
echo 77 > "$tmp/hw/pwm2"
"$rc" stop >/dev/null 2>&1
expect_equal '2 77' "$(cat "$tmp/hw/pwm2_enable") $(cat "$tmp/hw/pwm2")" "A fan whose loop never ran keeps its BIOS control when another fan's loop stops."

# ===== The boot-time stop leaves the BIOS in control =====
reset
echo 2 > "$tmp/hw/pwm1_enable"
echo 77 > "$tmp/hw/pwm1"
"$rc" stop >/dev/null 2>&1
expect_equal '2 77' "$(cat "$tmp/hw/pwm1_enable") $(cat "$tmp/hw/pwm1")" "Before emhttpd, stop does not set the fans to 50%."

# ===== Start gives back a controller no Enabled fan drives (Monitor only) =====
reset
touch "$tmp/emhttpd_up"
echo 1 > "$tmp/hw/pwm1_enable"
mkdir -p "$tmp/modes"
printf '5\n%s\n' "$tmp/hw/pwm1" > "$tmp/modes/pwm1"
printf 'service="2"\ncustom="A"\ncontroller="%s"\n' "$tmp/hw/pwm1" > "$tmp/cfg/fanctrlplus2_A.cfg"
"$rc" start >/dev/null 2>&1
expect_equal '5 ' "$(cat "$tmp/hw/pwm1_enable") $(running)" "Start must give a Monitor only fan's controller back and start no loop for it."
fan A pwm1

# ===== Stop keeps the mode of a controller it takes first (a fan moved to it) =====
reset
touch "$tmp/emhttpd_up"
rm -rf "$tmp/modes"
echo 5 > "$tmp/hw/pwm1_enable"
touch "$tmp/run/fanctrlplus2_A.pid"
"$rc" stop >/dev/null 2>&1
expect_equal '1 5' "$(cat "$tmp/hw/pwm1_enable") $(head -n1 "$tmp/modes/"* 2>/dev/null)" "Stop must keep the mode of a controller it sets to 50% first."

if (( failures )); then
  printf '%d rc boot test(s) failed\n' "$failures" >&2
  exit 1
fi
echo "rc boot tests passed"
