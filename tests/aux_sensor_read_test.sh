#!/bin/bash
# Aux-sensor reading shared by the control loop and the manual run, including
# the Mellanox NIC sensor read through mget_temp.

set -u

root=$(cd "$(dirname "$0")/.." && pwd)
source "$root/src/usr/local/emhttp/plugins/fanctrlplus2/scripts/aux_sensors.sh"

failures=0
expect_equal() {
  local expected="$1" actual="$2" message="$3"
  if [[ "$expected" == "$actual" ]]; then
    return
  fi
  printf '%s\nExpected: %s\nActual: %s\n' "$message" "$expected" "$actual" >&2
  failures=$((failures + 1))
}

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

# ===== Stub binaries: they record their arguments and print real tool output.
cat > "$tmp/mget_temp" <<'STUB'
#!/bin/bash
printf '%s\n' "$*" > "$MGET_ARGS_FILE"
printf '71\n'
STUB
cat > "$tmp/storcli64" <<'STUB'
#!/bin/bash
printf '%s\n' "$*" > "$STORCLI_ARGS_FILE"
printf 'ROC temperature(Degree Celsius) 68\n'
STUB
cat > "$tmp/nvidia-smi" <<'STUB'
#!/bin/bash
printf '55\n'
STUB
chmod +x "$tmp/mget_temp" "$tmp/storcli64" "$tmp/nvidia-smi"

export MGET_ARGS_FILE="$tmp/mget.args"
export STORCLI_ARGS_FILE="$tmp/storcli.args"

# ===== aux_find_bin =====
expect_equal "$tmp/mget_temp" "$(aux_find_bin "$tmp/missing" "$tmp/mget_temp")" \
  "An absolute candidate must be picked once it is executable."

PATH="$tmp:$PATH" actual=$(PATH="$tmp:$PATH" aux_find_bin /nonexistent/mget_temp mget_temp)
expect_equal "$tmp/mget_temp" "$actual" \
  "A bare name must resolve through PATH when no absolute candidate exists."

aux_find_bin /nonexistent/one /nonexistent/two > /dev/null
expect_equal "1" "$?" "aux_find_bin must report failure when nothing is found."

# ===== hwmon file sensors (unchanged behaviour) =====
printf '42000\n' > "$tmp/temp1_input"
expect_equal "42" "$(aux_read_sensor "$tmp/temp1_input")" \
  "An hwmon input must be reported in whole degrees."

printf 'nonsense\n' > "$tmp/temp2_input"
expect_equal "" "$(aux_read_sensor "$tmp/temp2_input")" \
  "A non-numeric hwmon input must yield no reading."

expect_equal "" "$(aux_read_sensor "$tmp/does_not_exist")" \
  "A missing hwmon input must yield no reading."

# ===== storcli =====
aux_storcli_bin="$tmp/storcli64"
aux_nvidia_smi_bin="$tmp/nvidia-smi"
aux_mget_temp_bin="$tmp/mget_temp"

expect_equal "68" "$(aux_read_sensor "storcli:c0:roc")" \
  "The ROC temperature must be read from storcli output."
expect_equal "/c0 show temperature" "$(cat "$STORCLI_ARGS_FILE")" \
  "storcli must be called for the controller named in the sensor token."

expect_equal "" "$(aux_read_sensor "storcli:c0:bogus")" \
  "A malformed storcli token must be rejected."

# ===== nvidia =====
expect_equal "55" "$(aux_read_sensor "nvidia:gpu0")" \
  "The GPU temperature must be read from nvidia-smi output."
expect_equal "" "$(aux_read_sensor "nvidia:gpuX")" \
  "A malformed nvidia token must be rejected."

# ===== Mellanox (issue #1) =====
expect_equal "71" "$(aux_read_sensor "mlx:0000:77:00.0")" \
  "The NIC temperature must be read from mget_temp output."
expect_equal "-d 0000:77:00.0" "$(cat "$MGET_ARGS_FILE")" \
  "mget_temp must be called with the PCI address from the sensor token."

expect_equal "" "$(aux_read_sensor "mlx:; rm -rf /")" \
  "A sensor token that is not a PCI address must never reach mget_temp."
expect_equal "" "$(aux_read_sensor "mlx:77:00.0")" \
  "A PCI address without a domain must be rejected."

aux_mget_temp_bin=""
expect_equal "" "$(aux_read_sensor "mlx:0000:77:00.0")" \
  "Without mget_temp installed the sensor must yield no reading."
aux_mget_temp_bin="$tmp/mget_temp"

# mget_temp builds that label their output must still parse.
cat > "$tmp/mget_temp" <<'STUB'
#!/bin/bash
printf '%s\n' "$*" > "$MGET_ARGS_FILE"
printf 'Temperature: 63 C\n'
STUB
chmod +x "$tmp/mget_temp"
expect_equal "63" "$(aux_read_sensor "mlx:0000:77:00.0")" \
  "A labelled mget_temp reading must still yield the temperature."

# A failing read (no MFT driver loaded) must not be reported as 0°C.
cat > "$tmp/mget_temp" <<'STUB'
#!/bin/bash
printf 'Failed to open device\n' >&2
exit 1
STUB
chmod +x "$tmp/mget_temp"
expect_equal "" "$(aux_read_sensor "mlx:0000:77:00.0")" \
  "A failed mget_temp call must yield no reading."

# ===== aux_max_temp_reading =====
printf '50000\n' > "$tmp/temp3_input"
cat > "$tmp/mget_temp" <<'STUB'
#!/bin/bash
printf '71\n'
STUB
chmod +x "$tmp/mget_temp"

expect_equal "71" "$(aux_max_temp_reading "$tmp/temp3_input,mlx:0000:77:00.0,storcli:c0:roc")" \
  "The hottest configured aux sensor must win."
expect_equal "50" "$(aux_max_temp_reading "$tmp/temp3_input,$tmp/does_not_exist,")" \
  "Unreadable sensors and empty entries must be skipped, not counted as 0."
expect_equal "" "$(aux_max_temp_reading "$tmp/does_not_exist")" \
  "No readable sensor must yield no reading at all."

if (( failures > 0 )); then
  printf '%d aux sensor assertion(s) failed.\n' "$failures" >&2
  exit 1
fi
printf 'aux sensor tests passed.\n'
