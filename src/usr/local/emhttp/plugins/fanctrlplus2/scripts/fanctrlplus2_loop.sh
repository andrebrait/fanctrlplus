#!/bin/bash
# fanctrlplus2_loop.sh - Fan-control loop combining disk and CPU temperatures.

cfg_file="$1"
[[ -f "$cfg_file" ]] || exit 1
source "$cfg_file"
max="${max:-255}"

# Keep the old write deadband until a configuration is saved as point curves.
has_custom_curve=0
for curve_key in cpu_curve aux_curve disk_curve ${!disk_group_@}; do
  if [[ "$curve_key" == *_curve && -v "$curve_key" ]]; then has_custom_curve=1; break; fi
done

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

source "/usr/local/emhttp/plugins/fanctrlplus2/scripts/disk_group_control.sh"

plugin="fanctrlplus2"
# The dashboard updater also creates this, but may start after the loops.
mkdir -p "/var/tmp/${plugin}"
custom="${custom:-$(basename "$cfg_file" .cfg)}"
# Shared RAM state keeps hysteresis and emergency latches across manual runs.
curve_state_file="/var/tmp/${plugin}/curve_state_${plugin}_${custom}"
controller_enable="${controller}_enable"

# Derive the RPM input path.
if [[ "$controller" =~ pwm([0-9]+)$ ]]; then
  fan_index="${BASH_REMATCH[1]}"
  fan_path="$(dirname "$controller")/fan${fan_index}_input"
else
  fan_path=""
fi

nct6687_watch=0
fcp_nct6687_msi_channel "$controller" && nct6687_watch=1
# Problems this fan is being warned about, one file each (fcp_track_faults).
fault_dir="/var/tmp/${plugin}/faults_${custom}"
mkdir -p "$fault_dir"

prev_pwm=-1
hist_file="/var/tmp/${plugin}/history_${plugin}_${custom}"

# Seed the sticky-source memory from the last recorded tick, so a loop restart
# does not flip the attribution of an ongoing PWM tie (see history_pick_source).
prev_hist_src=$(tail -n 1 "$hist_file" 2>/dev/null | cut -d'|' -f2)

while true; do
  if ! fcp_curve_begin "$curve_state_file"; then
    logger -p daemon.warning -t fanctrlplus2 "[$custom] Curve state unavailable; continuing fan control with in-memory state."
  fi
  # A manual run may have changed the applied PWM since the previous tick.
  if (( fcp_curve_shared )) && [[ "$prev_pwm" != -1 ]] && (( fcp_curve_output >= 0 )); then prev_pwm=$fcp_curve_output; fi
  # === CPU temperature ===
  cpu_pwm_val=0
  # Configured sensors that gave no reading this round, for the warning below.
  unread=()
  if [[ "${cpu_enable:-0}" == "1" && -n "$cpu_sensor" && -f "$cpu_sensor" ]]; then
    raw=$(cat "$cpu_sensor")
    # No carry-over: a round without a usable value must not reuse the last one.
    cpu_temp=""
    [[ "$raw" =~ ^-?[0-9]+$ ]] && cpu_temp=$(fcp_clamp_temp $((raw / 1000)))
    # 0 marks an unpopulated hwmon input, as for the aux sensors.
    [[ "$raw" =~ ^-?[0-9]+$ && "$raw" -ne 0 && -n "$cpu_temp" ]] || unread+=("$cpu_sensor")
    cpu_temp=${cpu_temp:-0}

    fcp_curve_pwm cpu "$cpu_temp" "$cpu_min_temp" "$cpu_max_temp"
    cpu_pwm_val=$curve_pwm
  else
    cpu_temp="-"
    [[ "${cpu_enable:-0}" == "1" && -n "$cpu_sensor" ]] && unread+=("$cpu_sensor")
  fi

  # === Aux Sensor Temperature (iterate CSV, take max) ===
  aux_pwm_val=0
  aux_temp="-"
  if [[ "${aux_enable:-0}" == "1" && -n "$aux_sensor" ]]; then
    aux_readings=$(aux_temp_readings "$aux_sensor")
    aux_max_valid=$(aux_hottest_reading <<< "$aux_readings")
    while IFS= read -r sensor; do
      unread+=("$sensor")
    done < <(aux_unread_sensors <<< "$aux_readings")

    if [[ -n "$aux_max_valid" ]]; then
      aux_temp=$aux_max_valid

      fcp_curve_pwm aux "$aux_temp" "$aux_min_temp" "$aux_max_temp"
      aux_pwm_val=$curve_pwm
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

  # Point curves apply every target; unmigrated ramps keep their write deadband.
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
    if (( pwm_val != prev_pwm && (has_custom_curve || pwm_val - prev_pwm >= 5 || prev_pwm - pwm_val >= 5) )); then
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
      if [[ -z "$log_enable" || "$log_enable" == "1" ]] && (( pwm_val - prev_pwm >= 5 || prev_pwm - pwm_val >= 5 )); then
        logger -t fanctrlplus2 "$label Temp=${max_temp}°C $temp_origin → PWM=$pwm_val → RPM=$rpm"
      fi

      prev_pwm=$pwm_val
    fi
  fi
  if (( fcp_curve_shared )); then fcp_curve_end "$curve_state_file" "$prev_pwm"; fi

  # Warn about lost control, a rejected speed, or a fan that stopped.
  fcp_check_fan "$controller" "$fan_path" "$prev_pwm" "$nct6687_watch" "$fault_dir"
  fcp_track_faults "$SECONDS" "$custom" "$fault_dir"

  # Configured sensors that each stay unread past the grace period: warn once.
  fcp_track_missing "$SECONDS" "${unread[@]}"
  if (( ${#fcp_missing_overdue[@]} )); then
    fcp_notify_missing "$custom" "/var/tmp/${plugin}/sensor_missing_${custom}" "${fcp_missing_overdue[@]}"
  fi

  sleep "$(interval_seconds)"
done
