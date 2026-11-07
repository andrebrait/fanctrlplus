#!/bin/bash

set -u

root=$(cd "$(dirname "$0")/.." && pwd)
# Both libraries, in the order the loop and the manual run source them.
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

# ===== disk_temp_from_smart =====
# The temperature rules that read smartctl output, exercised on the shapes the
# three device families actually print.
sata_output='SMART Attributes Data Structure revision number: 16
Vendor Specific SMART Attributes with Thresholds:
ID# ATTRIBUTE_NAME          FLAG     VALUE WORST THRESH TYPE      UPDATED  WHEN_FAILED RAW_VALUE
  9 Power_On_Hours          0x0032   089   089   000    Old_age   Always       -       8123
194 Temperature_Celsius     0x0022   118   100   000    Old_age   Always       -       32'
expect_equal "32" "$(disk_temp_from_smart "$sata_output" 0)" \
  "A SATA attribute table reports its raw temperature value."

airflow_output='ID# ATTRIBUTE_NAME          FLAG     VALUE WORST THRESH TYPE      UPDATED  WHEN_FAILED RAW_VALUE
190 Airflow_Temperature_Cel 0x0022   062   045   000    Old_age   Always       -       38'
expect_equal "38" "$(disk_temp_from_smart "$airflow_output" 0)" \
  "An airflow temperature attribute is read the same way."

scsi_output='Elements in grown defect list: 0
Current Drive Temperature:     34 C
Drive Trip Temperature:        65 C'
expect_equal "34" "$(disk_temp_from_smart "$scsi_output" 0)" \
  "A SCSI drive reports its current temperature line."

nvme_output='SMART/Health Information (NVMe Log 0x02)
Critical Warning:                   0x00
Temperature:                        38 Celsius
Available Spare:                    100%'
expect_equal "38" "$(disk_temp_from_smart "$nvme_output" 1)" \
  "An NVMe health log reports its temperature line."

expect_equal "" "$(disk_temp_from_smart 'Device is in STANDBY mode' 0)" \
  "Output with no temperature in it yields no reading."
expect_equal "" "$(disk_temp_from_smart '' 1)" \
  "Empty output yields no reading."

hot_output='ID# ATTRIBUTE_NAME          FLAG     VALUE WORST THRESH TYPE      UPDATED  WHEN_FAILED RAW_VALUE
194 Temperature_Celsius     0x0022   118   100   000    Old_age   Always       -       250'
expect_equal "250" "$(disk_temp_from_smart "$hot_output" 0)" \
  "A hot disk reading is reported as measured, like every other source."

# A raw attribute field carrying something that is not a temperature must not be
# clamped into one: the disk sits the round out, as a spun-down one does.
garbage_output='ID# ATTRIBUTE_NAME          FLAG     VALUE WORST THRESH TYPE      UPDATED  WHEN_FAILED RAW_VALUE
194 Temperature_Celsius     0x0022   118   100   000    Old_age   Always       -       65535'
expect_equal "" "$(disk_temp_from_smart "$garbage_output" 0)" \
  "A raw value no disk could report yields no reading."

# ===== disk_temp_read: one bounded smartctl call per drive =====
# A stand-in smartctl, picked by the device name: a drive that sleeps, one whose
# exit status carries health bits, and one that never answers. The real
# timeout(1) and the real library run against it.
fakebin=$(mktemp -d)
trap 'rm -rf "$fakebin"' EXIT
smart_log="$fakebin/calls"
stalled_marker="$fakebin/stalled"
printf '%s\n' "$sata_output" > "$fakebin/sata"
printf '%s\n' "$nvme_output" > "$fakebin/nvme"
cat > "$fakebin/smartctl" <<'SMART'
#!/bin/bash
printf '%s\n' "$*" >> "$FCP_FAKE_SMART_LOG"
case "${@: -1}" in
  */asleep)
    echo 'Device is in STANDBY mode, exit(2)'
    exit 2 ;;
  */failing)
    cat "$FCP_FAKE_SMART_ATTRS"
    exit 8 ;;
  */stalled)
    : > "$FCP_FAKE_SMART_STALLED"
    exec sleep 1000 ;;
  *)
    cat "$FCP_FAKE_SMART_ATTRS" ;;
esac
SMART
chmod +x "$fakebin/smartctl"

# Prints the reading for DEVICE. A call that is not bounded would stall the
# suite, so it runs under a watchdog and prints TIMED-OUT if that fires.
read_disk() {
  local out rc
  out=$(env PATH="$fakebin:$PATH" FCP_FAKE_SMART_LOG="$smart_log" FCP_FAKE_SMART_STALLED="$stalled_marker" \
    FCP_FAKE_SMART_ATTRS="$fakebin/${2:-sata}" fcp_smart_timeout="${3:-10}" \
    timeout 30 bash -c 'source "$1"; source "$2"; disk_temp_read "$3"' _ \
    "$root/src/usr/local/emhttp/plugins/fanctrlplus2/scripts/aux_sensors.sh" \
    "$root/src/usr/local/emhttp/plugins/fanctrlplus2/scripts/disk_group_control.sh" "$1")
  rc=$?
  if (( rc == 124 )); then printf 'TIMED-OUT'; else printf '%s' "$out"; fi
}

