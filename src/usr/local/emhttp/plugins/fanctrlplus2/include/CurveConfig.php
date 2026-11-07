<?php
// Fan curves. Each source (cpu, aux, or disk:N) maps temperature to raw PWM through
// 2-8 ordered points. A fan stores every source as PREFIX_curve plus four companions.
// A fan with none of them is legacy: each source is one two-point ramp built from
// the old pwm/max/low/high keys, which the runtime evaluates the same way.

const FCP_CURVE_MIN_POINTS = 2;
const FCP_CURVE_MAX_POINTS = 8;
const FCP_CURVE_MAX_TEMP = 300;
const FCP_CURVE_MAX_PWM = 255;
const FCP_CURVE_MAX_HYSTERESIS = 20;
const FCP_CURVE_FIELDS = ['curve', 'curve_mode', 'hysteresis', 'emergency_temp', 'emergency_pwm'];

// The prefix (cpu, aux or disk_group_N) a curve cfg key belongs to, or null for any other key.
function fcp_curve_prefix(string $key): ?string {
    return preg_match('/^((?:cpu|aux|disk_group_\d+))_(?:curve|curve_mode|hysteresis|emergency_temp|emergency_pwm)$/D', $key, $m) === 1 ? $m[1] : null;
}

function fcp_curve_has_settings(array $cfg): bool {
    foreach (array_keys($cfg) as $key) {
        if (fcp_curve_prefix((string)$key) !== null) return true;
    }
    return false;
}

function fcp_curve_count(array $cfg): int {
    return max(0, min(256, (int)($cfg['disk_group_count'] ?? 0)));
}

// Every source a fan with $count disk groups has, mapped to its cfg prefix.
function fcp_curve_sources(int $count): array {
    $sources = ['cpu' => 'cpu', 'aux' => 'aux'];
    for ($g = 0; $g < $count; $g++) $sources["disk:$g"] = "disk_group_$g";
    return $sources;
}

function fcp_curve_int(array $cfg, string $key, int $default): int {
    return isset($cfg[$key]) && is_numeric($cfg[$key]) ? (int)$cfg[$key] : $default;
}

// Validates one rule and returns it normalized. Strict: nothing is coerced, so any
// value that is not already the right JSON type is refused.
function fcp_validate_curve_rule(mixed $rule): array {
    if (!is_array($rule) || array_is_list($rule)) throw new InvalidArgumentException('A curve rule must be an object.');
    $keys = array_map('strval', array_keys($rule));
    sort($keys);
    if ($keys !== ['emergency_pwm', 'emergency_temp', 'hysteresis', 'mode', 'points']) {
        throw new InvalidArgumentException('A curve rule must have exactly the fields points, mode, hysteresis, emergency_temp and emergency_pwm.');
    }

    $points = $rule['points'];
    if (!is_array($points) || !array_is_list($points) || count($points) < FCP_CURVE_MIN_POINTS || count($points) > FCP_CURVE_MAX_POINTS) {
        throw new InvalidArgumentException('A curve needs 2 to 8 points.');
    }
    $normal = [];
    $lastTemp = -1;
    $lastPwm = 0;
    foreach ($points as $point) {
        if (!is_array($point) || !array_is_list($point) || count($point) !== 2 || !is_int($point[0]) || !is_int($point[1])) {
            throw new InvalidArgumentException('Each curve point must be [temperature, PWM] with integers.');
        }
        [$temp, $pwm] = $point;
        if ($temp < 0 || $temp > FCP_CURVE_MAX_TEMP || $pwm < 0 || $pwm > FCP_CURVE_MAX_PWM) {
            throw new InvalidArgumentException('A curve point must be 0-300 C and PWM 0-255.');
        }
        if ($temp <= $lastTemp) throw new InvalidArgumentException('Curve temperatures must strictly increase.');
        if ($pwm < $lastPwm) throw new InvalidArgumentException('Curve PWM values must not decrease.');
        $normal[] = [$temp, $pwm];
        [$lastTemp, $lastPwm] = [$temp, $pwm];
    }

    if (!in_array($rule['mode'], ['linear', 'stepped'], true)) throw new InvalidArgumentException('Curve mode must be linear or stepped.');
    $hysteresis = $rule['hysteresis'];
    if (!is_int($hysteresis) || $hysteresis < 0 || $hysteresis > FCP_CURVE_MAX_HYSTERESIS) {
        throw new InvalidArgumentException('Hysteresis must be an integer from 0 to 20 C.');
    }
    $emergencyTemp = $rule['emergency_temp'];
    if ($emergencyTemp !== null && (!is_int($emergencyTemp) || $emergencyTemp < 0 || $emergencyTemp > FCP_CURVE_MAX_TEMP)) {
        throw new InvalidArgumentException('Emergency temperature must be empty or an integer from 0 to 300 C.');
    }
    $emergencyPwm = $rule['emergency_pwm'];
    if (!is_int($emergencyPwm) || $emergencyPwm < 0 || $emergencyPwm > FCP_CURVE_MAX_PWM) {
        throw new InvalidArgumentException('Emergency PWM must be an integer from 0 to 255.');
    }
    // Only enforced while the emergency threshold is on: it must never cool less than the curve.
    if ($emergencyTemp !== null && $emergencyPwm < $lastPwm) {
        throw new InvalidArgumentException("Emergency PWM must not be below the curve's last PWM.");
    }
    return [
        'points' => $normal,
        'mode' => $rule['mode'],
        'hysteresis' => $hysteresis,
        'emergency_temp' => $emergencyTemp,
        'emergency_pwm' => $emergencyPwm,
    ];
}

