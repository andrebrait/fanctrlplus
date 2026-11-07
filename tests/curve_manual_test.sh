#!/bin/bash
# Execute the real manual run (Run Now) against file-backed sensor and controller
# fixtures: its exit status, the controller, and the curve state it shares with
# the fan loop. Failures are real: a controller that is a directory refuses
# every write, a state lock held by another process or sitting on a directory
# cannot be taken, a snapshot whose temporary file is /dev/full gets ENOSPC, and
# the stalled drive is a process that never answers.
set -u
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
custom="manual_test_$$"
runtime=/var/tmp/fanctrlplus2
state="$runtime/curve_state_fanctrlplus2_$custom"
background=()
cleanup() {
  local pid
  for pid in "${background[@]}"; do kill "$pid" 2>/dev/null; done
  [[ -s "$tmp/stalled.pid" ]] && kill "$(cat "$tmp/stalled.pid")" 2>/dev/null
  # Release anything still held at a barrier, so no stub outlives the test.
  if [[ -p "$tmp/release.fifo" ]]; then
    exec 7<>"$tmp/release.fifo" 8<>"$tmp/entered.fifo"
    echo go >&7
    echo go >&8
  fi
  rm -rf "$tmp" "$runtime/faults_$custom" "$state.lock" "$runtime/curves_fanctrlplus2_$custom" \
    "$runtime/temp_fanctrlplus2_$custom" "$runtime/history_fanctrlplus2_$custom"
  rm -f "$state" "$state".tmp.*
}
trap cleanup EXIT
export FCP_TEST_ROOT="$root/src/usr/local/emhttp/plugins/fanctrlplus2" FCP_TEST_TMP="$tmp" FCP_CONFIG_DIR="$tmp/cfg"
mkdir "$tmp/cfg" "$tmp/bin"
# The two ends of a barrier: a stalled drive read says it has started (entered),
# then waits until the test lets it go (release).
mkfifo "$tmp/entered.fifo" "$tmp/release.fifo"

failures=0
expect_equal() {
  local expected="$1" actual="$2" message="$3"
  if [[ "$expected" == "$actual" ]]; then
    return
  fi
  printf '%s\nExpected: %s\nActual: %s\n' "$message" "$expected" "$actual" >&2
  failures=$((failures + 1))
}
# yes/no for whether the last run's error output mentions TEXT.
said() { grep -q -- "$1" "$tmp/run.err" && echo yes || echo no; }

cat > "$tmp/env.sh" <<'SH'
source() {
  case "$1" in
    /usr/local/emhttp/plugins/fanctrlplus2/*) builtin source "$FCP_TEST_ROOT/${1#/usr/local/emhttp/plugins/fanctrlplus2/}" ;;
    *) builtin source "$1" ;;
  esac
  local rc=$?
  # The fixture's drives are plain names, not block devices, so the drive lookup
  # is replaced once the library is loaded; the SMART read it makes is the real one.
  if [[ "$1" == */disk_group_control.sh ]]; then
    disk_group_max_temp() {
      local disk best="" temp
      for disk in ${1//,/ }; do
        temp=$(disk_temp_read "/dev/fixture/$disk")
        [[ "$temp" =~ ^[0-9]+$ ]] && { [[ -z "$best" ]] || (( temp > best )); } && best=$temp
      done
      [[ -n "$best" ]] && echo "$best"
    }
  fi
  return $rc
}
logger() { :; }
sleep() {
  [[ "$1" == 4 ]] && return 0
  # One round of the fan loop: its end-of-round sleep is where it stops.
  [[ "${FCP_TEST_ONE_ROUND:-0}" == 1 ]] && exit 0
  command sleep "$@"
}
flock() {
  command flock "$@" || return
  # A full runtime filesystem: the snapshot's temporary file refuses every write.
  if [[ "$1" == -x && "${FCP_TEST_PUBLISH:-ok}" == fail ]]; then
    ln -sf /dev/full "${curve_state_file}.tmp.$$"
  fi
}
SH
printf '%s\n' 'ID# ATTRIBUTE_NAME          FLAG     VALUE WORST THRESH TYPE      UPDATED  WHEN_FAILED RAW_VALUE' \
  '194 Temperature_Celsius     0x0022   118   100   000    Old_age   Always       -       41' > "$tmp/attrs"
cat > "$tmp/bin/smartctl" <<'SH'
#!/bin/bash
if [[ "${FCP_FAKE_SMART:-ok}" == barrier ]]; then
  # Reached only once the caller has already sampled its other sources.
  echo entered > "$FCP_TEST_TMP/entered.fifo"
  read -r _ < "$FCP_TEST_TMP/release.fifo"