expect_equal "32" "$(read_disk /dev/sda)" "A readable SATA drive gives its temperature."
expect_equal "1" "$(grep -c -- ' /dev/sda$' "$smart_log")" "A readable drive is probed once, not once to see if it sleeps and again to read it."
expect_equal "-n standby -A /dev/sda" "$(grep -- ' /dev/sda$' "$smart_log")" "The one probe must leave a sleeping drive asleep."
expect_equal "38" "$(read_disk /dev/nvme0n1 nvme)" "An NVMe drive gives its temperature."
expect_equal "32" "$(read_disk /dev/failing)" "A smartctl exit status that carries health bits still has a valid reading."
expect_equal "" "$(read_disk /dev/asleep)" "A sleeping drive has no reading."
expect_equal "1" "$(grep -c asleep "$smart_log")" "A sleeping drive is probed once, and only with -n standby."
expect_equal "-n standby -A /dev/asleep" "$(grep asleep "$smart_log")" "A sleeping drive is never woken by a plain read."
expect_equal "" "$(read_disk /dev/stalled sata 1)" "A drive that never answers has no reading, and the read ends by itself."
expect_equal "present" "$([[ -e "$stalled_marker" ]] && echo present || echo absent)" "The stalled drive was really probed."

disk_group_max_temp() {
  case "$1" in
    array) echo 37 ;;
    pool) echo 45 ;;
    legacy) echo 36 ;;
  esac
}

pwm=102
max=255
disk_group_count=2
disk_group_0_name="Array"
disk_group_0_disks="array"
disk_group_0_low=35
disk_group_0_high=45
disk_group_1_name="Apps-pool"
disk_group_1_disks="pool"
disk_group_1_low=30
disk_group_1_high=50
disks="array,pool"

sample_disk_temps
calculate_disk_pwm
expect_equal 216 "$disk_pwm_val" "The hottest control curve must determine disk PWM."
expect_equal 45 "$disk_max" "The winning group's temperature must be retained."
expect_equal "Apps-pool" "$disk_group_name" "The winning group's name must be retained."
expect_equal "disk:0|37|132 disk:1|45|216" "${disk_curve_readings[*]}" "Every disk group's latest point must be retained."
expect_equal "(Disk: Apps-pool)" "$(disk_temperature_origin "$disk_group_name")" "Logs must identify the winning disk group."

disk_group_count=0
disks="legacy"
low=30
high=40
sample_disk_temps
calculate_disk_pwm
expect_equal 193 "$disk_pwm_val" "Legacy configurations must keep their single-range calculation."
expect_equal "disk:0|36|193" "${disk_curve_readings[*]}" "Legacy configurations must expose their current curve point."
expect_equal "" "$disk_group_name" "Legacy configurations must not invent a group name."
expect_equal "(Disk)" "$(disk_temperature_origin "$disk_group_name")" "Legacy logs must keep the existing disk source label."
expect_equal "(Disk: Pool ] Main)" "$(disk_temperature_origin 'Pool ) Main')" "Group names must not break the cache delimiter."

curve_cache=$(mktemp)
cpu_temp=42
cpu_pwm_val=128
aux_temp=39
aux_pwm_val=102
write_curve_readings "$curve_cache"
expect_equal $'disk:0|36|193\ncpu|42|128\naux|39|102' "$(< "$curve_cache")" "The curve cache must include disk, CPU, and Aux points."
rm -f "$curve_cache"

# calculate_disk_pwm only evaluates: it must not read a drive, because it runs
# under the curve-state lock that a stalled drive must never hold.
disk_group_count=2
disks="array,pool"
disk_group_max_temp() {
  case "$1" in
    array) echo 37 ;;
    pool) echo 45 ;;
  esac
}
sample_disk_temps
disk_group_max_temp() { echo "drive read during evaluation" >&2; echo 99; }
calculate_disk_pwm 2>"$fakebin/evaluation-reads"
expect_equal "216" "$disk_pwm_val" "Evaluation uses the temperatures sampled earlier."
expect_equal "" "$(< "$fakebin/evaluation-reads")" "Evaluating the disk PWM must not touch a drive."

# Each group's sample carries the time its read began, which the curve state
# orders it by: taken before the drives are read, never after.
disk_group_max_temp() {
  fcp_stamp_now
  printf '%s\n' "$fcp_now" >> "$fakebin/read-starts"
  echo 40
}
: > "$fakebin/read-starts"
disk_group_count=2
sample_disk_temps
mapfile -t read_starts < "$fakebin/read-starts"
expect_equal 2 "${#read_starts[@]}" "Each group's drives are read once."
expect_equal yes "$([[ "${fcp_disk_stamps[0]}" =~ ^[1-9][0-9]{9,15}$ && "${fcp_disk_stamps[1]}" =~ ^[1-9][0-9]{9,15}$ ]] && echo yes || echo no)" "Each group's sample time is a bounded number of microseconds."
expect_equal yes "$( (( fcp_disk_stamps[0] <= read_starts[0] && fcp_disk_stamps[1] <= read_starts[1] && fcp_disk_stamps[0] <= fcp_disk_stamps[1] )) && echo yes || echo no )" "A group's sample time is taken before its drives are read."

disk_group_count=0
disks="legacy"
sample_disk_temps
expect_equal yes "$([[ "${fcp_disk_stamps[0]}" =~ ^[1-9][0-9]{9,15}$ ]] && echo yes || echo no)" "The legacy disk list is stamped as group 0."

if (( failures > 0 )); then
  exit 1
fi

echo "disk group control tests passed"
