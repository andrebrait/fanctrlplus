#!/bin/bash
# fanctrlplus2_refresh_single.sh
plugin="fanctrlplus2"
cfg_path="/boot/config/plugins/$plugin"
custom="$1"
cfg_file="$cfg_path/${plugin}_$custom.cfg"
[[ -f "$cfg_file" ]] || exit 1
source "$cfg_file"
max="${max:-255}"
controller_enable="${controller}_enable"

source "/usr/local/emhttp/plugins/fanctrlplus2/scripts/aux_sensors.sh"
aux_locate_bins "${aux_sensor:-}"

source "/usr/local/emhttp/plugins/fanctrlplus2/scripts/disk_group_control.sh"

# === CPU temperature ===
cpu_pwm_val=0
if [[ "${cpu_enable:-0}" == "1" && -n "$cpu_sensor" && -f "$cpu_sensor" ]]; then
  raw=$(cat "$cpu_sensor")
  [[ "$raw" =~ ^-?[0-9]+$ ]] && cpu_temp=$(fcp_clamp_temp $((raw / 1000)))
  cpu_temp=${cpu_temp:-0}

  if (( cpu_temp <= cpu_min_temp )); then
    cpu_pwm_val=$pwm
  elif (( cpu_temp >= cpu_max_temp )); then
    cpu_pwm_val=$max
  else
    delta=$((cpu_temp - cpu_min_temp))
    range=$((cpu_max_temp - cpu_min_temp))
    cpu_pwm_val=$((pwm + delta * (max - pwm) / range))
  fi
else
  cpu_temp="-"
fi

# === Aux Sensor Temperature (iterate CSV, take max) ===
aux_pwm_val=0
aux_temp="-"
if [[ "${aux_enable:-0}" == "1" && -n "$aux_sensor" ]]; then
  aux_max_valid=$(aux_max_temp_reading "$aux_sensor")

  if [[ -n "$aux_max_valid" ]]; then
    aux_temp=$aux_max_valid

    if (( aux_temp <= aux_min_temp )); then
      aux_pwm_val=$pwm
    elif (( aux_temp >= aux_max_temp )); then
      aux_pwm_val=$max
    else
      delta=$((aux_temp - aux_min_temp))
      range=$((aux_max_temp - aux_min_temp))
      aux_pwm_val=$((pwm + delta * (max - pwm) / range))
    fi
  fi
fi

# === Disk-temperature PWM ===
calculate_disk_pwm
write_curve_readings "/var/tmp/fanctrlplus2/curves_${plugin}_${custom}"
  
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

# Force the PWM value.
[[ -f "$controller_enable" ]] && echo 1 > "$controller_enable"
echo "$pwm_val" > "$controller"
sleep 4

# Read the RPM value.
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
