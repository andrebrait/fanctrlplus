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
expect_equal "200" "$(disk_temp_from_smart "$hot_output" 0)" \
  "A disk reading above the ceiling is clamped like every other source."

# A raw attribute field carrying something that is not a temperature must not be
# clamped into one: the disk sits the round out, as a spun-down one does.
garbage_output='ID# ATTRIBUTE_NAME          FLAG     VALUE WORST THRESH TYPE      UPDATED  WHEN_FAILED RAW_VALUE
194 Temperature_Celsius     0x0022   118   100   000    Old_age   Always       -       65535'
expect_equal "" "$(disk_temp_from_smart "$garbage_output" 0)" \
  "A raw value no disk could report yields no reading."

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

if (( failures > 0 )); then
  exit 1
fi

echo "disk group control tests passed"
