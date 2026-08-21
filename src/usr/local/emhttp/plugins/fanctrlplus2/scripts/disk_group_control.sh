#!/bin/bash

# shellcheck disable=SC2034,SC2154

# Sourced alongside aux_sensors.sh, whose fcp_clamp_temp is used below.

# Whole degrees Celsius from one drive's smartctl -A output, or nothing when
# the output carries no temperature. Shared by the fan-control loop and the
# manual run; the reading is clamped like every other source.
disk_temp_from_smart() {
  local output="$1" is_nvme="${2:-0}" temp

  if [[ "$is_nvme" == "1" ]]; then
    temp=$(awk '/Temperature:/ {print $2; exit}' <<< "$output")
  else
    temp=$(awk '
      $1 == 190 || $1 == 194                  { print $10; exit }
      $1 == "Temperature_Celsius"             { print $10; exit }
      $1 == "Airflow_Temperature_Cel"         { print $10; exit }
      $1 == "Current" && $3 == "Temperature:" { print $4; exit }
    ' <<< "$output")
  fi

  fcp_clamp_temp "$temp"
}

# Reads smartctl temp for a comma-separated disk-by-id list; echoes the max
# valid temp found (spun-down/unreadable disks skipped), or nothing if no
# disk in the list had a valid temp. Shared by the legacy single-range path
# and each disk group's own range.
disk_group_max_temp() {
  local disks_csv="$1" disk disk_path real_path temp max_valid found=0
  local -a disks_list

  IFS=',' read -ra disks_list <<< "$disks_csv"
  for disk in "${disks_list[@]}"; do
    [[ -z "$disk" ]] && continue
    disk_path="/dev/disk/by-id/$disk"
    real_path=$(realpath "$disk_path" 2>/dev/null)
    [[ ! -b "$real_path" ]] && continue

    smartctl -n standby -A "$real_path" | grep -q "Device is in STANDBY" && continue

    if [[ "$real_path" == /dev/nvme* ]]; then
      temp=$(disk_temp_from_smart "$(smartctl -A "$real_path")" 1)
    else
      temp=$(disk_temp_from_smart "$(smartctl -A "$real_path")" 0)
    fi

    if [[ "$temp" =~ ^[0-9]+$ ]]; then
      (( found == 0 || temp > max_valid )) && max_valid=$temp
      found=1
    fi
  done
  (( found == 1 )) && echo "$max_valid"
}

calculate_disk_pwm() {
  disk_pwm_val=0
  disk_max="*"
  disk_group_name=""
  disk_group_index=""
  disk_curve_readings=()

  if [[ "${disk_group_count:-0}" -gt 0 ]]; then
    local g dvar nvar lvar hvar g_disks g_name g_low g_high
    local g_temp g_pwm g_delta g_range
    local disk_group_hit=0

    for ((g=0; g<disk_group_count; g++)); do
      dvar="disk_group_${g}_disks"
      nvar="disk_group_${g}_name"
      lvar="disk_group_${g}_low"
      hvar="disk_group_${g}_high"
      g_disks="${!dvar:-}"
      [[ -z "$g_disks" ]] && continue
      g_name="${!nvar:-}"
      [[ -z "$g_name" ]] && g_name="Group $((g + 1))"
      g_low="${!lvar:-40}"
      g_high="${!hvar:-60}"
      g_temp=$(disk_group_max_temp "$g_disks")
      [[ -z "$g_temp" ]] && continue

      if (( g_temp <= g_low )); then
        g_pwm=$pwm
      elif (( g_temp >= g_high )); then
        g_pwm=$max
      else
        g_delta=$((g_temp - g_low))
        g_range=$((g_high - g_low))
        g_pwm=$((pwm + g_delta * (max - pwm) / g_range))
      fi
      disk_curve_readings+=("disk:${g}|${g_temp}|${g_pwm}")

      if (( disk_group_hit == 0 || g_pwm > disk_pwm_val )); then
        disk_pwm_val=$g_pwm
        disk_max=$g_temp
        disk_group_name=$g_name
        disk_group_index=$g
        disk_group_hit=1
      fi
    done
  elif [[ -n "${disks:-}" ]]; then
    local disk_max_valid delta range
    disk_max_valid=$(disk_group_max_temp "$disks")
    if [[ -n "$disk_max_valid" ]]; then
      disk_max=$disk_max_valid

      if (( disk_max <= ${low:-40} )); then
        disk_pwm_val=$pwm
      elif (( disk_max >= ${high:-60} )); then
        disk_pwm_val=$max
      else
        delta=$((disk_max - low))
        range=$((high - low))
        disk_pwm_val=$((pwm + delta * (max - pwm) / range))
      fi
      disk_curve_readings+=("disk:0|${disk_max}|${disk_pwm_val}")
      disk_group_index=0
    fi
  fi
}

write_curve_readings() {
  local cache_file="$1"
  local tmp_file="${cache_file}.tmp.$$"
  local reading

  {
    for reading in "${disk_curve_readings[@]}"; do
      printf '%s\n' "$reading"
    done
    if [[ "${cpu_temp:-}" =~ ^[0-9]+$ && "${cpu_pwm_val:-}" =~ ^[0-9]+$ ]]; then
      printf 'cpu|%s|%s\n' "$cpu_temp" "$cpu_pwm_val"
    fi
    if [[ "${aux_temp:-}" =~ ^[0-9]+$ && "${aux_pwm_val:-}" =~ ^[0-9]+$ ]]; then
      printf 'aux|%s|%s\n' "$aux_temp" "$aux_pwm_val"
    fi
  } > "$tmp_file" && mv -f "$tmp_file" "$cache_file"
}

disk_temperature_origin() {
  local group_name="${1:-}"
  group_name="${group_name//)/]}"
  if [[ -n "$group_name" ]]; then
    printf '(Disk: %s)' "$group_name"
  else
    printf '(Disk)'
  fi
}

# ===== Fan-history recording (dashboard history widget) =====
# One line per loop tick: epoch|source|label|temp|pwm, where source is the
# machine key (cpu / aux / idle / disk:<group index>) driving the PWM.

# The widest chart window is 4 hours and the fastest interval is 5 seconds, so
# the cap has to hold 2880 ticks for that window to be shown in full.
fcp_history_max_lines=3000
fcp_labels_file="${fcp_labels_file:-/boot/config/plugins/fanctrlplus2/pwm_labels.cfg}"

# Collection follows the dashboard tile switch (__FCP_HISTORY__ in the labels
# file), re-read every tick so toggling needs no loop restart.
history_enabled() {
  grep -q '^__FCP_HISTORY__[[:space:]]*=[[:space:]]*1[[:space:]]*$' "$fcp_labels_file" 2>/dev/null
}

# Sticky tie-break: when the previous tick's driving source is tied with the
# current winner at the same PWM, the previous source keeps the attribution
# (a group cooling into a tie must not flip the chart color). Echoes
# "source|temp" from the candidate readings ("src|temp|pwm" triplets).
history_pick_source() {
  local prev_src="$1" winner_src="$2" winner_pwm="$3" reading src temp pwm
  shift 3

  if [[ -n "$prev_src" && "$prev_src" != "$winner_src" ]]; then
    for reading in "$@"; do
      IFS='|' read -r src temp pwm <<< "$reading"
      if [[ "$src" == "$prev_src" && "$pwm" == "$winner_pwm" ]]; then
        printf '%s|%s\n' "$src" "$temp"
        return
      fi
    done
  fi

  for reading in "$@"; do
    IFS='|' read -r src temp pwm <<< "$reading"
    if [[ "$src" == "$winner_src" ]]; then
      printf '%s|%s\n' "$src" "$temp"
      return
    fi
  done
  printf '%s|\n' "$winner_src"
}

# Display label for a source key; ")" and "|" in group names are replaced so
# labels stay safe inside "(...)" render contexts and the pipe-separated file.
history_source_label() {
  local src="$1" g nvar name
  case "$src" in
    cpu)  printf 'CPU' ;;
    aux)  printf 'Aux' ;;
    idle) printf 'Idle' ;;
    disk:*)
      g="${src#disk:}"
      nvar="disk_group_${g}_name"
      name="${!nvar:-}"
      if [[ "${disk_group_count:-0}" -eq 0 ]]; then
        printf 'Disk'
      elif [[ -n "$name" ]]; then
        name="${name//)/]}"
        printf 'Disk: %s' "${name//|//}"
      else
        printf 'Disk: Group %d' "$((g + 1))"
      fi
      ;;
    *) printf '%s' "$src" ;;
  esac
}