// The cfg fields that store one rule under $prefix: raw PWM values, and a blank emergency temperature when disabled.
function fcp_curve_fields(array $rule, string $prefix): array {
    $rule = fcp_validate_curve_rule($rule);
    return [
        "{$prefix}_curve" => implode(',', array_map(fn(array $point): string => "$point[0]:$point[1]", $rule['points'])),
        "{$prefix}_curve_mode" => $rule['mode'],
        "{$prefix}_hysteresis" => (string)$rule['hysteresis'],
        "{$prefix}_emergency_temp" => $rule['emergency_temp'] === null ? '' : (string)$rule['emergency_temp'],
        "{$prefix}_emergency_pwm" => (string)$rule['emergency_pwm'],
    ];
}

// One source's stored rule. Null when it stores nothing; partial or malformed settings are an error.
function fcp_curve_stored(array $cfg, string $prefix): ?array {
    $keys = array_map(fn(string $field): string => "{$prefix}_$field", FCP_CURVE_FIELDS);
    if (array_filter($keys, fn(string $key): bool => array_key_exists($key, $cfg)) === []) return null;
    foreach ($keys as $key) {
        if (!isset($cfg[$key]) || !is_string($cfg[$key])) throw new InvalidArgumentException("Curve settings for $prefix are incomplete.");
    }
    $text = fn(string $field): string => $cfg["{$prefix}_$field"];

    $curve = $text('curve');
    if (preg_match('/^(?:0|[1-9]\d{0,2}):(?:0|[1-9]\d{0,2})(?:,(?:0|[1-9]\d{0,2}):(?:0|[1-9]\d{0,2})){1,7}$/D', $curve) !== 1) throw new InvalidArgumentException("Curve for $prefix is malformed.");
    $points = [];
    foreach (explode(',', $curve) as $pair) {
        [$temp, $pwm] = explode(':', $pair);
        $points[] = [(int)$temp, (int)$pwm];
    }
    $emergencyTemp = $text('emergency_temp');
    $hysteresis = $text('hysteresis');
    $emergencyPwm = $text('emergency_pwm');
    if (($emergencyTemp !== '' && preg_match('/^(?:0|[1-9]\d{0,2})$/D', $emergencyTemp) !== 1)
        || preg_match('/^(?:0|[1-9]\d?)$/D', $hysteresis) !== 1 || preg_match('/^(?:0|[1-9]\d{0,2})$/D', $emergencyPwm) !== 1) {
        throw new InvalidArgumentException("Curve settings for $prefix are malformed.");
    }
    return fcp_validate_curve_rule([
        'points' => $points,
        'mode' => $text('curve_mode'),
        'hysteresis' => (int)$hysteresis,
        'emergency_temp' => $emergencyTemp === '' ? null : (int)$emergencyTemp,
        'emergency_pwm' => (int)$emergencyPwm,
    ]);
}

