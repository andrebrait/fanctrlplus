#!/bin/bash
# fanctrlplus2_loop.sh - Fan-control loop combining disk and CPU temperatures.

cfg_file="$1"
[[ -f "$cfg_file" ]] || exit 1
source "$cfg_file"
max="${max:-255}"

# ===== Fan Speed on Idle (ABS) =====
# Minimum absolute value: the cfg pwm value is Min Speed.
min_pwm_abs="${pwm:-0}"

if [[ -n "${idle:-}" ]]; then
  idle_pwm_abs="$idle"
elif [[ -n "${idle_percent:-}" ]]; then
  idle_pwm_abs=$(( (idle_percent * 255 + 50) / 100 ))
else
  idle_pwm_abs=0
fi

# Clamp the base value to [0, max].
(( idle_pwm_abs < 0 )) && idle_pwm_abs=0
(( idle_pwm_abs > max )) && idle_pwm_abs="$max"

# Idle Speed must not exceed Min Speed.
if (( idle_pwm_abs > min_pwm_abs )); then
  idle_pwm_abs="$min_pwm_abs"
fi

source "/usr/local/emhttp/plugins/fanctrlplus2/scripts/aux_sensors.sh"
aux_locate_bins "${aux_sensor:-}"

# Reads smartctl temp for a comma-separated disk-by-id list; echoes the max
# valid temp found (spun-down/unreadable disks skipped), or nothing if no
# disk in the list had a valid temp. Shared by the legacy single-range path
# and each disk group's own range.
disk_group_max_temp() {
  local disks_csv="$1" disk disk_path real_path temp max_valid found=0
  IFS=',' read -ra disks_list <<< "$disks_csv"
  for disk in "${disks_list[@]}"; do
    [[ -z "$disk" ]] && continue
    disk_path="/dev/disk/by-id/$disk"
    real_path=$(realpath "$disk_path" 2>/dev/null)
    [[ ! -b "$real_path" ]] && continue

    smartctl -n standby -A "$real_path" | grep -q "Device is in STANDBY" && continue

    if [[ "$real_path" == /dev/nvme* ]]; then
      temp=$(smartctl -A "$real_path" | awk '/Temperature:/ {print $2; exit}')
    else
      temp=$(smartctl -A "$real_path" | awk '
        $1 == 190 || $1 == 194                   { print $10; exit }
        $1 == "Temperature_Celsius"             { print $10; exit }
        $1 == "Airflow_Temperature_Cel"         { print $10; exit }
        $1 == "Current" && $3 == "Temperature:" { print $4; exit }
      ')
    fi

    if [[ "$temp" =~ ^[0-9]+$ ]]; then
      (( found == 0 || temp > max_valid )) && max_valid=$temp
      found=1
    fi
  done
  (( found == 1 )) && echo "$max_valid"
}

source "/usr/local/emhttp/plugins/fanctrlplus2/scripts/disk_group_control.sh"

plugin="fanctrlplus2"
custom="${custom:-$(basename "$cfg_file" .cfg)}"
controller_enable="${controller}_enable"

# Derive the RPM input path.
if [[ "$controller" =~ pwm([0-9]+)$ ]]; then
  fan_index="${BASH_REMATCH[1]}"
  fan_path="$(dirname "$controller")/fan${fan_index}_input"
else
  fan_path=""
fi

prev_pwm=-1
hist_file="/var/tmp/${plugin}/history_${plugin}_${custom}"

# Seed the sticky-source memory from the last recorded tick, so a loop restart
# does not flip the attribution of an ongoing PWM tie (see history_pick_source).
prev_hist_src=$(tail -n 1 "$hist_file" 2>/dev/null | cut -d'|' -f2)

while true; do
  # === CPU temperature ===
  cpu_pwm_val=0
  if [[ "${cpu_enable:-0}" == "1" && -n "$cpu_sensor" && -f "$cpu_sensor" ]]; then
    raw=$(cat "$cpu_sensor")
    [[ "$raw" =~ ^[0-9]+$ ]] && cpu_temp=$((raw / 1000))
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
  # Candidate "src|temp|pwm" readings for the history attribution.
  hist_candidates=("${disk_curve_readings[@]}")
  if [[ "${cpu_temp:-}" =~ ^[0-9]+$ ]]; then
    hist_candidates+=("cpu|${cpu_temp}|${cpu_pwm_val}")
  fi
  if [[ "${aux_temp:-}" =~ ^[0-9]+$ ]]; then
    hist_candidates+=("aux|${aux_temp}|${aux_pwm_val}")
  fi

  if (( cpu_pwm_val > disk_pwm_val )); then
    pwm_val=$cpu_pwm_val
    winner_src="cpu"
  else
    pwm_val=$disk_pwm_val
    winner_src=$([ -n "${disks:-}" ] && echo "disk:${disk_group_index:-0}" || echo "cpu")
  fi

  if (( aux_pwm_val > pwm_val )); then
    pwm_val=$aux_pwm_val
    winner_src="aux"
  fi

  # On a PWM tie the previous tick's source keeps the attribution
  # (history_pick_source); temperature and origin follow the picked source.
  IFS='|' read -r hist_src hist_temp \
    <<< "$(history_pick_source "$prev_hist_src" "$winner_src" "$pwm_val" "${hist_candidates[@]}")"

  if [[ "$hist_temp" =~ ^[0-9]+$ ]]; then
    max_temp=$hist_temp
    temp_origin="($(history_source_label "$hist_src"))"
  else
    # If no temperature source is valid, use Idle Speed and label the source.
    hist_src="idle"
    hist_temp=""
    max_temp="*"
    pwm_val="$idle_pwm_abs"
    temp_origin="(Idle)"
  fi

  # Refresh the dashboard cache on every iteration.
  echo "${max_temp} ${temp_origin}" > "/var/tmp/fanctrlplus2/temp_${plugin}_${custom}"

  # Append the tick to the fan-speed history (dashboard history widget),
  # unless the tile is switched off.
  if history_enabled; then
    history_append "$hist_file" "$(date +%s)" \
      "$hist_src" "$(history_source_label "$hist_src")" "$hist_temp" "$pwm_val"
  fi
  prev_hist_src="$hist_src"

  # === Write when PWM changes materially or on the first iteration ===
  if [[ "$prev_pwm" == -1 ]]; then
    [[ -f "$controller_enable" ]] && echo 1 > "$controller_enable"
    echo "$pwm_val" > "$controller"
    sleep 4
    if [[ -n "$fan_path" && -f "$fan_path" ]]; then
      rpm=$(cat "$fan_path")
    else
      rpm="?"
    fi

    # Always perform the initial write.
    label="[${custom}]"
    logger -t fanctrlplus2 "$label Temp=${max_temp}°C $temp_origin → PWM=$pwm_val → RPM=$rpm"
    prev_pwm=$pwm_val
  else
    if (( pwm_val - prev_pwm >= 5 || prev_pwm - pwm_val >= 5 )); then
      [[ -f "$controller_enable" ]] && echo 1 > "$controller_enable"
      echo "$pwm_val" > "$controller"
      sleep 4
      if [[ -n "$fan_path" && -f "$fan_path" ]]; then
        rpm=$(cat "$fan_path")
      else
        rpm="?"
      fi

      label="[${custom}]"
      log_enable="${syslog:-1}"
      if [[ -z "$log_enable" || "$log_enable" == "1" ]]; then
        logger -t fanctrlplus2 "$label Temp=${max_temp}°C $temp_origin → PWM=$pwm_val → RPM=$rpm"
      fi

      prev_pwm=$pwm_val
    fi
  fi

  sleep "$(interval_seconds)"
done
