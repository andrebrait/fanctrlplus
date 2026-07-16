#!/bin/bash

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

# ===== history_pick_source =====

# The previous driver keeps the attribution on a PWM tie: HDDs (disk:0) drove
# at 31°C, cooled to 30°C where SSDs (disk:1) now produce the same PWM.
actual=$(history_pick_source "disk:0" "disk:1" 127 "disk:0|30|127" "disk:1|40|127")
expect_equal "disk:0|30" "$actual" "A tie must keep the previous driving source."

# A strictly higher PWM takes over regardless of the previous driver.
actual=$(history_pick_source "disk:0" "disk:1" 160 "disk:0|30|127" "disk:1|48|160")
expect_equal "disk:1|48" "$actual" "A higher PWM must displace the previous source."

# The previous driver disappeared (e.g. spun down): the winner is used.
actual=$(history_pick_source "disk:0" "cpu" 140 "cpu|55|140" "disk:1|35|110")
expect_equal "cpu|55" "$actual" "A vanished previous source must not stick."

# No previous driver (first tick): the winner is used.
actual=$(history_pick_source "" "aux" 130 "aux|47|130" "cpu|40|120")
expect_equal "aux|47" "$actual" "The first tick must record the winner."

# Winner missing from the candidates (no valid temp): empty temperature.
actual=$(history_pick_source "" "disk:0" 51)
expect_equal "disk:0|" "$actual" "A winner without a reading must yield an empty temp."

# The previous source must match at the WINNING PWM, not merely exist.
actual=$(history_pick_source "cpu" "disk:1" 200 "cpu|41|120" "disk:1|52|200")
expect_equal "disk:1|52" "$actual" "A lower previous source must not steal the attribution."

# ===== history_source_label =====

disk_group_count=2
disk_group_0_name="HDDs"
disk_group_1_name="SSDs (2.5)"
expect_equal "CPU" "$(history_source_label cpu)" "CPU label."
expect_equal "Aux" "$(history_source_label aux)" "Aux label."
expect_equal "Idle" "$(history_source_label idle)" "Idle label."
expect_equal "Disk: HDDs" "$(history_source_label disk:0)" "Named group label."
expect_equal "Disk: SSDs (2.5]" "$(history_source_label disk:1)" "Group label must escape closing parens."
disk_group_1_name=""
expect_equal "Disk: Group 2" "$(history_source_label disk:1)" "Unnamed group label must fall back to its number."
disk_group_count=0
expect_equal "Disk" "$(history_source_label disk:0)" "Legacy single-range label."
pipe_name="A|B"
disk_group_count=1
disk_group_0_name="$pipe_name"
expect_equal "Disk: A/B" "$(history_source_label disk:0)" "Group label must not contain the field separator."

# ===== history_append =====

hist_file=$(mktemp)
history_append "$hist_file" 1000 "disk:0" "Disk: HDDs" 43 127
expect_equal "1000|disk:0|Disk: HDDs|43|127" "$(< "$hist_file")" "History lines must be epoch|src|label|temp|pwm."

history_append "$hist_file" 1060 "idle" "Idle" "" 51
expect_equal "1060|idle|Idle||51" "$(tail -n 1 "$hist_file")" "An idle tick must record an empty temperature."

# The file is trimmed to the retention cap.
fcp_history_max_lines=3
history_append "$hist_file" 1120 "cpu" "CPU" 50 140
history_append "$hist_file" 1180 "cpu" "CPU" 51 142
expect_equal 3 "$(wc -l < "$hist_file" | tr -d ' ')" "History must be trimmed to the retention cap."
expect_equal "1060|idle|Idle||51" "$(head -n 1 "$hist_file")" "Trimming must drop the oldest lines first."
rm -f "$hist_file"

if (( failures > 0 )); then
  exit 1
fi

echo "fan history tests passed"
