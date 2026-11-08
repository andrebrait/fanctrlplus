#!/bin/bash
# fanctrlplus2_loop.sh - Fan-control loop combining disk and CPU temperatures.

cfg_file="$1"
[[ -f "$cfg_file" ]] || exit 1
source "$cfg_file"
max="${max:-255}"

# Keep the old write deadband until a configuration is saved as point curves.
# Full speed (every fail-hot result) is always written, deadband or not.
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

# Whether the last controller write, and the last curve-state snapshot, failed.
# Each is logged once per stretch, not every round.
write_failing=0
publish_failing=0

while true; do
  # ===== Read every source first, outside the curve-state lock =====
  # A slow or stalled sensor (a drive that does not answer smartctl, an aux
  # tool that hangs) must not keep a manual Run from the lock. Each source is
  # stamped with the time its read began: another run may publish a newer
  # observation of it while this one waits, and an older sample must not undo
  # that (see the observation time in curve_control.sh).
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

  # ===== Evaluate the curves and write the controller, under the lock =====
  if ! fcp_curve_begin "$curve_state_file"; then
    logger -p daemon.warning -t fanctrlplus2 "[$custom] Curve state unavailable; continuing fan control with in-memory state."
  fi
  # A manual run may have changed the applied PWM since the previous tick. An
  # unknown output (-1: a run left it pending) makes this round write its target.
  if (( fcp_curve_shared )) && [[ "$prev_pwm" != -1 ]]; then prev_pwm=$fcp_curve_output; fi
  # === CPU temperature ===
  cpu_pwm_val=0
  # Configured sensors that gave no reading this round, for the warning below.
  unread=()
  if (( cpu_sampled )); then
    # No carry-over: a round without a usable value must not reuse the last one.
    fcp_cpu_pwm "$cpu_raw" "$cpu_stamp"
    (( cpu_usable )) || unread+=("$cpu_sensor")
  else
    cpu_temp="-"
    [[ "${cpu_enable:-0}" == "1" && -n "$cpu_sensor" ]] && unread+=("$cpu_sensor")
  fi

  # === Aux Sensor Temperature (iterate CSV, take max) ===
  aux_pwm_val=0
  aux_temp="-"
  if [[ "${aux_enable:-0}" == "1" && -n "$aux_sensor" ]]; then
    aux_max_valid=$(aux_hottest_reading <<< "$aux_readings")
    while IFS= read -r sensor; do
      unread+=("$sensor")
    done < <(aux_unread_sensors <<< "$aux_readings")

    if [[ -n "$aux_max_valid" ]]; then
      aux_temp=$aux_max_valid

      fcp_curve_pwm aux "$aux_temp" "$aux_min_temp" "$aux_max_temp" "$aux_stamp"
      aux_pwm_val=$curve_pwm
    fi
  fi

  # === Disk-temperature PWM ===
  calculate_disk_pwm

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

  # Point curves apply every target; unmigrated ramps keep their write deadband.
  # An output counts as applied only once the write succeeded: after a failed
  # one prev_pwm stays put, so the next round, still holding any emergency
  # latch the snapshot kept, tries again.
  wrote=0
  old_pwm=$prev_pwm
  point_target=$has_custom_curve
  if [[ "${disk_group_count:-0}" -eq 0 && -n "${disks:-}" && ! -v disk_curve && "$disk_max" =~ ^[0-9]+$ ]] && (( disk_pwm_val == pwm_val )); then
    point_target=0
    # A tied point target or an unmet emergency must bypass the legacy disk deadband.
    if [[ "$cpu_temp" =~ ^[0-9]+$ && -v cpu_curve ]] && (( cpu_pwm_val == pwm_val || (${fcp_curve_latch[cpu]:-0} && old_pwm < cpu_pwm_val) )); then point_target=1; fi
    if [[ "$aux_temp" =~ ^[0-9]+$ && -v aux_curve ]] && (( aux_pwm_val == pwm_val || (${fcp_curve_latch[aux]:-0} && old_pwm < aux_pwm_val) )); then point_target=1; fi
  fi
  if [[ "$old_pwm" == -1 ]] || (( pwm_val != old_pwm && (point_target || pwm_val == 255 || pwm_val - old_pwm >= 5 || old_pwm - pwm_val >= 5) )); then
    if fcp_write_pwm "$pwm_val"; then
      wrote=1
      prev_pwm=$pwm_val
      write_failing=0
    elif (( ! write_failing )); then
      write_failing=1
      logger -p daemon.warning -t fanctrlplus2 "[$custom] Could not write PWM=$pwm_val to $controller; keeping the previous output and trying again."
    fi
  fi
  if (( fcp_curve_shared )); then
    if fcp_curve_end "$curve_state_file" "$prev_pwm"; then
      publish_failing=0
    elif (( ! publish_failing )); then
      publish_failing=1
      logger -p daemon.warning -t fanctrlplus2 "[$custom] Curve state could not be saved; keeping it in memory until it can."
    fi
  fi

  # ===== The rest of the round, outside the lock =====
  write_curve_readings "/var/tmp/fanctrlplus2/curves_${plugin}_${custom}"

  # Refresh the dashboard cache on every iteration.
  echo "${max_temp} ${temp_origin}" > "/var/tmp/fanctrlplus2/temp_${plugin}_${custom}"

  # Append the tick to the fan-speed history (dashboard history widget),
  # unless the tile is switched off.
  if history_enabled; then
    history_append "$hist_file" "$(date +%s)" \
      "$hist_src" "$(history_source_label "$hist_src")" "$hist_temp" "$pwm_val"
  fi
  prev_hist_src="$hist_src"

  if (( wrote )); then
    # Let the fan settle, then read its RPM back.
    sleep 4
    if [[ -n "$fan_path" && -f "$fan_path" ]]; then
      rpm=$(cat "$fan_path")
    else
      rpm="?"
    fi

    # The first write is always logged; later ones when syslog is on and the
    # change is at least 5.
    label="[${custom}]"
    log_enable="${syslog:-1}"
    if [[ "$old_pwm" == -1 ]] || { [[ -z "$log_enable" || "$log_enable" == "1" ]] && (( pwm_val - old_pwm >= 5 || old_pwm - pwm_val >= 5 )); }; then
      logger -t fanctrlplus2 "$label Temp=${max_temp}°C $temp_origin → PWM=$pwm_val → RPM=$rpm"
    fi
  fi

  # Warn about lost control, a rejected speed, or a fan that stopped. Until a
  # write has succeeded only the controller and its mode can be checked.
  check_target=""
  (( prev_pwm >= 0 )) && check_target=$prev_pwm
  fcp_check_fan "$controller" "$fan_path" "$check_target" "$nct6687_watch" "$fault_dir"
  fcp_track_faults "$SECONDS" "$custom" "$fault_dir"

  # Configured sensors that each stay unread past the grace period: warn once.
  fcp_track_missing "$SECONDS" "${unread[@]}"
  if (( ${#fcp_missing_overdue[@]} )); then
    fcp_notify_missing "$custom" "/var/tmp/${plugin}/sensor_missing_${custom}" "${fcp_missing_overdue[@]}"
  fi

  sleep "$(interval_seconds)"
done
