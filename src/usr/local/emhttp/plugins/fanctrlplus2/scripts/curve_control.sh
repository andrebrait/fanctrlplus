#!/bin/bash

# shellcheck disable=SC2034,SC2154

# Fan curves for one source: cpu, aux, disk_group_N, or the legacy disk range.
# fcp_curve_pwm gives the raw PWM for a reading. The loop and the manual run
# both evaluate through it, so they agree.
#
# A source with none of the curve fields keeps the legacy straight ramp between
# LOW and HIGH, with the integer math it always had. A source with PREFIX_curve
# follows its own fields:
#   PREFIX_curve           "T:P,T:P,..." 2-8 points; temperatures strictly
#                          increasing 0-300, raw PWM 0-255 nondecreasing
#   PREFIX_curve_mode      "linear" (default) or "stepped"
#   PREFIX_hysteresis      0-20 (default 0)
#   PREFIX_emergency_temp  unset or "" disables; 0-300 enables
#   PREFIX_emergency_pwm   0-255 (default 255), at least the last curve PWM
# Any field that does not validate fails hot: the source reads 255. So does a
# source that has some of the fields but no PREFIX_curve: its emergency
# threshold must not be dropped by falling back to the legacy ramp.

# Per-source state for a configured curve, keyed by PREFIX: the effective
# temperature the curve follows, the emergency latch, the fingerprint of the
# rule that produced them, and the observation time (below). A rule with a
# different fingerprint is new, so an edited curve never inherits the old state.
declare -gA fcp_curve_eff=() fcp_curve_latch=() fcp_curve_fp=() fcp_curve_stamp=()
fcp_curve_clock_reset=0

# The state is bound to what the source reads (fcp_curve_binding), so a disk
# group that inherits another group's number, or a CPU sensor that is swapped,
# starts fresh instead of inheriting a latch it never earned.

# Observation time. A source is read before the state lock is taken, and the
# lock can be a long wait away, so another run may publish a newer observation of
# the same source first. Each sample therefore carries the time its observation
# began (fcp_stamp_now: microseconds, taken before the read), and the state
# keeps the time of the newest observation it holds (fcp_curve_stamp). A sample
# older than that is stale: it may still heat the fan or enter the emergency,
# which is safe whatever the truth is now, but it never cools the fan or clears
# a latch; only a strictly newer stamped observation may do that.
fcp_now=0

# The result of fcp_curve_pwm. A global, not a return value: the state above
# must persist between calls, and a $(...) call would throw it away.
curve_pwm=0

# The applied output the snapshot records (-1: unknown), whether this process
# holds the state lock, and whether its memory is ahead of the file's: its last
# snapshot write failed, or a round ran without the lock. Then a plain reload
# would undo what the memory holds, so the file is merged in instead
# (fcp_curve_load).
fcp_curve_output=-1
fcp_curve_shared=0
fcp_curve_unpublished=0

# What this process last saw of the file, a line per source in the file's own
# format (and the output), set by every load and every successful publish. A
# line that is still the same says nothing new; one that differs was written by
# another run after this process last synced.
declare -gA fcp_curve_synced=()
fcp_curve_synced_output=""

# Seconds a run may wait for the state lock held by another run or the loop.
fcp_curve_lock_wait="${fcp_curve_lock_wait:-10}"

# Sets fcp_now to the current time in microseconds (EPOCHREALTIME without its
# decimal point, a bounded integer). A shell without EPOCHREALTIME has whole
# seconds. Call it directly, not in $(...), as for every setter here.
fcp_stamp_now() {
  if [[ -n "${EPOCHREALTIME-}" ]]; then
    fcp_now=${EPOCHREALTIME/[.,]/}
  else
    printf -v fcp_now '%(%s)T' -1
    fcp_now+=000000
  fi
}

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

