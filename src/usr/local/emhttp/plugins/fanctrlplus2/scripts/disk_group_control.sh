#!/bin/bash

# shellcheck disable=SC2034,SC2154

calculate_disk_pwm() {
  disk_pwm_val=0
  disk_max="*"
  disk_group_name=""
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
