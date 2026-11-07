#!/bin/bash

# shellcheck disable=SC2034,SC2154

# Fan curves for one source: cpu, aux, disk_group_N, or the legacy disk range.
# fcp_curve_pwm gives the raw PWM for a reading. The loop and the manual run
# both evaluate through it, so they agree.
#
# A source with no PREFIX_curve keeps the legacy straight ramp between LOW and
# HIGH, with the integer math it always had. A source with PREFIX_curve follows
# its own fields:
#   PREFIX_curve           "T:P,T:P,..." 2-8 points; temperatures strictly
#                          increasing 0-300, raw PWM 0-255 nondecreasing
#   PREFIX_curve_mode      "linear" (default) or "stepped"
#   PREFIX_hysteresis      0-20 (default 0)
#   PREFIX_emergency_temp  unset or "" disables; 0-300 enables
#   PREFIX_emergency_pwm   0-255 (default 255), at least the last curve PWM
# Any field that does not validate fails hot: the source reads 255.

# Per-source state for a configured curve, keyed by PREFIX: the effective
# temperature the curve follows, the emergency latch, and the fingerprint of the
# rule that produced them. A rule with a different fingerprint is new, so an
# edited curve never inherits the old state.
declare -gA fcp_curve_eff=() fcp_curve_latch=() fcp_curve_fp=()

# The result of fcp_curve_pwm. A global, not a return value: the state above
# must persist between calls, and a $(...) call would throw it away.
curve_pwm=0

# The parsed points and the validated fields of the rule being evaluated.
fcp_points_temp=()
fcp_points_pwm=()
fcp_rule_mode=linear
fcp_rule_hyst=0
fcp_rule_em_on=0
fcp_rule_em_temp=""
fcp_rule_em_pwm=255

# Legacy straight ramp. LOW and HIGH are the source's temperature bounds; pwm
# and max are the PWM range. The arithmetic matches the pre-curve code exactly.
fcp_curve_straight() {
  local temp="$1" low="$2" high="$3"
  if [[ ! "${pwm:-}" =~ ^(0|[1-9][0-9]*)$ || ! "${max:-}" =~ ^(0|[1-9][0-9]*)$ ||
        ! "$low" =~ ^-?(0|[1-9][0-9]*)$ || ! "$high" =~ ^-?(0|[1-9][0-9]*)$ ]]; then
    curve_pwm=255
  elif (( temp <= low )); then
    curve_pwm=$pwm
  elif (( temp >= high )); then
    curve_pwm=$max
  else
    curve_pwm=$(( pwm + (temp - low) * (max - pwm) / (high - low) ))
  fi
  return 0
}

# Validates a PREFIX_curve string and parses it into fcp_points_temp and
# fcp_points_pwm. The pattern admits only canonical digits and separators, so
# nothing that reaches arithmetic can be read as code.
fcp_curve_parse() {
  local curve="$1" point t p prev_t=-1 prev_p=-1
  local -a points=()
  [[ "$curve" =~ ^(0|[1-9][0-9]{0,2}):(0|[1-9][0-9]{0,2})(,(0|[1-9][0-9]{0,2}):(0|[1-9][0-9]{0,2})){1,7}$ ]] || return 1
  IFS=',' read -ra points <<< "$curve"
  fcp_points_temp=()
  fcp_points_pwm=()
  for point in "${points[@]}"; do
    t=${point%%:*}
    p=${point##*:}
    (( t <= 300 && p <= 255 && t > prev_t && p >= prev_p )) || return 1
    fcp_points_temp+=("$t")
    fcp_points_pwm+=("$p")
    prev_t=$t
    prev_p=$p
  done
  return 0
}

# Reads and validates PREFIX's curve fields into the fcp_rule_* and
# fcp_points_* globals. Returns 1 when any field is malformed. An unset optional
# field takes its default; a field that is set must be valid.
fcp_curve_rule() {
  local prefix="$1" n
  local curve_var="${1}_curve" mode_var="${1}_curve_mode" hyst_var="${1}_hysteresis"
  local em_temp_var="${1}_emergency_temp" em_pwm_var="${1}_emergency_pwm"

  fcp_curve_parse "${!curve_var}" || return 1
  n=${#fcp_points_pwm[@]}

  fcp_rule_mode=${!mode_var-linear}
  fcp_rule_hyst=${!hyst_var-0}
  fcp_rule_em_temp=${!em_temp_var-}
  fcp_rule_em_pwm=${!em_pwm_var-255}
  fcp_rule_em_on=0

  case "$fcp_rule_mode" in
    linear|stepped) ;;
    *) return 1 ;;
  esac
  [[ "$fcp_rule_hyst" =~ ^(0|[1-9][0-9]?)$ ]] && (( fcp_rule_hyst <= 20 )) || return 1

  if [[ -n "$fcp_rule_em_temp" ]]; then
    fcp_rule_em_on=1
    [[ "$fcp_rule_em_temp" =~ ^(0|[1-9][0-9]{0,2})$ ]] && (( fcp_rule_em_temp <= 300 )) || return 1
    [[ "$fcp_rule_em_pwm" =~ ^(0|[1-9][0-9]{0,2})$ ]] &&
      (( fcp_rule_em_pwm <= 255 && fcp_rule_em_pwm >= fcp_points_pwm[n-1] )) || return 1
  else
    fcp_rule_em_pwm=""
  fi
  return 0
}