fi
if [[ "${FCP_FAKE_SMART:-ok}" == stall ]]; then
  echo $$ > "$FCP_TEST_TMP/stalled.pid"
  exec sleep 1000
fi
cat "$FCP_TEST_TMP/attrs"
SH
chmod +x "$tmp/bin/smartctl"

write_cfg() {
  cat > "$FCP_CONFIG_DIR/fanctrlplus2_$custom.cfg" <<CFG
custom="$custom"
controller="$tmp/pwm1"
pwm="${cfg_pwm:-100}"
max="255"
cpu_enable="1"
cpu_sensor="$tmp/temp1_input"
cpu_min_temp="30"
cpu_max_temp="70"
aux_enable="0"
$1
CFG
}
# Starts a case with no curve state and the controller holding $1.
fresh() {
  rm -rf "$state" "$state".tmp.* "$state.lock" "$tmp/pwm1"
  printf '%s\n' "$1" > "$tmp/pwm1"
}
# One Run Now with the CPU sensor reading $1. Sets rc to its exit status and
# leaves its error output in $tmp/run.err. The knobs are set by the cases.
lock_wait=10 smart_timeout=10 fake_smart=ok publish=ok
run_manual() {
  printf '%s\n' "$1" > "$tmp/temp1_input"
  rc=0
  env BASH_ENV="$tmp/env.sh" PATH="$tmp/bin:$PATH" FCP_FAKE_SMART="$fake_smart" FCP_TEST_PUBLISH="$publish" \
    fcp_curve_lock_wait="$lock_wait" fcp_smart_timeout="$smart_timeout" \
    timeout 120 bash "$FCP_TEST_ROOT/scripts/fanctrlplus2_refresh_single.sh" "$custom" 2>"$tmp/run.err" || rc=$?
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

# ===== Each run continues the state the last one left =====
# Every run is its own process, so this is the snapshot at work: emergency at 60
# with hysteresis 5 holds through 58 and releases at a valid 55. A CPU input that
# reads 0 or is not a number is a gap with the documented output (the curve at
# 0 °C), and the latch survives it.
write_cfg "$rule_latch"
for gap in 0 n/a; do
  fresh 0
  run_manual 60000; expect_equal "0 255" "$rc $(cat "$tmp/pwm1")" "A hot reading latches the emergency."
  run_manual "$gap"; expect_equal "0 76" "$rc $(cat "$tmp/pwm1")" "A CPU input of '$gap' gives the documented gap output."
  run_manual 58000; expect_equal "0 255" "$rc $(cat "$tmp/pwm1")" "Recovery after a gap of '$gap' resumes the latch."
  run_manual 55000; expect_equal "0 169" "$rc $(cat "$tmp/pwm1")" "A valid cool reading releases the latch."
done

# ===== A refused controller write fails the run and is not applied output =====
write_cfg "$rule_retry"
fresh 0
run_manual 35000
expect_equal "0 100 @output|100||" "$rc $(cat "$tmp/pwm1") $(grep '^@output' "$state")" "A run that writes records its output as applied."
rm -f "$tmp/pwm1"; mkdir "$tmp/pwm1"
run_manual 40000
expect_equal 1 "$rc" "A controller write that fails fails the run."
expect_equal yes "$(said 'Could not write PWM=104')" "The failure names the write that failed."
expect_equal "@output|100||" "$(grep '^@output' "$state")" "The previous applied output stays on record after a failed write."
expect_equal 1 "$(grep '^cpu|' "$state" | cut -d'|' -f4)" "The emergency latch is published, so the loop retries it."
rmdir "$tmp/pwm1"; printf '100\n' > "$tmp/pwm1"
run_manual 40000
expect_equal "0 104 @output|104||" "$rc $(cat "$tmp/pwm1") $(grep '^@output' "$state")" "The next run, with the controller back, applies the emergency."

# With no output on record, a failed write leaves it unknown.
fresh 0
rm -f "$tmp/pwm1"; mkdir "$tmp/pwm1"
run_manual 35000
expect_equal 1 "$rc" "A failed first write fails the run."
expect_equal 0 "$(grep -c '^@output' "$state")" "A failed first write records no applied output."

# ===== A run that cannot coordinate with the loop does not write =====
write_cfg "$rule_latch"
fresh 100
( flock -x 9; echo held > "$tmp/held"; exec sleep 300 ) 9>"$state.lock" &
holder=$!
background+=("$holder")
for _ in $(seq 100); do [[ -e "$tmp/held" ]] && break; sleep 0.1; done
expect_equal present "$([[ -e "$tmp/held" ]] && echo present || echo absent)" "Another process must be holding the state lock."
lock_wait=1
run_manual 60000
expect_equal "1 100" "$rc $(cat "$tmp/pwm1")" "A run that cannot get the state lock fails and leaves the controller alone."
expect_equal yes "$(said 'curve-state lock')" "The failure says it was the lock."
kill "$holder"; { wait "$holder"; } 2>/dev/null
run_manual 60000
expect_equal "0 255" "$rc $(cat "$tmp/pwm1")" "The run succeeds once the lock is free."
lock_wait=10

fresh 100
mkdir "$state.lock"
run_manual 60000
expect_equal "1 100" "$rc $(cat "$tmp/pwm1")" "A lock that cannot even be opened fails the run before any write."
expect_equal yes "$(said 'curve-state lock')" "The failure says it was the lock."
rmdir "$state.lock"

# ===== A snapshot that cannot be saved is reported, though the fan was set =====
fresh 0
publish=fail
run_manual 60000
expect_equal "1 255" "$rc $(cat "$tmp/pwm1")" "A run whose snapshot cannot be saved fails, having set the fan."
expect_equal yes "$(said 'could not be saved')" "The failure says the state was not saved."
expect_equal absent "$([[ -e "$state" ]] && echo present || echo absent)" "Nothing replaced the snapshot."
publish=ok

# ===== A stalled drive does not hold up other sources =====
# Drive group at 41 °C is 193 on its ramp, the CPU at 40 °C is 138.
write_cfg 'disk_group_count="1"
disk_group_0_name="Fixture"
disk_group_0_disks="fixture1"
disk_group_0_low="35"
disk_group_0_high="45"'
fresh 0
printf '40000\n' > "$tmp/temp1_input"
run_manual 40000
expect_equal "0 193" "$rc $(cat "$tmp/pwm1")" "A healthy drive and the CPU both feed the run."

# The fan loop is stuck in a drive read that never answers (its own timeout is
# a minute). A manual Run must not wait for it: the loop does not hold the lock
# while it reads.
fresh 0
env BASH_ENV="$tmp/env.sh" PATH="$tmp/bin:$PATH" FCP_FAKE_SMART=stall fcp_smart_timeout=60 \
  bash "$FCP_TEST_ROOT/scripts/fanctrlplus2_loop.sh" "$FCP_CONFIG_DIR/fanctrlplus2_$custom.cfg" >/dev/null 2>&1 &
loop=$!
background+=("$loop")
for _ in $(seq 100); do [[ -s "$tmp/stalled.pid" ]] && break; sleep 0.1; done
expect_equal present "$([[ -s "$tmp/stalled.pid" ]] && echo present || echo absent)" "The loop must be stuck in its drive read."
lock_wait=3
run_manual 40000
expect_equal "0 193" "$rc $(cat "$tmp/pwm1")" "A manual Run completes with its own readings while the loop is stuck on a drive."
lock_wait=10
kill "$loop"; { wait "$loop"; } 2>/dev/null
kill "$(cat "$tmp/stalled.pid")" 2>/dev/null
rm -f "$tmp/stalled.pid"

# When the manual Run's own drive stalls, the read ends by itself and the CPU
# still drives the fan.
fresh 0
fake_smart=stall smart_timeout=1
run_manual 40000
expect_equal "0 138" "$rc $(cat "$tmp/pwm1")" "A stalled drive leaves the CPU to drive the run."
fake_smart=ok smart_timeout=10

# ===== A sample taken before another run's lock cannot undo that run =====
# Every source is read before the curve-state lock is taken, so a run can hold a
# sample while another run completes. The stalled drive read is a barrier: the
# stub tells the test it has been reached (the CPU is already sampled by then),
# and does not return until the test releases it. No timing is involved: each
# process is either before the barrier or after it, and the test says which.
# Rule: curve 30:76,70:200 linear, hysteresis 5, emergency 60 at PWM 255; the
# drive is held at 0 by its ramp, so the CPU decides: 91 at 35, 122 at 40 after
# a release from 60, 255 while latched.
await_barrier() {
  timeout 30 bash -c 'read -r _ < "$1"' _ "$tmp/entered.fifo" && return 0
  printf 'The held process never reached its drive read.\n' >&2
  failures=$((failures + 1))
  return 1
}
release_barrier() {
  timeout 30 bash -c 'echo go > "$1"' _ "$tmp/release.fifo" && return 0
  printf 'The held drive read was never released.\n' >&2
  failures=$((failures + 1))
  return 1
}
# One round of the real fan loop, in the foreground, with the CPU reading $1.
run_loop_round() {
  printf '%s\n' "$1" > "$tmp/temp1_input"
  loop_rc=0
  env BASH_ENV="$tmp/env.sh" PATH="$tmp/bin:$PATH" FCP_FAKE_SMART="$fake_smart" FCP_TEST_ONE_ROUND=1 \
    fcp_smart_timeout="$smart_timeout" \
    timeout 120 bash "$FCP_TEST_ROOT/scripts/fanctrlplus2_loop.sh" "$FCP_CONFIG_DIR/fanctrlplus2_$custom.cfg" \
    >/dev/null 2>"$tmp/run.err" || loop_rc=$?
}
# Field N of the state line for source $1 (3 effective temperature, 4 latch, 5 time).
state_field() { grep "^$1|" "$state" | cut -d'|' -f"$2"; }

cfg_pwm=0
write_cfg "$rule_latch"$'\n''disk_group_count="1"
disk_group_0_name="Fixture"
disk_group_0_disks="fixture1"
disk_group_0_low="60"
disk_group_0_high="80"'

# The fan loop reads the CPU at 35, then stalls in its drive read. A manual Run
# reads 60 and latches the emergency. Released, the loop evaluates its older 35:
# it must not release the latch, nor lower the output the Run applied.
fresh 0
printf '35000\n' > "$tmp/temp1_input"
env BASH_ENV="$tmp/env.sh" PATH="$tmp/bin:$PATH" FCP_FAKE_SMART=barrier FCP_TEST_ONE_ROUND=1 fcp_smart_timeout=60 \
  bash "$FCP_TEST_ROOT/scripts/fanctrlplus2_loop.sh" "$FCP_CONFIG_DIR/fanctrlplus2_$custom.cfg" >/dev/null 2>"$tmp/bg.err" &
loop=$!
background+=("$loop")
if await_barrier; then
  run_manual 60000
  expect_equal "0 255" "$rc $(cat "$tmp/pwm1")" "The manual Run reads 60 while the loop is held, and latches the emergency."
  if release_barrier; then
    { wait "$loop"; } 2>/dev/null; loop_rc=$?
    expect_equal "0 255" "$loop_rc $(cat "$tmp/pwm1")" "The loop's older 35 must not lower the emergency the manual Run applied."
    expect_equal 1 "$(state_field cpu 4)" "The emergency latch survives the loop's older sample."
    expect_equal "@output|255||" "$(grep '^@output' "$state")" "The applied output stays the emergency."
  fi
fi

# The reverse order. A manual Run reads 60, then stalls in its drive read. The
# fan loop completes a round on a newer, cool reading of 35 and publishes it.
# Released, the Run applies its older 60 conservatively; a valid newer cool
# reading afterwards releases that emergency normally.
fresh 0
printf '60000\n' > "$tmp/temp1_input"
env BASH_ENV="$tmp/env.sh" PATH="$tmp/bin:$PATH" FCP_FAKE_SMART=barrier FCP_TEST_PUBLISH=ok \
  fcp_curve_lock_wait=10 fcp_smart_timeout=60 \
  bash "$FCP_TEST_ROOT/scripts/fanctrlplus2_refresh_single.sh" "$custom" >/dev/null 2>"$tmp/bg.err" &
manual=$!
background+=("$manual")
if await_barrier; then
  run_loop_round 35000
  expect_equal "0 91" "$loop_rc $(cat "$tmp/pwm1")" "The loop's newer 35 sets the curve value while the Run is held."
  expect_equal 0 "$(state_field cpu 4)" "The loop's cool round is published, unlatched."
  if release_barrier; then
    { wait "$manual"; } 2>/dev/null; manual_rc=$?
    expect_equal "0 255" "$manual_rc $(cat "$tmp/pwm1")" "The Run's older 60 still enters the emergency."
    expect_equal 1 "$(state_field cpu 4)" "The emergency latch is published."
    run_loop_round 40000
    expect_equal "0 122" "$loop_rc $(cat "$tmp/pwm1")" "A valid newer cool reading releases the emergency normally."
    expect_equal 0 "$(state_field cpu 4)" "The release is published."
  fi
fi
cfg_pwm=100

if (( failures > 0 )); then
  exit 1
fi

echo "curve manual tests passed"
