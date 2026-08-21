#!/bin/bash
# Sleep-interval derivation: seconds configs, grandfathered minute configs.

set -u

root=$(cd "$(dirname "$0")/.." && pwd)
source "$root/src/usr/local/emhttp/plugins/fanctrlplus2/scripts/disk_group_control.sh"

failures=0
expect_equal() {
  local expected="$1" actual="$2" message="$3"
  if [[ "$expected" == "$actual" ]]; then
    return
  fi
  printf '%s\nExpected: %s\nActual: %s\n' "$message" "$expected" "$actual" >&2
  failures=$((failures + 1))
}

# A seconds config is used verbatim.
interval_sec="30" interval="" \
  actual=$(interval_seconds)
expect_equal "30" "$actual" "interval_sec must be used as-is."

# A pre-seconds config stored minutes: 5 min must keep meaning 5 minutes.
interval_sec="" interval="5" \
  actual=$(interval_seconds)
expect_equal "300" "$actual" "A legacy minute config must be converted to seconds."

# Once saved in the new shape, the seconds value wins over the stale minutes.
interval_sec="45" interval="5" \
  actual=$(interval_seconds)
expect_equal "45" "$actual" "interval_sec must win over a leftover interval."

# Neither key set (brand-new/broken config): the documented default.
interval_sec="" interval="" \
  actual=$(interval_seconds)
expect_equal "120" "$actual" "A config without any interval must fall back to 120s."

# Junk in either key must not produce a busy loop or an arithmetic error.
interval_sec="abc" interval="" \
  actual=$(interval_seconds)
expect_equal "120" "$actual" "A non-numeric interval_sec must fall back to the default."

interval_sec="0" interval="0" \
  actual=$(interval_seconds)
expect_equal "120" "$actual" "A zero interval must fall back to the default."

# The floor protects the loop: each tick already sleeps 4s to read back RPM.
interval_sec="1" interval="" \
  actual=$(interval_seconds)
expect_equal "5" "$actual" "Below-floor values must be clamped to 5s."

if (( failures > 0 )); then
  printf '%d interval assertion(s) failed.\n' "$failures" >&2
  exit 1
fi
printf 'interval_seconds tests passed.\n'
