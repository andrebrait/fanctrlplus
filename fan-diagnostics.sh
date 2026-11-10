#!/bin/sh
# Read-only hardware snapshot for FanCtrl Plus support. Run with: curl -fsSL URL | sh

printf '%s\n' 'FanCtrl Plus hardware diagnostics' 'No changes were made. Review the output before sharing it.'
printf '\nKernel: '; uname -r
for name in board_vendor board_name product_name bios_version; do
  file="/sys/class/dmi/id/$name"
  if [ -r "$file" ]; then printf '%s: ' "$name"; cat "$file"; fi
done
for file in /var/log/plugins/fanctrlplus2.plg /boot/config/plugins/fanctrlplus2.plg; do
  if [ -r "$file" ]; then
    printf '\nPlugin version: '
    grep -m 1 '^[[:space:]]*version=' "$file" || :
    break
  fi
done
printf '\nnct6687 driver parameters:\n'
for name in fan_config msi_fan_brute_force; do
  file="/sys/module/nct6687/parameters/$name"
  if [ -r "$file" ]; then printf '%s=' "$name"; cat "$file"; fi
done

printf '\nFan configurations (anonymous controller and alert settings):\n'
config_number=0
for file in /boot/config/plugins/fanctrlplus2/fanctrlplus2_*.cfg; do
  [ -f "$file" ] || continue
  config_number=$((config_number + 1))
  printf 'Fan %s\n' "$config_number"
  awk -F= '$1 ~ /^(interval_sec|notify)$/ {print}' "$file"
  controller=$(awk -F= '$1 == "controller" {gsub(/^"|"$/, "", $2); print $2; exit}' "$file")
  resolved=$(readlink -f "$controller" 2>/dev/null)
  matched=0
  for candidate in /sys/class/hwmon/hwmon*/pwm*; do
    [ -n "$resolved" ] && [ "$(readlink -f "$candidate" 2>/dev/null)" = "$resolved" ] || continue
    printf 'controller=%s\n' "$candidate"
    matched=1
    break
  done
  [ "$matched" = 1 ] || printf 'controller=unresolved\n'
  printf '\n'
done

printf '\nHardware monitors: PWM values are 0–255; fan inputs are RPM.\n'
for sample in 1 2 3; do
  printf '\nSample %s (UTC ' "$sample"; date -u '+%H:%M:%S)'
  for dir in /sys/class/hwmon/hwmon*; do
    [ -r "$dir/name" ] || continue
    printf '%s name=' "$dir"; cat "$dir/name"
    for file in "$dir"/pwm* "$dir"/fan*_input; do
      [ -r "$file" ] || continue
      name=${file##*/}
      case "$name" in
        pwm*) suffix=${name#pwm}; suffix=${suffix%_enable}; case "$suffix" in ''|*[!0-9]*) continue;; esac ;;
        fan*_input) suffix=${name#fan}; suffix=${suffix%_input}; case "$suffix" in ''|*[!0-9]*) continue;; esac ;;
        *) continue ;;
      esac
      printf '  %s=' "$name"; cat "$file"
    done
  done
  [ "$sample" = 3 ] || sleep 5
done