// Normalize legacy bounds to increasing temperatures; the separate normal cap
// still applies to the resulting curve.
function fcp_curve_legacy_rule(int $low, int $high, int $pwm, int $cap): array {
    $low = min(max($low, 0), FCP_CURVE_MAX_TEMP - 1);
    $high = min(max($high, $low + 1), FCP_CURVE_MAX_TEMP);
    $pwm = min(max($pwm, 0), FCP_CURVE_MAX_PWM);
    $cap = min(max($cap, 0), FCP_CURVE_MAX_PWM);
    return [
        'points' => [[$low, $pwm], [$high, max($pwm, $cap)]],
        'mode' => 'linear',
        'hysteresis' => 0,
        'emergency_temp' => null,
        'emergency_pwm' => FCP_CURVE_MAX_PWM,
    ];
}

// A source's legacy ramp from the stored old keys. The defaults match the editor's.
function fcp_curve_legacy_source(array $cfg, string $source): array {
    if ($source === 'cpu' || $source === 'aux') {
        $low = fcp_curve_int($cfg, "{$source}_min_temp", 40);
        $high = fcp_curve_int($cfg, "{$source}_max_temp", 70);
    } elseif (fcp_curve_count($cfg) === 0) {
        // Without disk groups the runtime reads the single range from low/high.
        $low = fcp_curve_int($cfg, 'low', 40);
        $high = fcp_curve_int($cfg, 'high', 60);
    } else {
        $group = substr($source, strlen('disk:'));
        $low = fcp_curve_int($cfg, "disk_group_{$group}_low", 40);
        $high = fcp_curve_int($cfg, "disk_group_{$group}_high", 60);
    }
    return fcp_curve_legacy_rule($low, $high, fcp_curve_int($cfg, 'pwm', 102), fcp_curve_int($cfg, 'max', 255));
}

// The rules a fan saves, keyed by source. A fan with curves must store exactly the sources its groups define.
function fcp_curve_source_rules(array $cfg): array {
    $sources = fcp_curve_sources(fcp_curve_count($cfg));
    if (!fcp_curve_has_settings($cfg)) {
        $rules = [];
        foreach (array_keys($sources) as $source) $rules[$source] = fcp_curve_legacy_source($cfg, $source);
        return $rules;
    }
    $declared = array_values($sources);
    foreach (array_keys($cfg) as $key) {
        $prefix = fcp_curve_prefix((string)$key);
        if ($prefix !== null && !in_array($prefix, $declared, true)) {
            throw new InvalidArgumentException("Curve settings exist for $prefix, which this fan does not have.");
        }
    }
    $rules = [];
    foreach ($sources as $source => $prefix) {
        $rules[$source] = fcp_curve_stored($cfg, $prefix) ?? throw new InvalidArgumentException("Curve settings for $prefix are missing.");
    }
    return $rules;
}

// Rules for the editor: every source the fan has, plus disk:0 for a fan that has no disk groups yet.
function fcp_curve_rules(array $cfg): array {
    $rules = fcp_curve_source_rules($cfg);
    $rules['disk:0'] ??= fcp_curve_legacy_source($cfg, 'disk:0');
    return $rules;
}

