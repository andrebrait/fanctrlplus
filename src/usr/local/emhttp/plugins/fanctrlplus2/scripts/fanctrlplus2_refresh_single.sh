#!/bin/bash
# fanctrlplus2_refresh_single.sh - one manual evaluation of a fan (Run Now):
# reads its sources, sets the controller once and waits for the RPM to settle.
# It runs to completion and its exit status is the answer to the request: when
# it cannot coordinate with the fan loop or write the controller it exits 1
# with the reason on stderr, rather than let anything be reported as done.
plugin="fanctrlplus2"
cfg_path="${FCP_CONFIG_DIR:-/boot/config/plugins/$plugin}"
custom="$1"
cfg_file="$cfg_path/${plugin}_$custom.cfg"
[[ -f "$cfg_file" ]] || { echo "No configuration found for fan \"$custom\"." >&2; exit 1; }
source "$cfg_file"
max="${max:-255}"
controller_enable="${controller}_enable"
curve_state_file="/var/tmp/${plugin}/curve_state_${plugin}_${custom}"

source "/usr/local/emhttp/plugins/fanctrlplus2/scripts/aux_sensors.sh"

source "/usr/local/emhttp/plugins/fanctrlplus2/scripts/disk_group_control.sh"

# Reports why this run failed, to the caller and the log, and stops.
fail() {
  echo "$*" >&2
  logger -p daemon.warning -t fanctrlplus2 "Manual Run [$custom]: $*"
  exit 1
}

mkdir -p "/var/tmp/${plugin}" ||
  fail "Could not create /var/tmp/${plugin}, so this run cannot coordinate with the fan loop. Nothing was written."

# ===== Read every source first, outside the curve-state lock =====
# A slow or stalled sensor must not keep the fan loop from the lock, nor this
# run from the other sources. Each source is stamped with the time its read
# began, so a sample older than a newer observation another run published while
# this one waited cannot undo it (see the observation time in curve_control.sh).
cpu_sampled=0
if [[ "${cpu_enable:-0}" == "1" && -n "$cpu_sensor" && -f "$cpu_sensor" ]]; then
  fcp_stamp_now
  cpu_stamp=$fcp_now
  cpu_raw=$(cat "$cpu_sensor")
  cpu_sampled=1
fi
aux_readings=""
if [[ "${aux_enable:-0}" == "1" && -n "$aux_sensor" ]]; then
  fcp_stamp_now
  aux_stamp=$fcp_now
  aux_readings=$(aux_temp_readings "$aux_sensor")
fi
sample_disk_temps

# ===== Evaluate and write, under the lock shared with the fan loop =====
# Without it this run would race the loop's own write and curve state, so it
# does not run at all.
fcp_curve_begin "$curve_state_file" ||
  fail "Could not take the fan's curve-state lock (waited ${fcp_curve_lock_wait}s). Nothing was written."

# === CPU temperature ===
cpu_pwm_val=0
if (( cpu_sampled )); then
  fcp_cpu_pwm "$cpu_raw" "$cpu_stamp"
else
  cpu_temp="-"
fi

# === Aux Sensor Temperature (iterate CSV, take max) ===
aux_pwm_val=0
aux_temp="-"
if [[ "${aux_enable:-0}" == "1" && -n "$aux_sensor" ]]; then
  aux_max_valid=$(aux_hottest_reading <<< "$aux_readings")

  if [[ -n "$aux_max_valid" ]]; then
    aux_temp=$aux_max_valid

    fcp_curve_pwm aux "$aux_temp" "$aux_min_temp" "$aux_max_temp" "$aux_stamp"
    aux_pwm_val=$curve_pwm
  fi
fi

# === Disk-temperature PWM ===
calculate_disk_pwm

# === Use the higher PWM and record its temperature and source ===
if (( cpu_pwm_val > disk_pwm_val )); then
  pwm_val=$cpu_pwm_val
  max_temp=$cpu_temp
  temp_origin="(CPU)"
else
  pwm_val=$disk_pwm_val
  max_temp=$disk_max
  temp_origin=$([ -n "${disks:-}" ] && disk_temperature_origin "$disk_group_name" || echo "(CPU)")
fi

if (( aux_pwm_val > pwm_val )); then
  pwm_val=$aux_pwm_val
  max_temp=$aux_temp
  temp_origin="(Aux)"
fi

# Do not write an empty value.
if [[ ! "$max_temp" =~ ^[0-9]+$ ]]; then
  max_temp="*"
  temp_origin=""
fi

# Save the curve state, emergency latch included, before touching the fan. A run
# that cannot save it must not set the fan: its latch would die with this
# process, and the loop would carry on from the older, unlatched snapshot.
if ! fcp_curve_publish "$curve_state_file"; then
  flock -u "$fcp_curve_state_fd"
  fail "The fan's curve state could not be saved in /var/tmp/${plugin}, so this run did not set the fan. The fan loop keeps control."
fi

# Force the PWM value. The output is recorded as applied only once the write
# succeeded; after a failed one the snapshot keeps the previous output (or none)
# and still carries any emergency latch, so the fan loop tries again.
if fcp_write_pwm "$pwm_val"; then
  applied=$pwm_val
  write_failed=0
else
  applied=$fcp_curve_output
  write_failed=1
fi
publish_failed=0
fcp_curve_end "$curve_state_file" "$applied" || publish_failed=1

write_curve_readings "/var/tmp/fanctrlplus2/curves_${plugin}_${custom}"

(( write_failed )) &&
  fail "Could not write PWM=$pwm_val to $controller. The fan keeps its previous speed and the fan loop will try again."

# Give the fan time to settle, then read the RPM value.
sleep 4
fan_index=""
if [[ "$controller" =~ pwm([0-9]+)$ ]]; then
  fan_index="${BASH_REMATCH[1]}"
  fan_path="$(dirname "$controller")/fan${fan_index}_input"
fi
if [[ -n "$fan_path" && -f "$fan_path" ]]; then
  rpm=$(cat "$fan_path")
else
  rpm="?"
fi

label="[${custom}]"
logger -t fanctrlplus2 "Manual Run $label Temp=${max_temp}°C $temp_origin → PWM=$pwm_val → RPM=$rpm"

echo "${max_temp} ${temp_origin}" > "/var/tmp/fanctrlplus2/temp_${plugin}_${custom}"

# The latch is saved; only the applied output could not be recorded, so the loop
# may write once more to reach the same target.
(( publish_failed )) &&
  fail "The fan was set to PWM=$pwm_val, but its curve state could not be saved in /var/tmp/${plugin} afterwards."
exit 0