history_append() {
  local file="$1" epoch="$2" src="$3" label="$4" temp="$5" pwm="$6"
  local tmp_file="${file}.tmp.$$"

  printf '%s|%s|%s|%s|%s\n' "$epoch" "$src" "$label" "$temp" "$pwm" >> "$file"
  if (( $(wc -l < "$file") > fcp_history_max_lines )); then
    tail -n "$fcp_history_max_lines" "$file" > "$tmp_file" && mv -f "$tmp_file" "$file"
  fi
}

# ===== Loop cadence =====
# Configs written before the interval became a seconds value stored minutes in
# "interval"; those are grandfathered by converting them, so an existing fan
# keeps the cadence it was set to. The floor matters because each tick that
# changes the PWM already sleeps 4s to read the resulting RPM back.
fcp_interval_min_seconds=5
fcp_interval_default_seconds=120

interval_seconds() {
  local secs="${interval_sec:-}"

  if [[ ! "$secs" =~ ^[0-9]+$ ]] || (( secs <= 0 )); then
    if [[ "${interval:-}" =~ ^[0-9]+$ ]] && (( interval > 0 )); then
      secs=$((interval * 60))
    else
      secs=$fcp_interval_default_seconds
    fi
  fi

  (( secs < fcp_interval_min_seconds )) && secs=$fcp_interval_min_seconds
  printf '%s' "$secs"
}