# Raw PWM of the parsed points at TEMP. Endpoints saturate. Between points a
# linear curve interpolates (integer math, rounding down). A stepped curve holds
# the lower point until the next point's temperature is reached.
fcp_curve_at() {
  local temp="$1" n=${#fcp_points_temp[@]} i t0 p0 t1 p1
  if (( temp <= fcp_points_temp[0] )); then
    curve_pwm=${fcp_points_pwm[0]}
    return 0
  fi
  if (( temp >= fcp_points_temp[n-1] )); then
    curve_pwm=${fcp_points_pwm[n-1]}
    return 0
  fi
  for (( i = 0; i < n - 1; i++ )); do
    (( temp < fcp_points_temp[i+1] )) && break
  done
  t0=${fcp_points_temp[i]}
  p0=${fcp_points_pwm[i]}
  t1=${fcp_points_temp[i+1]}
  p1=${fcp_points_pwm[i+1]}
  if [[ "$fcp_rule_mode" == stepped ]]; then
    curve_pwm=$p0
  else
    curve_pwm=$(( p0 + (temp - t0) * (p1 - p0) / (t1 - t0) ))
  fi
  return 0
}

# Sets curve_pwm to the raw PWM for TEMP, a whole-degree reading, in the source
# PREFIX. LOW and HIGH are the source's legacy bounds; they drive the straight
# ramp when PREFIX_curve is unset. Call it directly, never inside $(...): the
# hysteresis state lives in this shell.
fcp_curve_pwm() {
  local prefix="$1" temp="$2" low="$3" high="$4"
  local curve_var="${1}_curve" fp eff latch gap

  curve_pwm=255
  [[ "$temp" =~ ^(0|[1-9][0-9]*)$ ]] || return 0

  if [[ -z "${!curve_var+x}" ]]; then
    fcp_curve_straight "$temp" "$low" "$high"
    return 0
  fi

  [[ "${max:-}" =~ ^(0|[1-9][0-9]{0,2})$ ]] && (( max <= 255 )) || return 0
  fcp_curve_rule "$prefix" || return 0

  fp="${!curve_var};${fcp_rule_mode};${fcp_rule_hyst};${fcp_rule_em_temp};${fcp_rule_em_pwm}"
  if [[ "${fcp_curve_fp[$prefix]:-}" == "$fp" && -n "${fcp_curve_eff[$prefix]:-}" ]]; then
    eff=${fcp_curve_eff[$prefix]}
    latch=${fcp_curve_latch[$prefix]:-0}
  else
    eff=$temp
    latch=0
  fi

  # Hysteresis: a rise is followed at once. A fall is followed only down to
  # reading plus hysteresis, so a cooling reading never raises the effective
  # temperature.
  if (( temp >= eff )); then
    eff=$temp
  elif (( temp + fcp_rule_hyst < eff )); then
    eff=$(( temp + fcp_rule_hyst ))
  fi

  # Emergency latch, on the actual reading. It enters at the threshold and
  # leaves at threshold minus hysteresis; with no hysteresis, just below it.
  if (( fcp_rule_em_on )); then
    if (( latch )); then
      gap=$(( fcp_rule_hyst > 0 ? fcp_rule_hyst : 1 ))
      (( temp <= fcp_rule_em_temp - gap )) && latch=0
    elif (( temp >= fcp_rule_em_temp )); then
      latch=1
    fi
  fi

  fcp_curve_eff[$prefix]=$eff
  fcp_curve_latch[$prefix]=$latch
  fcp_curve_fp[$prefix]=$fp

  if (( latch )); then
    # Emergency is the point of the curve, so it is never capped.
    curve_pwm=$fcp_rule_em_pwm
  else
    fcp_curve_at "$eff"
    (( curve_pwm > max )) && curve_pwm=$max
  fi
  return 0
}

# Atomically replace the RAM snapshot while the caller holds its state lock.
fcp_curve_publish() {
  local file="$1" key tmp
  tmp="${file}.tmp.$$"
  {
    [[ "${fcp_curve_output:--1}" != -1 ]] && printf '@output|%s||\n' "$fcp_curve_output"
    for key in "${!fcp_curve_eff[@]}"; do
      printf '%s|%s|%s|%s\n' "$key" "${fcp_curve_fp[$key]:-}" \
        "${fcp_curve_eff[$key]}" "${fcp_curve_latch[$key]:-0}"
    done
  } > "$tmp" && mv -f "$tmp" "$file"
  return 0
}

# Seeds this process's curve state from FILE, as written by fcp_curve_publish, so
# a manual run continues the loop's hysteresis and latch. A malformed line is
# skipped, leaving that source to start fresh. A fingerprint from another rule is
# caught when the source is evaluated.
fcp_curve_load() {
  local file="$1" key fp eff latch
  fcp_curve_output=-1
  [[ -r "$file" ]] || return 0
  while IFS='|' read -r key fp eff latch; do
    if [[ "$key" == @output && "$fp" =~ ^(0|[1-9][0-9]{0,2})$ && -z "$eff$latch" ]] && (( fp <= 255 )); then
      fcp_curve_output=$fp
      continue
    fi
    [[ "$key" =~ ^[a-z_][a-z_0-9]*$ && "$eff" =~ ^(0|[1-9][0-9]{0,2})$ && "$latch" =~ ^[01]$ ]] || continue
    fcp_curve_eff[$key]=$eff
    fcp_curve_latch[$key]=$latch
    fcp_curve_fp[$key]=$fp
  done < "$file"
  return 0
}

# Serialize evaluation and controller writes across the loop and manual Run.
fcp_curve_begin() {
  local file="$1"
  fcp_curve_shared=0
  exec {fcp_curve_state_fd}>"${file}.lock" || return 1
  if ! flock -x "$fcp_curve_state_fd"; then
    exec {fcp_curve_state_fd}>&-
    return 1
  fi
  fcp_curve_shared=1
  fcp_curve_load "$file"
}

fcp_curve_end() {
  fcp_curve_output="${2:--1}"
  fcp_curve_publish "$1"
  flock -u "$fcp_curve_state_fd"
  exec {fcp_curve_state_fd}>&-
}
