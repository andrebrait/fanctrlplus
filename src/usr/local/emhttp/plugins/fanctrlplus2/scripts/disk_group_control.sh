#!/bin/bash

# shellcheck disable=SC2034,SC2154

# Sourced alongside aux_sensors.sh, whose fcp_clamp_temp is used below.

# Curve evaluation for every source. Loaded beside this file, so the loop,
# the manual run and the tests reach it the same way.
source "$(dirname "${BASH_SOURCE[0]}")/curve_control.sh"

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

# Seconds one smartctl call may take before it is stopped (and killed two
# seconds later if it ignores that). A drive that stalls has no reading this
# round, as a spun-down one has none.
fcp_smart_timeout="${fcp_smart_timeout:-10}"

# Whole degrees Celsius of one drive, or nothing. One smartctl call does it
# all: -n standby leaves a sleeping drive asleep (it prints "Device is in
# STANDBY" instead of the attributes) and on a drive that is awake prints what
# a plain -A would. The exit status is not looked at: smartctl sets bits for a
# failing disk or errors in its log while the attributes are still good, and a
# call stopped by the timeout leaves no temperature to read.
disk_temp_read() {
  local real_path="$1" is_nvme=0 output
  [[ "$real_path" == /dev/nvme* ]] && is_nvme=1
  output=$(timeout -k 2 "$fcp_smart_timeout" smartctl -n standby -A "$real_path")
  [[ "$output" == *"Device is in STANDBY"* ]] && return 0
  disk_temp_from_smart "$output" "$is_nvme"
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

    temp=$(disk_temp_read "$real_path")

    if [[ "$temp" =~ ^[0-9]+$ ]]; then
      (( found == 0 || temp > max_valid )) && max_valid=$temp
      found=1
    fi
  done
  (( found == 1 )) && echo "$max_valid"
}

# Each disk group's hottest valid temperature (the legacy disk list is group
# 0), keyed by group index, with nothing for a group that gave no reading, and
# the time each group's read began (fcp_stamp_now, taken before its drives are
# read) in fcp_disk_stamps, which the curve state orders the sample by.
# Slow: it reads the drives. Call it before taking the curve-state lock, then
# hand the result to calculate_disk_pwm.
declare -gA fcp_disk_temps=() fcp_disk_stamps=()

sample_disk_temps() {
  local g dvar g_disks
  fcp_disk_temps=()
  fcp_disk_stamps=()
  if [[ "${disk_group_count:-0}" -gt 0 ]]; then
    for ((g=0; g<disk_group_count; g++)); do
      dvar="disk_group_${g}_disks"
      g_disks="${!dvar:-}"
      [[ -z "$g_disks" ]] && continue
      fcp_stamp_now
      fcp_disk_stamps[$g]=$fcp_now
      fcp_disk_temps[$g]=$(disk_group_max_temp "$g_disks")
    done
  elif [[ -n "${disks:-}" ]]; then
    fcp_stamp_now
    fcp_disk_stamps[0]=$fcp_now
    fcp_disk_temps[0]=$(disk_group_max_temp "$disks")
  fi
}

