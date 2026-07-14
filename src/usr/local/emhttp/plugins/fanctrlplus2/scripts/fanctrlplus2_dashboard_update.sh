#!/bin/bash
# fanctrlplus2_dashboard_update.sh - Update dashboard RPM and PWM data in real time.
plugin="fanctrlplus2"
cfg_path="/boot/config/plugins/$plugin"
tmp_path="/var/tmp/$plugin"

mkdir -p "$tmp_path"

while true; do
  for cfg in "$cfg_path"/${plugin}_*.cfg; do
    [[ -f "$cfg" ]] || continue

    source "$cfg"
    [[ "$service" != "1" ]] && continue
    [[ -z "$controller" || -z "$custom" ]] && continue

    # Derive the fanX_input path from pwmX.
    if [[ "$controller" =~ pwm([0-9]+)$ ]]; then
      fan_index="${BASH_REMATCH[1]}"
      fan_path="$(dirname "$controller")/fan${fan_index}_input"
    else
      continue
    fi

    # Read RPM.
    rpm="-"
    [[ -f "$fan_path" ]] && rpm=$(< "$fan_path")

    # Write the RPM cache file.
    echo "$rpm" > "$tmp_path/rpm_${plugin}_${custom}"

    # Read PWM.
    pwm_val="-"
    [[ -f "$controller" ]] && pwm_val=$(< "$controller")

    # Write the PWM cache file.
    echo "$pwm_val" > "$tmp_path/pwm_${plugin}_${custom}"

    # Determine the current status.
    if [[ "$rpm" =~ ^[0-9]+$ ]] && (( rpm > 0 )); then
      echo "Running" > "$tmp_path/status_${plugin}_${custom}"
    else
      echo "Stopped" > "$tmp_path/status_${plugin}_${custom}"
    fi
  done

  sleep 5  # Dashboard refresh interval; this does not affect fan control.
done
