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

# Locate external tool binaries for aux sensor reading
storcli_bin=""
nvidia_smi_bin=""
if [[ "${aux_sensor:-}" == *storcli:* ]]; then
  for candidate in /opt/MegaRAID/storcli/storcli64 /opt/MegaRAID/storcli/storcli \
                   /usr/local/sbin/storcli64 /usr/local/sbin/storcli \
                   /usr/local/bin/storcli64 /usr/local/bin/storcli \
                   /usr/sbin/storcli64 /usr/sbin/storcli \
                   /usr/bin/storcli64 /usr/bin/storcli; do
    if [[ -x "$candidate" ]]; then storcli_bin="$candidate"; break; fi
  done
  [[ -z "$storcli_bin" ]] && storcli_bin=$(command -v storcli64 2>/dev/null || command -v storcli 2>/dev/null || true)
fi
if [[ "${aux_sensor:-}" == *nvidia:* ]]; then
  for candidate in /usr/bin/nvidia-smi /usr/local/bin/nvidia-smi /usr/lib/nvidia/bin/nvidia-smi; do
    if [[ -x "$candidate" ]]; then nvidia_smi_bin="$candidate"; break; fi
  done
  [[ -z "$nvidia_smi_bin" ]] && nvidia_smi_bin=$(command -v nvidia-smi 2>/dev/null || true)
fi

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
  aux_max_valid=0

  IFS=',' read -ra aux_list <<< "$aux_sensor"
  for sensor in "${aux_list[@]}"; do
    cur_temp=0

    if [[ "$sensor" == storcli:* ]]; then
      if [[ -n "$storcli_bin" && "$sensor" =~ ^storcli:c([0-9]+):roc$ ]]; then
        sc_ctrl="${BASH_REMATCH[1]}"
        cur_temp=$("$storcli_bin" "/c${sc_ctrl}" show temperature 2>/dev/null \
          | awk '/ROC temperature/{print $NF; exit}')
        cur_temp=${cur_temp:-0}
      fi
    elif [[ "$sensor" == nvidia:* ]]; then
      if [[ -n "$nvidia_smi_bin" && "$sensor" =~ ^nvidia:gpu([0-9]+)$ ]]; then
        gpu_idx="${BASH_REMATCH[1]}"
        cur_temp=$("$nvidia_smi_bin" --query-gpu=temperature.gpu --format=csv,noheader,nounits -i "$gpu_idx" 2>/dev/null)
        cur_temp=${cur_temp:-0}
      fi
    elif [[ -f "$sensor" ]]; then
      raw=$(cat "$sensor")
      [[ "$raw" =~ ^[0-9]+$ ]] && cur_temp=$((raw / 1000))
      cur_temp=${cur_temp:-0}
    fi

    if [[ "$cur_temp" =~ ^[0-9]+$ ]] && (( cur_temp > aux_max_valid )); then
      aux_max_valid=$cur_temp
    fi
  done

  if (( aux_max_valid > 0 )); then
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
disk_pwm_val=0
disk_max="*"

if [[ "${disk_group_count:-0}" -gt 0 ]]; then
  # Disk groups: each has its own Low/High range, driven by its own hottest
  # selected disk. Fan reacts to whichever group's computed PWM is highest.
  disk_group_hit=0
  for (( g=0; g<disk_group_count; g++ )); do
    dvar="disk_group_${g}_disks"; lvar="disk_group_${g}_low"; hvar="disk_group_${g}_high"
    g_disks="${!dvar:-}"
    [[ -z "$g_disks" ]] && continue
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

    if (( disk_group_hit == 0 || g_pwm > disk_pwm_val )); then
      disk_pwm_val=$g_pwm
      disk_max=$g_temp
      disk_group_hit=1
    fi
  done
elif [ -n "$disks" ]; then
  # Legacy single-range path (pre-group configs) -- unchanged behavior.
  disk_max_valid=$(disk_group_max_temp "$disks")
  if [[ -n "$disk_max_valid" ]]; then
    disk_max=$disk_max_valid

    if (( disk_max <= low )); then
      disk_pwm_val=$pwm
    elif (( disk_max >= high )); then
      disk_pwm_val=$max
    else
      delta=$((disk_max - low))
      range=$((high - low))
      disk_pwm_val=$((pwm + delta * (max - pwm) / range))
    fi
  fi
fi
  
# === Use the higher PWM and record its temperature and source ===
if (( cpu_pwm_val > disk_pwm_val )); then
  pwm_val=$cpu_pwm_val
  max_temp=$cpu_temp
  temp_origin="(CPU)"
else
  pwm_val=$disk_pwm_val
  max_temp=$disk_max
  temp_origin=$([ -n "$disks" ] && echo "(Disk)" || echo "(CPU)")
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