# The disk PWM from the temperatures sample_disk_temps read. Quick, and runs
# the curve state, so it belongs inside the lock.
calculate_disk_pwm() {
  disk_pwm_val=0
  disk_max="*"
  disk_group_name=""
  disk_group_index=""
  disk_curve_readings=()

  if [[ "${disk_group_count:-0}" -gt 0 ]]; then
    local g dvar nvar lvar hvar g_disks g_name g_low g_high
    local g_temp g_pwm
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
      g_temp="${fcp_disk_temps[$g]:-}"
      [[ -z "$g_temp" ]] && continue

      fcp_curve_pwm "disk_group_${g}" "$g_temp" "$g_low" "$g_high" "${fcp_disk_stamps[$g]:-}"
      g_pwm=$curve_pwm
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
    local disk_max_valid
    disk_max_valid="${fcp_disk_temps[0]:-}"
    if [[ -n "$disk_max_valid" ]]; then
      disk_max=$disk_max_valid

      # The legacy range has no curve fields of its own: this is its straight ramp.
      fcp_curve_pwm disk "$disk_max" "${low:-40}" "${high:-60}" "${fcp_disk_stamps[0]:-}"
      disk_pwm_val=$curve_pwm
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

# Puts the fan's controller (the cfg's controller, and its _enable file when
# there is one) in manual mode and writes the PWM value $1. Succeeds only when
# the PWM write did, so a caller records an output as applied only then. The
# mode write stays best effort, as it always was.
fcp_write_pwm() {
  [[ -f "$controller_enable" ]] && echo 1 > "$controller_enable"
  echo "$1" > "$controller"
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
# changes the PWM already sleeps 4s to read the resulting RPM back; the ceiling
# is the one the settings page enforces, so a hand-edited config cannot put the
# loop outside the supported range.
fcp_interval_min_seconds=5
fcp_interval_max_seconds=3600
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
  (( secs > fcp_interval_max_seconds )) && secs=$fcp_interval_max_seconds
  printf '%s' "$secs"
}

# ===== MSI nct6687 stuck-fan check (#13) =====
# On MSI boards in the driver's msi_alt1 layout, system-fan channels (pwm3 and
# up) ignore speed changes unless the msi_fan_brute_force option is set, and
# stay at about 60%. Reading pwmN back returns the chip's actual output, so
# fcp_check_fan catches a fan driven above 60% that keeps reading back lower. Drivers
# that expose fan_control_watchdog run with the option set and are skipped.
fcp_nct6687_module="${fcp_nct6687_module:-/sys/module/nct6687}"

fcp_nct6687_msi_channel() {
  local controller="$1" chip
  [[ "$controller" =~ /pwm([0-9]+)$ ]] && (( BASH_REMATCH[1] >= 3 )) || return 1
  chip=$(cat "$(dirname "$controller")/name" 2>/dev/null)
  [[ "$chip" =~ ^nct668[367]$ ]] || return 1
  [[ ! -e "$(dirname "$controller")/fan_control_watchdog" ]] || return 1
  [[ "$(cat "$fcp_nct6687_module/parameters/fan_config" 2>/dev/null)" == "msi_alt1" ]]
}

# A PWM value as a whole percentage, rounded the way the dashboard shows it.
fcp_pwm_percent() {
  echo $(( ($1 * 100 + 127) / 255 ))
}

# ===== Fan problem alerts =====
# Problems with one fan never stop its loop or any other fan's: they only
# raise Unraid notifications, which the fan's "Problem Alerts" switch
# (notify=0 in its cfg) silences. The switch is read the way the loop reads
# its cfg, when a notification is due, so flipping it needs no restart.
fcp_notify_bin="${fcp_notify_bin:-/usr/local/emhttp/webGui/scripts/notify}"

fcp_alerts_enabled() {
  (unset notify; source "${cfg_file:-}" >/dev/null 2>&1; [[ "${notify:-1}" != 0 ]])
}

# Sends an Unraid notification (notify's -i, -s and -d options). A hung
# notify must not stall fan control.
fcp_notify() {
  timeout 30 "$fcp_notify_bin" -e "FanCtrl Plus 2" -l "/Settings/fanctrlplus2" "$@"
}

# Seconds a problem must last before it warns, and must stay gone before it
# counts as recovered: longer than a fan takes to spin up or a chip takes to
# apply a new speed, and long enough that a flapping problem does not spam.
fcp_fault_grace_seconds=30
# Set above this share of full speed, a fan that spun before should spin. The
# default Min Speed is 40%, where some fans stop by design.
fcp_stopped_min_percent=40
fcp_controller_subject="has lost its PWM controller"
fcp_faults=()
fcp_ok=()

# shortcut: a directional RPM change is suggestive, not proof of control.
fcp_response_pending=''
fcp_response_start() {
  local old="$1" new="$2" rpm="$3" now="$4"
  fcp_response_pending=''
  [[ "$old" =~ ^[0-9]+$ && "$new" =~ ^[0-9]+$ && "$rpm" =~ ^[0-9]+$ ]] || return
  (( rpm > 0 && (new - old >= 51 || old - new >= 51) )) || return
  fcp_response_old=$old fcp_response_pending=$new fcp_response_baseline=$rpm fcp_response_time=$now
}

# Whether pwmN_enable mode $1 leaves the fan at the speed we last wrote ($3).
# Mode 0 means full speed, and nct6775-core reports manual output at 255 that
# way, so it is no loss of control while 255 is our target and the readback.
# Leaving 255 writes mode 1 again; a driver refusing that still warns.
fcp_manual_mode() {
  [[ "$1" == 1 ]] || [[ "$1" == 0 && "$3" == 255 && "$(cat "$2" 2>/dev/null)" == 255 ]]
}

# Checks one fan, set to target (the PWM value last written, or empty before
# any write succeeded; nct is 1 for a channel fcp_nct6687_msi_channel matched).
# Sets fcp_faults to this round's problems as "key|subject|details" entries,
# and fcp_ok to the keys seen to be fine. A key in neither could not be checked
# this round. The fan's flag directory remembers that it has a tachometer,
# across loop restarts.
fcp_check_fan() {
  local controller="$1" fan_input="$2" target="$3" nct="$4" dir="$5" mode actual rpm want got
  fcp_faults=()
  fcp_ok=()
  if [[ ! -e "$controller" ]]; then
    fcp_faults+=("controller|$fcp_controller_subject|${controller:-Its controller} no longer exists, so the fan speed cannot be set. The controller may have been unplugged or renumbered, or its driver unloaded. Check the controller on the FanCtrl Plus 2 settings page.")
    return
  fi
  fcp_ok+=(controller)
  # Something else drives the fan, so its speed and RPM say nothing about us.
  # An enable attribute that exists but cannot be read leaves all this unchecked.
  if [[ -e "${controller}_enable" ]]; then
    mode=$(cat "${controller}_enable" 2>/dev/null)
    [[ -n "$mode" ]] || return
    if ! fcp_manual_mode "$mode" "$controller" "$target"; then
      fcp_response_pending=''
      fcp_faults+=("mode|is no longer under FanCtrl Plus 2 control|${controller}_enable changed from manual (1) to $mode, so the BIOS or another program sets the fan speed. FanCtrl Plus 2 takes the fan back at its next speed change.")
      return
    fi
  fi
  fcp_ok+=(mode)
  # Speed and stopped compare against a written target; there is none yet.
  [[ -n "$target" ]] || return
  [[ "${fcp_response_pending:-}" == "$target" ]] || fcp_response_pending=''
  want=$(fcp_pwm_percent "$target")
  # Most drivers read back the value last written; a chip that ignores a
  # write, or a write that failed, reads back something else.
  actual=$(cat "$controller" 2>/dev/null)
  if [[ "$actual" =~ ^[0-9]+$ ]]; then
    got=$(fcp_pwm_percent "$actual")
    if (( want - got >= 10 || got - want >= 10 )); then
      if (( nct && want > 60 && got <= 60 )); then
        fcp_faults+=("speed|is not reaching its set speed|Set to $want% but the nct6687 controller reports $got%. On MSI boards this driver ignores system-fan speed changes unless its msi_fan_brute_force option is set. See the FanCtrl Plus 2 settings page.")
      else
        fcp_faults+=("speed|is not reaching its set speed|Set to $want% but the controller reports $got%. The controller may be ignoring speed changes, or another program may be setting it.")
      fi
    else
      fcp_ok+=(speed)
    fi
  fi
  # A fan without a tachometer always reads 0, so only a fan seen spinning on
  # this controller (.spun names it) can count as stopped; at a low speed it
  # may have stopped by design.
  rpm=$(cat "$fan_input" 2>/dev/null)
  [[ "$rpm" =~ ^[0-9]+$ ]] || return
  if [[ ${fcp_response_pending:-} == "$target" && ${#fcp_faults[@]} -gt 0 ]] &&
     (( SECONDS - fcp_response_time >= 30 && rpm > 0 )); then
    local delta=$((rpm - fcp_response_baseline))
    if (( (target > fcp_response_old && delta >= 100 && delta * 5 >= fcp_response_baseline) ||
          (target < fcp_response_old && delta <= -100 && -delta * 5 >= fcp_response_baseline) )); then
      fcp_faults[${#fcp_faults[@]}-1]+=" RPM changed from ${fcp_response_baseline} to ${rpm} in the requested direction, suggesting the fan may have responded despite the PWM readback."
    fi
  fi
  if (( rpm > 0 )); then
    [[ "$(cat "$dir/.spun" 2>/dev/null)" == "$controller" ]] ||
      printf '%s' "$controller" > "$dir/.spun" 2>/dev/null
    fcp_ok+=(stopped)
  elif [[ "$(cat "$dir/.spun" 2>/dev/null)" == "$controller" ]] && (( want > fcp_stopped_min_percent )); then
    fcp_faults+=("stopped|has stopped|Set to $want% but the fan reports 0 RPM, although it was spinning earlier. It may have failed, stalled or been unplugged.")
  fi
}

# Problem key -> when the current stretch of it being bad, or being fine, began.
declare -gA fcp_fault_since=() fcp_clear_since=()
fcp_fault_target=''

# Records fcp_check_fan's results for a fan. A problem lasting the grace
# period sends one warning; once it has been fine for the grace period, one
# notice says it recovered. A key that could not be checked restarts both
# clocks. A warned problem's flag file holds its subject, so a loop restart
# neither repeats the warning nor loses the recovery notice. The flag is
# written only once the warning is sent, so a failed or interrupted warning
# is tried again.
fcp_track_faults() {
  local now="$1" name="$2" dir="$3" fault key subject details flag
  local -A bad=() ok=()
  if [[ "${4:-}" != "$fcp_fault_target" ]]; then
    unset 'fcp_fault_since[speed]'
    fcp_fault_target="${4:-}"
  fi
  for key in "${fcp_ok[@]}"; do
    ok[$key]=1
    : "${fcp_clear_since[$key]:=$now}"
  done
  for fault in "${fcp_faults[@]}"; do
    IFS='|' read -r key subject details <<< "$fault"
    bad[$key]=1
    : "${fcp_fault_since[$key]:=$now}"
    (( now - fcp_fault_since[$key] >= fcp_fault_grace_seconds )) || continue
    flag="$dir/$key"
    [[ -e "$flag" ]] && continue
    fcp_alerts_enabled || continue
    # Without a writable flag, every round would warn again.
    [[ -d "$dir" && -w "$dir" ]] || continue
    fcp_notify -i warning -s "Fan $name $subject" -d "$details" &&
      printf '%s' "$subject" > "$flag" 2>/dev/null
  done
  for key in "${!fcp_fault_since[@]}"; do
    [[ -n "${bad[$key]:-}" ]] || unset 'fcp_fault_since[$key]'
  done
  for key in "${!fcp_clear_since[@]}"; do
    [[ -n "${ok[$key]:-}" ]] || unset 'fcp_clear_since[$key]'
  done
  for flag in "$dir"/*; do
    key=${flag##*/}
    [[ -f "$flag" && -n "${ok[$key]:-}" ]] || continue
    (( now - fcp_clear_since[$key] >= fcp_fault_grace_seconds )) || continue
    # Alerts switched off: drop the incident without a notice.
    if fcp_alerts_enabled; then
      fcp_notify -i normal -s "Fan $name has recovered" \
        -d "This earlier warning has cleared: Fan $name $(cat "$flag")." || continue
    fi
    rm -f "$flag"
  done
}

# Warns once that a fan's loop could not start because its controller is
# missing. The flag directory is the loop's own, so the loop announces the
# recovery once the controller is back and the fan is started again.
fcp_warn_not_started() {
  local cfg_file="$1" name="$2" controller="$3" dir="$4"
  mkdir -p "$dir" 2>/dev/null
  [[ -e "$dir/controller" ]] && return 0
  fcp_alerts_enabled || return 0
  [[ -d "$dir" && -w "$dir" ]] || return 0
  fcp_notify -i warning -s "Fan $name $fcp_controller_subject" \
    -d "${controller:-Its PWM controller} is missing or cannot be written, so FanCtrl Plus 2 did not start controlling this fan. The controller may be unplugged, renumbered or ambiguous, or its driver not loaded. Check the controller on the FanCtrl Plus 2 settings page, then apply." &&
    printf '%s' "$fcp_controller_subject" > "$dir/controller" 2>/dev/null
}

# ===== Configured sensors without a reading (#21) =====
# A sensor that gives no reading cannot drive the fan curve, so a fan whose
# only source is missing stays at Idle Speed (or Min Speed for an unreadable
# CPU file, which counts as 0 °C) and looks like a cool machine.
# Seconds a sensor may stay unread before a warning: long enough for a tool
# that the boot installs after the loop starts.
fcp_missing_grace_seconds=60

# Sensor -> when it was first unread in its current stretch. Each sensor has
# its own clock, so sensors failing on alternate rounds never add up to a
# warning none of them earned.
declare -gA fcp_missing_since=()
fcp_missing_overdue=()

# Records this round's unread sensors (the arguments after the time), forgets
# any that read again, and sets fcp_missing_overdue to those unread for the
# whole grace period. Call it in the loop's own shell, not in $(...): its
# state lives in these globals.
fcp_track_missing() {
  local now="$1" sensor start
  shift
  local -A since=()
  fcp_missing_overdue=()
  for sensor in "$@"; do
    start=${fcp_missing_since[$sensor]:-$now}
    since[$sensor]=$start
    (( now - start >= fcp_missing_grace_seconds )) && fcp_missing_overdue+=("$sensor")
  done
  fcp_missing_since=()
  for sensor in "${!since[@]}"; do
    fcp_missing_since[$sensor]=${since[$sensor]}
  done
}

# One Unraid notification per fan per boot, naming the sensors given after
# the flag. The flag lives on tmpfs; without one every tick would notify
# again, so no flag means no notification. A failed notification drops the
# flag, so the next tick tries again. A sensor that keeps dropping in and out
# warns only once.
fcp_notify_missing() {
  local name="$1" flag="$2" sensors
  shift 2
  printf -v sensors '%s, ' "$@"
  [[ -e "$flag" ]] && return 0
  fcp_alerts_enabled || return 0
  touch "$flag" 2>/dev/null || return 0
  fcp_notify -i warning -s "Fan $name has no reading from a configured sensor" \
    -d "No reading for at least $fcp_missing_grace_seconds seconds from: ${sensors%, }. The fan cannot react to these sensors while they are unread, and stays at its Idle or Min Speed when no other source has a reading." \
    || rm -f "$flag"
}
