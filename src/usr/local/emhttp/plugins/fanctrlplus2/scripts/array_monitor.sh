#!/bin/bash

plugin="fanctrlplus2"
LOG="/var/log/fanctrlplus2_array_watch.log"
CHECK_INTERVAL=10
rc_script="/etc/rc.d/rc.${plugin}"
pidfile="/var/run/fanctrlplus2.user_stopped"
# Shared with the settings page; a configuration restore holds it while it
# swaps files.
config_lock="/boot/config/plugins/${plugin}/.config.lock"

last_md_state=""
last_fanctrl_state=0

log() {
  echo "[fanctrlplus2] $(date +'%Y-%m-%d %H:%M:%S') $1" >> "$LOG"
}

check_array_started() {
  local state
  state=$(grep -oP 'mdState=\K\w+' /proc/mdstat 2>/dev/null || true)
  [[ "$state" == "STARTED" ]]
}

is_fanctrl_running() {
  pgrep -f fanctrlplus2_loop.sh | grep -vq "$$"
}

log "Array monitor started"

while true; do
  current_md_state=$(grep -oP 'mdState=\K\w+' < <(/usr/local/sbin/mdcmd status 2>/dev/null) || echo "")

  if [[ "$current_md_state" != "$last_md_state" ]]; then
    log "Array state changed: $last_md_state → $current_md_state"
    last_md_state="$current_md_state"
  fi

  if [[ "$current_md_state" == "STARTED" ]]; then
    # Decide and start under the settings lock. fd 9 is closed for rc so the
    # fan loops it spawns do not keep the lock held.
    (
      flock 9 || exit 0
      if ! is_fanctrl_running && [[ ! -f "$pidfile" ]]; then
        log "FanCtrlPlus not running after array start → launching"
        "$rc_script" start 9>&-
      fi
    ) 9>>"$config_lock" # append: '>' would rewrite the file on the flash drive every pass
  fi

  sleep "$CHECK_INTERVAL"
done
