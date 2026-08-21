#!/bin/bash
# Auxiliary temperature sources shared by the control loop and the manual run:
# plain hwmon inputs plus the sensors that only an external tool can read
# (LSI/MegaRAID via storcli, NVIDIA GPUs via nvidia-smi, Mellanox NICs via
# mget_temp).

# shellcheck disable=SC2034

# Drop-in directory for user-supplied sensor scripts. Each executable there is
# one sensor: it prints the temperature in Celsius and nothing else, or prints
# nothing and exits non-zero, which disables it for that round only.
fcp_custom_sensor_dir="${fcp_custom_sensor_dir:-/boot/config/plugins/fanctrlplus2/sensors.d}"
fcp_custom_sensor_timeout="${fcp_custom_sensor_timeout:-5}"

# ===== Temperature readings =====
# Every reading the plugin evaluates, whatever produced it, is pulled into the
# range the fan curve can act on. Clamping rather than discarding keeps a
# source that reports an odd value in play: the curve saturates at its own high
# point anyway, so the ceiling and a wild reading drive the fan identically,
# and a source with nothing to report says so by producing no reading at all.
fcp_temp_floor=0
fcp_temp_ceiling=200

# Beyond this, a value is not a measurement at all. Tools report failure in
# band -- mget_temp has been seen printing 10000 or -10000 when it cannot read
# the card, exiting 0 as it does so -- and clamping such a value to the ceiling
# would drive the fan to maximum on a failed read. Anything outside leaves the
# source with nothing to report for that round.
fcp_temp_implausible_below=-100
fcp_temp_implausible_above=300