# What a source's state belongs to: the sensors or disks it reads, as a sorted
# set, so that reordering the same members never resets the state while another
# set of members always does. Sets fcp_curve_bind. The text ends up in the
# snapshot's pipe-separated lines, so those characters are replaced.
fcp_curve_binding() {
  local LC_COLLATE=C list="" var item i
  local -a items=() sorted=()
  case "$1" in
    cpu)  list=${cpu_sensor-} ;;
    aux)  list=${aux_sensor-} ;;
    disk) list=${disks-} ;;
    *)    var="${1}_disks"; list=${!var-} ;;
  esac
  IFS=',' read -ra items <<< "$list"
  for item in "${items[@]}"; do
    [[ -n "$item" ]] || continue
    for (( i = 0; i < ${#sorted[@]}; i++ )); do
      [[ "${sorted[i]}" < "$item" ]] || break
    done
    [[ "${sorted[i]-}" == "$item" ]] && continue
    sorted=("${sorted[@]:0:i}" "$item" "${sorted[@]:i}")
  done
  local IFS=,
  fcp_curve_bind="${sorted[*]}"
  fcp_curve_bind=${fcp_curve_bind//[|$'\n']/_}
}

# Sets curve_pwm to the raw PWM for TEMP, a whole-degree reading, in the source
# PREFIX. LOW and HIGH are the source's legacy bounds; they drive the straight
# ramp when the source has no curve settings. Call it directly, never inside
# $(...): the hysteresis state lives in this shell.
#
# The optional fifth argument is either STAMP, the time the reading's
# observation began (fcp_stamp_now, a number: see the observation time above), or
# "peek", which evaluates TEMP against the state without recording anything, not
# even the time. Without a stamp the call is an ordinary sequential evaluation,
# as new as the state. A malformed stamp counts as none.
fcp_curve_pwm() {
  local prefix="$1" temp="$2" low="$3" high="$4" arg="${5:-}"
  local curve_var="${1}_curve" field fp eff latch gap stamp="" peek=0 stale=0 known=0

  if [[ "$arg" == peek ]]; then
    peek=1
  elif [[ "$arg" =~ ^(0|[1-9][0-9]{0,15})$ ]]; then
    stamp=$arg
  fi

  curve_pwm=255
  [[ "$temp" =~ ^(0|[1-9][0-9]*)$ ]] || return 0

  if [[ ! -v "$curve_var" ]]; then
    # Settings without their curve are a corrupt rule, not a legacy one.
    for field in curve_mode hysteresis emergency_temp emergency_pwm; do
      [[ -v "${prefix}_${field}" ]] && return 0
    done
    fcp_curve_straight "$temp" "$low" "$high"
    return 0
  fi

  [[ "${max:-}" =~ ^(0|[1-9][0-9]{0,2})$ ]] && (( max <= 255 )) || return 0
  fcp_curve_rule "$prefix" || return 0

  fcp_curve_binding "$prefix"
  fp="${!curve_var};${fcp_rule_mode};${fcp_rule_hyst};${fcp_rule_em_temp};${fcp_rule_em_pwm};${fcp_curve_bind}"
  if [[ "${fcp_curve_fp[$prefix]:-}" == "$fp" && -n "${fcp_curve_eff[$prefix]:-}" ]]; then
    known=1
    eff=${fcp_curve_eff[$prefix]}
    latch=${fcp_curve_latch[$prefix]:-0}
    # Ties cannot establish order, and clock rebasing quarantines this round.
    [[ -n "$stamp" ]] && (( fcp_curve_clock_reset || (${fcp_curve_stamp[$prefix]:-0} > 0 && stamp <= ${fcp_curve_stamp[$prefix]:-0}) )) && stale=1
  else
    eff=$temp
    latch=0
  fi

  # Hysteresis: a rise is followed at once. A fall is followed only down to
  # reading plus hysteresis, so a cooling reading never raises the effective
  # temperature. A stale reading may raise it but never lowers it.
  if (( temp >= eff )); then
    eff=$temp
  elif (( ! stale && temp + fcp_rule_hyst < eff )); then
    eff=$(( temp + fcp_rule_hyst ))
  fi

  # Emergency latch, on the actual reading. It enters at the threshold and
  # leaves at threshold minus hysteresis; with no hysteresis, just below it. A
  # stale reading may enter it but never leaves it.
  if (( fcp_rule_em_on )); then
    if (( latch )); then
      gap=$(( fcp_rule_hyst > 0 ? fcp_rule_hyst : 1 ))
      (( ! stale && temp <= fcp_rule_em_temp - gap )) && latch=0
    elif (( temp >= fcp_rule_em_temp )); then
      latch=1
    fi
  fi

  if (( ! peek )); then
    fcp_curve_eff[$prefix]=$eff
    fcp_curve_latch[$prefix]=$latch
    fcp_curve_fp[$prefix]=$fp
    # The state's time moves on only with an observation at least as new as it.
    # A fresh state takes the sample's time; a call without a stamp changes none.
    if (( ! known )); then
      fcp_curve_stamp[$prefix]=${stamp:-0}
    elif [[ -n "$stamp" ]] && (( ! stale )); then
      fcp_curve_stamp[$prefix]=$stamp
    fi
  fi

  if (( latch )); then
    # Emergency is the point of the curve, so it is never capped.
    curve_pwm=$fcp_rule_em_pwm
  else
    fcp_curve_at "$eff"
    (( curve_pwm > max )) && curve_pwm=$max
  fi
  return 0
}

# The CPU source's PWM for RAW, the content of its sensor file (millidegrees),
# read at STAMP (fcp_stamp_now before the read; see fcp_curve_pwm). Sets
# cpu_usable, cpu_temp and cpu_pwm_val. Input that is not a number, reads exactly
# 0 (an unpopulated hwmon input) or is not a plausible temperature is not a
# reading: that round counts as 0 °C for the output, as it always did, but never
# enters the hysteresis, emergency or observation-time state, so a hot fan stays
# under its latch through a gap in the readings. A nonzero raw value that works
# out to 0 °C is a real reading and does.
fcp_cpu_pwm() {
  local raw="$1" stamp="${2:-}" temp=""
  [[ "$raw" =~ ^-?[0-9]+$ ]] && temp=$(fcp_clamp_temp $((raw / 1000)))
  if [[ "$raw" =~ ^-?[0-9]+$ && "$raw" -ne 0 && -n "$temp" ]]; then
    cpu_usable=1
    cpu_temp=$temp
    fcp_curve_pwm cpu "$cpu_temp" "${cpu_min_temp-}" "${cpu_max_temp-}" "$stamp"
  else
    cpu_usable=0
    cpu_temp=0
    fcp_curve_pwm cpu 0 "${cpu_min_temp-}" "${cpu_max_temp-}" peek
  fi
  cpu_pwm_val=$curve_pwm
}

# Atomically replaces the RAM snapshot while the caller holds its state lock.
# One line per source, "KEY|FINGERPRINT|EFFECTIVE|LATCH|TIME", and the applied
# output as "@output|VALUE||". Fails, leaving the old snapshot in place and the
# partial file removed, when the snapshot cannot be written. The state of a
# failed publish stays in this process (fcp_curve_unpublished); the next load
# merges the file into it instead of replacing it.
fcp_curve_publish() {
  local file="$1" key tmp="${1}.tmp.$$" body="" line
  local -A lines=()
  if [[ "${fcp_curve_output:--1}" != -1 ]]; then
    body="@output|${fcp_curve_output}||"$'\n'
  fi
  for key in "${!fcp_curve_eff[@]}"; do
    line="${fcp_curve_fp[$key]:-}|${fcp_curve_eff[$key]}|${fcp_curve_latch[$key]:-0}|${fcp_curve_stamp[$key]:-0}"
    lines[$key]=$line
    body+="${key}|${line}"$'\n'
  done
  if printf '%s' "$body" > "$tmp" && mv -f "$tmp" "$file"; then
    fcp_curve_unpublished=0
    fcp_curve_synced=()
    for key in "${!lines[@]}"; do fcp_curve_synced[$key]=${lines[$key]}; done
    fcp_curve_synced_output=""
    [[ "${fcp_curve_output:--1}" != -1 ]] && fcp_curve_synced_output=$fcp_curve_output
    return 0
  fi
  rm -f "$tmp"
  fcp_curve_unpublished=1
  return 1
}

# Folds one source's line from the file into this process's own state, for when
# the memory is ahead of the file (fcp_curve_unpublished): neither side is the
# truth, so the result is the hotter of the two. A source only the file has is
# taken as it is. One with another fingerprint is left alone: the evaluator
# starts whichever rule it is given from scratch anyway. For the same rule the
# effective temperature is the higher, the latch is held if either side holds it,
# and the observation time is the newer. The next sample at least that new
# releases it normally.
fcp_curve_merge() {
  local key="$1" fp="$2" eff="$3" latch="$4" stamp="$5"
  if [[ -z "${fcp_curve_fp[$key]+x}" ]]; then
    fcp_curve_eff[$key]=$eff
    fcp_curve_latch[$key]=$latch
    fcp_curve_fp[$key]=$fp
    fcp_curve_stamp[$key]=$stamp
  elif [[ "${fcp_curve_fp[$key]}" == "$fp" ]]; then
    (( eff > ${fcp_curve_eff[$key]:-0} )) && fcp_curve_eff[$key]=$eff
    (( latch )) && fcp_curve_latch[$key]=1
    (( stamp > ${fcp_curve_stamp[$key]:-0} )) && fcp_curve_stamp[$key]=$stamp
  fi
  return 0
}

# Seeds this process's curve state from FILE, as written by fcp_curve_publish, so
# a manual run continues the loop's hysteresis and latch. A malformed line is
# skipped, leaving that source to start fresh. A fingerprint from another rule is
# caught when the source is evaluated.
#
# A process whose memory is ahead of the file (fcp_curve_unpublished) merges
# instead of replacing, and only what changed since it last synced: a line that
# is still what it last saw says nothing new, so a latch released here is not
# brought back by an older snapshot, while a line another run wrote since is
# merged hot (fcp_curve_merge).
#
# A clock rollback makes in-flight observations ambiguous. Rebase the clock but
# preserve cooling for this round; a freshly sampled next round can release it.
fcp_curve_load() {
  local file="$1" key fp eff latch stamp line now merge=$fcp_curve_unpublished
  fcp_stamp_now
  now=$fcp_now
  fcp_curve_clock_reset=0
  for key in "${!fcp_curve_stamp[@]}"; do
    if (( fcp_curve_stamp[$key] > now )); then fcp_curve_stamp[$key]=0; fcp_curve_clock_reset=1; fi
  done
  if (( ! merge )); then
    fcp_curve_output=-1
    fcp_curve_synced=()
    fcp_curve_synced_output=""
  fi
  [[ -r "$file" ]] || return 0
  while IFS='|' read -r key fp eff latch stamp; do
    if [[ "$key" == @output ]]; then
      if [[ "$fp" =~ ^(0|[1-9][0-9]{0,2})$ && -z "$eff$latch$stamp" ]] && (( fp <= 255 )); then
        (( merge )) && [[ "$fp" == "$fcp_curve_synced_output" ]] || fcp_curve_output=$fp
        fcp_curve_synced_output=$fp
      fi
      continue
    fi
    [[ "$key" =~ ^[a-z_][a-z_0-9]*$ && "$eff" =~ ^(0|[1-9][0-9]{0,2})$ && "$latch" =~ ^[01]$ ]] || continue
    [[ -n "$stamp" ]] || stamp=0
    [[ "$stamp" =~ ^(0|[1-9][0-9]{0,15})$ ]] || continue
    if (( stamp > now )); then stamp=0; fcp_curve_clock_reset=1; fi
    line="${fp}|${eff}|${latch}|${stamp}"
    if (( merge )); then
      [[ "${fcp_curve_synced[$key]-}" == "$line" ]] && continue
      fcp_curve_merge "$key" "$fp" "$eff" "$latch" "$stamp"
    else
      fcp_curve_eff[$key]=$eff
      fcp_curve_latch[$key]=$latch
      fcp_curve_fp[$key]=$fp
      fcp_curve_stamp[$key]=$stamp
    fi
    fcp_curve_synced[$key]=$line
  done < "$file"
  return 0
}

# Serializes evaluation and controller writes across the loop and manual Run.
# Waits up to fcp_curve_lock_wait seconds; fails when the lock cannot be had.
# Hold it only from here to fcp_curve_end: read every sensor first, so a slow
# or stalled sensor can never keep the other process from the lock. A failure
# leaves the caller's round to run on its own memory, which makes the memory
# ahead of the file (fcp_curve_unpublished) until a publish catches it up.
fcp_curve_begin() {
  local file="$1"
  fcp_curve_shared=0
  if ! exec {fcp_curve_state_fd}>"${file}.lock"; then
    fcp_curve_unpublished=1
    return 1
  fi
  if ! flock -x -w "$fcp_curve_lock_wait" "$fcp_curve_state_fd"; then
    exec {fcp_curve_state_fd}>&-
    fcp_curve_unpublished=1
    return 1
  fi
  fcp_curve_shared=1
  fcp_curve_load "$file"
  return 0
}

# Publishes the snapshot, recording OUTPUT as the applied output (default: the
# one the snapshot already had, for a run whose write failed), and always
# releases the lock. Fails when the snapshot could not be written.
fcp_curve_end() {
  local rc=0
  fcp_curve_output="${2-$fcp_curve_output}"
  fcp_curve_publish "$1" || rc=1
  flock -u "$fcp_curve_state_fd"
  exec {fcp_curve_state_fd}>&-
  return $rc
}