// Idle is bounded by disk curves and enabled CPU/Aux rules, never disabled sensors.
function fcp_curve_floor(array $rules, bool $cpuEnabled, bool $auxEnabled): int {
    $minimum = 255;
    foreach ($rules as $source => $rule) {
        if (($source === 'cpu' && !$cpuEnabled) || ($source === 'aux' && !$auxEnabled)) continue;
        $minimum = min($minimum, $rule['points'][0][1]);
    }
    return $minimum;
}

// The temperatures a curve spans: its first and last point.
function fcp_curve_endpoints(array $rule): array {
    $last = count($rule['points']) - 1;
    return [$rule['points'][0][0], $rule['points'][$last][0]];
}

// The legacy keys older readers use, derived from the curves so the two can never disagree.
// Min and Max Temp are blank for a disabled source, as the old form wrote them.
function fcp_curve_legacy_fields(array $rules, bool $cpuEnabled, bool $auxEnabled): array {
    $fields = ['pwm' => (string)fcp_curve_floor($rules, $cpuEnabled, $auxEnabled)];
    foreach (['cpu' => $cpuEnabled, 'aux' => $auxEnabled] as $source => $enabled) {
        [$low, $high] = fcp_curve_endpoints($rules[$source]);
        $fields["{$source}_min_temp"] = $enabled ? (string)$low : '';
        $fields["{$source}_max_temp"] = $enabled ? (string)$high : '';
    }
    $fields['low'] = '40';
    $fields['high'] = '60';
    foreach ($rules as $source => $rule) {
        if (strncmp((string)$source, 'disk:', strlen('disk:')) !== 0) continue;
        $group = substr((string)$source, strlen('disk:'));
        [$low, $high] = fcp_curve_endpoints($rule);
        $fields["disk_group_{$group}_low"] = (string)$low;
        $fields["disk_group_{$group}_high"] = (string)$high;
        if ($group === '0') {
            $fields['low'] = (string)$low;
            $fields['high'] = (string)$high;
        }
    }
    return $fields;
}

// The current editor must submit every rule; a stale form must refresh, not overwrite curves.
function fcp_curve_post_rules(mixed $json, array $groupKeys): array {
    if (!is_string($json)) throw new InvalidArgumentException('Curve settings are missing. Refresh the settings page before applying changes.');
    $decoded = json_decode($json, true, 8);
    if (!is_array($decoded)) throw new InvalidArgumentException('Curve settings are not valid JSON.');
    $expected = ['cpu', 'aux'];
    foreach ($groupKeys as $g) $expected[] = "disk:$g";
    $actual = array_map('strval', array_keys($decoded));
    sort($expected);
    sort($actual);
    if ($actual !== $expected) throw new InvalidArgumentException("Curve settings must cover exactly the fan's sources.");
    $rules = [];
    foreach ($decoded as $source => $rule) $rules[(string)$source] = fcp_validate_curve_rule($rule);
    return $rules;
}

// A restored fan with curves must agree with itself: idle fits under the lowest point and the max,
// and every legacy key matches what its curves derive.
function fcp_curve_verify(array $cfg): void {
    if (!fcp_curve_has_settings($cfg)) return;
    $rules = fcp_curve_source_rules($cfg);
    if (fcp_curve_int($cfg, 'idle', 0) > min(fcp_curve_floor($rules, ($cfg['cpu_enable'] ?? '') === '1', ($cfg['aux_enable'] ?? '') === '1'), fcp_curve_int($cfg, 'max', -1))) {
        throw new InvalidArgumentException('Idle speed must not exceed the lowest curve point or the normal max.');
    }
    $expected = fcp_curve_legacy_fields($rules, ($cfg['cpu_enable'] ?? '') === '1', ($cfg['aux_enable'] ?? '') === '1');
    foreach ($expected as $key => $value) {
        if (!array_key_exists($key, $cfg) || $cfg[$key] !== $value) throw new InvalidArgumentException("$key does not match the fan's curves.");
    }
}
