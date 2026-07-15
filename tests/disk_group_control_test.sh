#!/bin/bash

set -u

root=$(cd "$(dirname "$0")/.." && pwd)
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