fcp_clamp_temp() {
  local value="${1:-}" temp

  # Base 10 explicitly: a padded reading like 0900 is a decimal temperature,
  # not an octal literal that would fail the arithmetic outright.
  [[ "$value" =~ ^(-?)0*([0-9]+)$ ]] || return 0
  temp=$((10#${BASH_REMATCH[2]}))
  [[ "${BASH_REMATCH[1]}" == "-" ]] && temp=$(( -temp ))

  (( temp < fcp_temp_implausible_below || temp > fcp_temp_implausible_above )) && return 0

  (( temp < fcp_temp_floor )) && temp=$fcp_temp_floor
  (( temp > fcp_temp_ceiling )) && temp=$fcp_temp_ceiling
  printf '%s' "$temp"
}

# Echo the first usable binary. Absolute candidates must be executable; bare
# names are looked up in PATH. Returns 1 when none of them is installed.
aux_find_bin() {
  local candidate resolved
  for candidate in "$@"; do
    if [[ "$candidate" == /* ]]; then
      if [[ -x "$candidate" ]]; then
        printf '%s' "$candidate"
        return 0
      fi
    else
      resolved=$(command -v "$candidate" 2>/dev/null)
      if [[ -n "$resolved" ]]; then
        printf '%s' "$resolved"
        return 0
      fi
    fi
  done
  return 1
}

# Locate the external tools the configured sensor list actually needs, so a
# machine without them pays nothing. Call once before reading sensors.
aux_locate_bins() {
  local sensors="${1:-}"

  aux_storcli_bin=""
  aux_nvidia_smi_bin=""
  aux_mget_temp_bin=""

  if [[ "$sensors" == *storcli:* ]]; then
    aux_storcli_bin=$(aux_find_bin \
      /opt/MegaRAID/storcli/storcli64 /opt/MegaRAID/storcli/storcli \
      /usr/local/sbin/storcli64 /usr/local/sbin/storcli \
      /usr/local/bin/storcli64 /usr/local/bin/storcli \
      /usr/sbin/storcli64 /usr/sbin/storcli \
      /usr/bin/storcli64 /usr/bin/storcli \
      storcli64 storcli)
  fi

  if [[ "$sensors" == *nvidia:* ]]; then
    aux_nvidia_smi_bin=$(aux_find_bin \
      /usr/bin/nvidia-smi /usr/local/bin/nvidia-smi /usr/lib/nvidia/bin/nvidia-smi \
      nvidia-smi)
  fi

  if [[ "$sensors" == *mlx:* ]]; then
    aux_mget_temp_bin=$(aux_find_bin \
      /usr/bin/mget_temp /usr/local/bin/mget_temp /usr/bin/mst/mget_temp \
      mget_temp)
  fi
}

# Echo one sensor's temperature in whole degrees Celsius, or nothing when it
# cannot be read. Tokens are matched strictly: a sensor string is user-editable
# config and is passed to an external command.
aux_read_sensor() {
  local sensor="$1" temp="" raw script

  case "$sensor" in
    storcli:*)
      [[ -n "$aux_storcli_bin" && "$sensor" =~ ^storcli:c([0-9]+):roc$ ]] || return 0
      temp=$("$aux_storcli_bin" "/c${BASH_REMATCH[1]}" show temperature 2>/dev/null \
        | awk '/ROC temperature/{print $NF; exit}')
      ;;
    nvidia:*)
      [[ -n "$aux_nvidia_smi_bin" && "$sensor" =~ ^nvidia:gpu([0-9]+)$ ]] || return 0
      temp=$("$aux_nvidia_smi_bin" --query-gpu=temperature.gpu \
        --format=csv,noheader,nounits -i "${BASH_REMATCH[1]}" 2>/dev/null)
      ;;
    mlx:*)
      # mget_temp prints the network chip temperature for one PCI function,
      # either bare ("71") or labelled; take the first number either way.
      [[ -n "$aux_mget_temp_bin" ]] || return 0
      [[ "$sensor" =~ ^mlx:([0-9a-fA-F]{4}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2}\.[0-9a-fA-F])$ ]] || return 0
      # The exit status decides whether there is a reading. These tools print
      # their errors on stdout as well, and those carry digits (the PCI address
      # itself does), so a failed call must never be parsed for a number.
      raw=$("$aux_mget_temp_bin" -d "${BASH_REMATCH[1]}" 2>/dev/null) || return 0
      temp=$(grep -oE -- '-?[0-9]+' <<< "$raw" | head -n 1)
      ;;
    custom:*)
      # The name addresses a file inside the drop-in directory: it is never
      # part of a command line, and anything that could leave that directory
      # is refused outright.
      [[ "$sensor" =~ ^custom:([A-Za-z0-9._-]+)$ ]] || return 0
      script="$fcp_custom_sensor_dir/${BASH_REMATCH[1]}"
      [[ "${BASH_REMATCH[1]}" == .* || ! -x "$script" ]] && return 0
      temp=$(timeout "$fcp_custom_sensor_timeout" "$script" 2>/dev/null) || return 0
      # The contract is the temperature and nothing else, so output that is not
      # a number comes from a broken script rather than a sensor. A fractional
      # reading is truncated to whole degrees.
      [[ "$temp" =~ ^-?[0-9]+(\.[0-9]+)?$ ]] || return 0
      temp="${temp%%.*}"
      ;;
    *)
      # An hwmon input reading exactly 0 is the long-standing marker for a
      # sensor that is present but not populated, not a reading of 0 °C.
      [[ -f "$sensor" ]] || return 0
      raw=$(cat "$sensor" 2>/dev/null)
      [[ "$raw" =~ ^-?[0-9]+$ ]] || return 0
      (( raw == 0 )) && return 0
      temp=$((raw / 1000))
      ;;
  esac

  fcp_clamp_temp "$temp"
  return 0
}

# Echo the hottest reading across the comma-separated sensor list, or nothing
# when none of them could be read. A sensor that reported nothing sits the round
# out; a sensor that reported a cold value still counts.
aux_max_temp_reading() {
  local sensors="$1" sensor temp best=""
  local -a sensor_list

  IFS=',' read -ra sensor_list <<< "$sensors"
  for sensor in "${sensor_list[@]}"; do
    [[ -z "$sensor" ]] && continue
    temp=$(aux_read_sensor "$sensor")
    [[ "$temp" =~ ^[0-9]+$ ]] || continue
    if [[ -z "$best" ]] || (( temp > best )); then
      best=$temp
    fi
  done

  [[ -n "$best" ]] && printf '%s' "$best"
  return 0
}
