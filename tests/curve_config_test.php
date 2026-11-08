<?php
// Fan curves: strict rule validation, exact migration of legacy ramps, cross-field
// backup validation, and the save pipeline. A bad block must reject the whole save
// before any file is renamed or written.

$include = __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2/include';
require_once "$include/ConfigBackup.php";
require_once "$include/CurveConfig.php";
require_once "$include/FanBlockRender.php";

$failures = [];
$check = function ($condition, string $message) use (&$failures) {
    if (!$condition) $failures[] = $message;
};
// Every rejection must be an InvalidArgumentException, so callers can report it.
$rejects = function (callable $fn): bool {
    try { $fn(); return false; }
    catch (InvalidArgumentException) { return true; }
};
$sorted = function (array $map): array { ksort($map); return $map; };
// Serializes a cfg the way update.fanctrlplus2.php writes one.
$cfgText = function (array $values): string {
    $text = '';
    foreach ($values as $key => $value) $text .= "$key=\"$value\"\n";
    return $text;
};

$contract = ['points' => [[30, 76], [50, 255]], 'mode' => 'linear', 'hysteresis' => 0, 'emergency_temp' => null, 'emergency_pwm' => 255];
$tuned = ['points' => [[35, 60], [52, 200], [70, 255]], 'mode' => 'stepped', 'hysteresis' => 5, 'emergency_temp' => 75, 'emergency_pwm' => 255];
$with = fn(array $changes) => array_replace($contract, $changes);

// 1. Strict validation: well-formed rules pass unchanged; every unsafe shape is refused.
$check(fcp_validate_curve_rule($contract) === $contract, 'A well-formed rule must validate to itself.');
$check(fcp_validate_curve_rule($tuned) === $tuned, 'A stepped rule with hysteresis and emergency settings must validate to itself.');
$flat = $with(['points' => [[0, 0], [10, 0], [20, 20], [30, 20], [40, 80], [50, 80], [60, 200], [300, 255]]]);
$check(fcp_validate_curve_rule($flat) === $flat, 'Eight points with flat sections over the full temperature range must be accepted.');
$check(!$rejects(fn() => fcp_validate_curve_rule($with(['emergency_pwm' => 0]))), 'Emergency PWM is only checked against the curve while the emergency threshold is enabled.');

$unsafe = [
    'one point' => $with(['points' => [[30, 76]]]),
    'nine points' => $with(['points' => [[0, 0], [10, 10], [20, 20], [30, 30], [40, 40], [50, 50], [60, 60], [70, 70], [80, 80]]]),
    'points as an object' => $with(['points' => ['a' => [30, 76], 'b' => [50, 255]]]),
    'repeated temperature' => $with(['points' => [[30, 76], [30, 255]]]),
    'decreasing temperature' => $with(['points' => [[50, 76], [30, 255]]]),
    'decreasing PWM' => $with(['points' => [[30, 200], [50, 100]]]),
    'temperature above 300' => $with(['points' => [[30, 76], [301, 255]]]),
    'negative temperature' => $with(['points' => [[-1, 76], [50, 255]]]),
    'PWM above 255' => $with(['points' => [[30, 76], [50, 256]]]),
    'PWM as a string' => $with(['points' => [[30, '76'], [50, 255]]]),
    'temperature as a float' => $with(['points' => [[30.0, 76], [50, 255]]]),
    'unknown mode' => $with(['mode' => 'cubic']),
    'hysteresis above 20' => $with(['hysteresis' => 21]),
    'hysteresis as a string' => $with(['hysteresis' => '5']),
    'emergency temperature as a string' => $with(['emergency_temp' => '50']),
    'emergency temperature above 300' => $with(['emergency_temp' => 301]),
    'emergency PWM below the last curve PWM' => $with(['emergency_temp' => 60, 'emergency_pwm' => 200]),
    'emergency PWM above 255' => $with(['emergency_pwm' => 256]),
    'missing hysteresis' => array_diff_key($contract, ['hysteresis' => 0]),
    'an unknown field' => $contract + ['name' => 'x'],
    'a scalar' => 'curve',
];
foreach ($unsafe as $label => $rule) {
    $check($rejects(fn() => fcp_validate_curve_rule($rule)), "A curve rule with $label must be rejected.");
}

// 2. Serialization: exact cfg keys and raw values, and a reload gives back the same rules.
$check(fcp_curve_fields($contract, 'disk_group_0') === [
    'disk_group_0_curve' => '30:76,50:255',
    'disk_group_0_curve_mode' => 'linear',
    'disk_group_0_hysteresis' => '0',
    'disk_group_0_emergency_temp' => '',
    'disk_group_0_emergency_pwm' => '255',
], 'Curve fields must use the documented keys and write raw PWM values.');

$stored = fcp_curve_fields($contract, 'cpu') + fcp_curve_fields($contract, 'aux') + fcp_curve_fields($tuned, 'disk_group_0') + ['disk_group_count' => '1'];
$reloaded = fcp_curve_rules($stored);
$check($reloaded['disk:0'] === $tuned && $reloaded['cpu'] === $contract && $reloaded['aux'] === $contract, 'Saved curves must reload identically, including emergency and hysteresis settings.');

// 3. Legacy migration: old pwm/max/low/high keys become exact two-point linear ramps.
$legacy = fcp_curve_rules(['pwm' => '102', 'max' => '255', 'disk_group_count' => '1', 'disk_group_0_low' => '40', 'disk_group_0_high' => '60', 'cpu_min_temp' => '40', 'cpu_max_temp' => '70', 'aux_min_temp' => '40', 'aux_max_temp' => '70']);
$check($legacy['disk:0'] === $with(['points' => [[40, 102], [60, 255]]]), 'A legacy disk group must migrate to the exact raw ramp between its low/high at PWM and max.');
$check($legacy['cpu'] === $with(['points' => [[40, 102], [70, 255]]]) && $legacy['aux'] === $with(['points' => [[40, 102], [70, 255]]]), 'Legacy CPU and Aux bounds must migrate to exact raw ramps.');

$custom = fcp_curve_rules(['pwm' => '60', 'max' => '200', 'disk_group_count' => '2', 'disk_group_0_low' => '35', 'disk_group_0_high' => '52', 'disk_group_1_low' => '45', 'disk_group_1_high' => '70', 'cpu_min_temp' => '45', 'cpu_max_temp' => '80', 'aux_min_temp' => '', 'aux_max_temp' => '']);
$check($custom['disk:0']['points'] === [[35, 60], [52, 200]] && $custom['disk:1']['points'] === [[45, 60], [70, 200]] && $custom['cpu']['points'] === [[45, 60], [80, 200]], 'Each legacy group must keep its own bounds, with PWM and max as raw values.');
$check($custom['aux']['points'] === [[40, 60], [70, 200]], 'Blank legacy Aux bounds must fall back to the Aux default of 40 to 70.');

$defaults = fcp_curve_rules([]);
$check($defaults['disk:0']['points'] === [[40, 102], [60, 255]] && $defaults['cpu']['points'] === [[40, 102], [70, 255]] && $defaults['aux']['points'] === [[40, 102], [70, 255]], 'A config without legacy keys must use the documented defaults.');
$check(array_keys($defaults) === ['cpu', 'aux', 'disk:0'], 'A config without disk groups must still offer a disk:0 source.');
$check(fcp_build_disk_groups(['disk_group_count' => '0', 'disks' => '']) === [], 'A saved CPU-only fan must reopen without a disk group.');
$check(count(fcp_build_disk_groups(['disks' => 'legacy-disk'])) === 1, 'Legacy disk selection must remain visible.');

$degenerate = fcp_curve_rules(['pwm' => '102', 'max' => '255', 'disk_group_count' => '1', 'disk_group_0_low' => '60', 'disk_group_0_high' => '60', 'cpu_min_temp' => '300', 'cpu_max_temp' => '300']);
$check($degenerate['disk:0']['points'] === [[60, 102], [61, 255]], 'Equal legacy bounds must become strictly increasing instead of dividing by zero.');
$check($degenerate['cpu']['points'] === [[299, 102], [300, 255]], 'Bounds at the top of the temperature range must stay valid.');
$inverted = fcp_curve_rules(['pwm' => '200', 'max' => '100', 'disk_group_count' => '1', 'disk_group_0_low' => '60', 'disk_group_0_high' => '40']);
$check($inverted['disk:0']['points'] === [[60, 200], [61, 200]], 'An inverted legacy PWM range becomes flat stored points; the independent normal cap still applies.');
// A legacy bound below 0 C has no exact two-point form: refuse it rather than clamp it to a cooler ramp.
$check($rejects(fn() => fcp_curve_rules(['pwm' => '102', 'max' => '255', 'disk_group_count' => '1', 'disk_group_0_low' => '-10', 'disk_group_0_high' => '50'])), 'A negative legacy disk bound must be refused, not clamped to 0 C.');
$check($rejects(fn() => fcp_curve_rules(['pwm' => '102', 'max' => '255', 'disk_group_count' => '0', 'low' => '-10', 'high' => '50'])), 'A negative legacy single-range bound must be refused, not clamped to 0 C.');
$check($rejects(fn() => fcp_curve_rules(['pwm' => '102', 'max' => '255', 'disk_group_count' => '0', 'cpu_min_temp' => '-10', 'cpu_max_temp' => '50'])), 'A negative legacy CPU bound must be refused, not clamped to 0 C.');
$check(fcp_curve_rules(['pwm' => '102', 'max' => '255', 'disk_group_count' => '1', 'disk_group_0_low' => '0', 'disk_group_0_high' => '50'])['disk:0']['points'] === [[0, 102], [50, 255]], 'A legacy lower bound of exactly 0 C must still migrate exactly.');

// 4. Stored settings are all or nothing per source, and must match the fan's groups.
$full = fcp_curve_fields($contract, 'cpu') + fcp_curve_fields($contract, 'aux') + fcp_curve_fields($contract, 'disk_group_0') + ['disk_group_count' => '1'];
$check(count(fcp_curve_rules($full)) === 3, 'A complete curve set must load every source.');
$partial = $full;
unset($partial['disk_group_0_curve_mode']);
$cpuMissing = array_diff_key($full, array_flip(array_map(fn($field) => "cpu_$field", FCP_CURVE_FIELDS)));
$orphan = $full + fcp_curve_fields($contract, 'disk_group_1');
$injected = $full;
$injected['disk_group_0_curve'] = '30:76$(touch /tmp/fcp_curve_pwn)';
$unordered = $full;
$unordered['disk_group_0_curve'] = '50:255,30:76';
foreach (['a partial companion set' => $partial, 'a missing cpu source' => $cpuMissing, 'a curve for a group the fan does not have' => $orphan, 'an injected command' => $injected, 'unordered points' => $unordered] as $label => $cfg) {
    $check($rejects(fn() => fcp_curve_rules($cfg)), "Stored curves with $label must be rejected.");
}

// 5. Legacy keys are derived from the curves: pwm is the lowest first point, low/high the endpoints.
$check(fcp_curve_floor(['cpu' => $with(['points' => [[40, 120], [70, 255]]]), 'disk:0' => $contract], true, false) === 76, 'The legacy PWM must be the lowest first point across enabled sources.');
$derived = fcp_curve_legacy_fields([
    'cpu' => $with(['points' => [[40, 120], [70, 255]]]),
    'aux' => $contract,
    'disk:0' => $contract,
    'disk:1' => $with(['points' => [[45, 76], [65, 255]]]),
], true, false);
$check($sorted($derived) === $sorted([
    'pwm' => '76', 'low' => '30', 'high' => '50',
    'cpu_min_temp' => '40', 'cpu_max_temp' => '70', 'aux_min_temp' => '', 'aux_max_temp' => '',
    'disk_group_0_low' => '30', 'disk_group_0_high' => '50', 'disk_group_1_low' => '45', 'disk_group_1_high' => '65',
]), 'Legacy fields must follow the curve endpoints, and Min and Max Temp must stay blank for an inactive source.');

// A save accepts exactly the current editor's source set.
$editor = ['cpu' => $contract, 'aux' => $contract, 'disk:0' => $contract, 'disk:2' => $tuned];
$check(fcp_curve_post_rules(json_encode($editor), [0, 2]) === $editor, 'The editor JSON must resolve to exactly the rules it describes.');
$check($rejects(fn() => fcp_curve_post_rules(json_encode($editor + ['disk:5' => $contract]), [0, 2])), 'A rule for a group the block does not have must be rejected.');
$check($rejects(fn() => fcp_curve_post_rules(json_encode(array_diff_key($editor, ['disk:2' => 0])), [0, 2])), 'A posted group without a rule must be rejected.');
$check($rejects(fn() => fcp_curve_post_rules(json_encode(array_replace($editor, ['disk:0' => $with(['points' => [[30, 76], [30, 255]]])])), [0, 2])), 'An invalid rule must reject the save.');
$check($rejects(fn() => fcp_curve_post_rules('{broken', [0, 2])), 'Malformed curve JSON must be rejected.');
$check($rejects(fn() => fcp_curve_post_rules(null, [0, 2])), 'Missing curve settings must reject the save.');

// 7. Backups: curve settings restore only when they agree with each other and with the legacy keys.
$curveFan = [
    'custom' => 'JBOD', 'label' => 'JBOD', 'service' => '1', 'controller' => '', 'controller_identity' => '',
    'pwm' => '76', 'max' => '255', 'idle' => '0', 'low' => '30', 'high' => '50',
    'interval_sec' => '120', 'interval' => '2', 'disks' => 'sda', 'disk_group_count' => '1',
    'syslog' => '1', 'notify' => '1',
    'cpu_enable' => '0', 'cpu_sensor' => '', 'cpu_min_temp' => '', 'cpu_max_temp' => '',
    'aux_enable' => '0', 'aux_sensor' => '', 'aux_min_temp' => '', 'aux_max_temp' => '',
    'disk_group_0_name' => 'Group A', 'disk_group_0_disks' => 'sda', 'disk_group_0_low' => '30', 'disk_group_0_high' => '50',
] + fcp_curve_fields($contract, 'cpu') + fcp_curve_fields($contract, 'aux') + fcp_curve_fields($contract, 'disk_group_0');
$backupOf = fn(array $values) => json_encode(['format' => 'fanctrlplus2-config', 'version' => 1, 'files' => (object)['fanctrlplus2_JBOD.cfg' => $cfgText($values)]]);
$check(fcp_validate_config_backup($backupOf($curveFan))['fanctrlplus2_JBOD.cfg'] === $cfgText($curveFan), 'A fan with curves must validate and round-trip through a backup unchanged.');

$tunedFan = array_replace($curveFan, fcp_curve_fields($tuned, 'disk_group_0'), [
    'pwm' => '60', 'low' => '35', 'high' => '70', 'disk_group_0_low' => '35', 'disk_group_0_high' => '70',
]);
$check(!$rejects(fn() => fcp_validate_config_backup($backupOf($tunedFan))), 'A stepped curve with hysteresis and emergency settings must restore.');

$mutated = [
    'idle above the lowest curve point' => array_replace($curveFan, ['idle' => '80']),
    'legacy pwm not derived from the curves' => array_replace($curveFan, ['pwm' => '70']),
    'legacy low not derived from the curves' => array_replace($curveFan, ['low' => '31']),
    'an injected command in a curve' => array_replace($curveFan, ['disk_group_0_curve' => '30:76$(touch /tmp/fcp_curve_pwn)']),
    'a partial companion set' => array_diff_key($curveFan, ['disk_group_0_curve_mode' => 0]),
    'a curve for a group the fan does not have' => $curveFan + fcp_curve_fields($contract, 'disk_group_1'),
    'non-canonical point numbers that Bash rejects' => array_replace($curveFan, ['disk_group_0_curve' => '030:076,50:255']),
    'non-canonical hysteresis that Bash rejects' => array_replace($curveFan, ['disk_group_0_hysteresis' => '00']),
    'non-canonical emergency PWM that Bash rejects' => array_replace($curveFan, ['disk_group_0_emergency_pwm' => '025']),
    'an emergency PWM below the curve' => array_replace($curveFan, ['disk_group_0_emergency_temp' => '60', 'disk_group_0_emergency_pwm' => '200']),
];
foreach ($mutated as $label => $values) {
    $check($rejects(fn() => fcp_validate_config_backup($backupOf($values))), "A backup with $label must be rejected.");
}

// 8. Save pipeline, run as the real endpoint against a scratch configuration directory.
$tmp = sys_get_temp_dir() . '/fcp_curve_' . getmypid();
$cfgDir = "$tmp/cfg";
mkdir($cfgDir, 0700, true);
putenv("FCP_CONFIG_DIR=$cfgDir");
putenv("FCP_CONFIG_LOCK=$tmp/.config.lock");
$daemonLog = "$tmp/daemon.log";
file_put_contents("$tmp/rc_stub.sh", "#!/bin/bash\necho \"\$@\" >> " . escapeshellarg($daemonLog) . "\n");
putenv("FCP_RC_SCRIPT=$tmp/rc_stub.sh");
$docroot = realpath(__DIR__ . '/../src/usr/local/emhttp');
file_put_contents("$tmp/harness.php", <<<'PHP'
<?php
$_SERVER['DOCUMENT_ROOT'] = $argv[2];
$_POST = unserialize(file_get_contents($argv[1]));
require $argv[2] . '/plugins/fanctrlplus2/include/update.fanctrlplus2.php';
PHP);

$save = function (array $post) use ($tmp, $docroot): array {
    file_put_contents("$tmp/post.bin", serialize($post));
    $output = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$tmp/harness.php") . ' ' . escapeshellarg("$tmp/post.bin") . ' ' . escapeshellarg($docroot) . ' 2>&1');
    $reply = json_decode(trim($output), true);
    return is_array($reply) ? $reply : ['status' => 'unparsed', 'output' => $output];
};
// Builds the form post for the given blocks. A block with 'curve' posts editor JSON; a request with none is a legacy form.
$request = function (array $blocks): array {
    $post = ['#file' => [], 'order' => [], 'interval' => [], 'custom' => [], 'controller' => [], 'service' => [],
        'pwm_percent' => [], 'max_percent' => [], 'idle_percent' => [], 'cpu_enable' => [], 'cpu_sensor' => [],
        'cpu_min_temp' => [], 'cpu_max_temp' => [], 'aux_enable' => [], 'aux_min_temp' => [], 'aux_max_temp' => [],
        'disk_group_name' => [], 'disk_group_disks' => [], 'disk_group_low' => [], 'disk_group_high' => [], 'curve_config' => []];
    foreach (array_values($blocks) as $i => $block) {
        $block += ['pwm' => '30', 'max' => '100', 'idle' => '0', 'cpu' => '0', 'cpu_min' => '40', 'cpu_max' => '70',
            'groups' => [0 => ['Group A', ['sda'], '40', '60']]];
        $post['#file'][$i] = $block['file'];
        $post['order'][] = $block['file'];
        $post['custom'][$i] = $block['custom'];
        $post['interval'][$i] = '120';
        $post['controller'][$i] = '';
        $post['service'][$i] = '1';
        $post['pwm_percent'][$i] = $block['pwm'];
        $post['max_percent'][$i] = $block['max'];
        $post['idle_percent'][$i] = $block['idle'];
        $post['cpu_enable'][$i] = $block['cpu'];
        $post['cpu_sensor'][$i] = '';
        $post['cpu_min_temp'][$i] = $block['cpu_min'];
        $post['cpu_max_temp'][$i] = $block['cpu_max'];
        $post['aux_enable'][$i] = '0';
        $post['aux_min_temp'][$i] = '40';
        $post['aux_max_temp'][$i] = '70';
        foreach ($block['groups'] as $g => [$name, $disks, $low, $high]) {
            $post['disk_group_name'][$i][$g] = $name;
            $post['disk_group_disks'][$i][$g] = $disks;
            $post['disk_group_low'][$i][$g] = $low;
            $post['disk_group_high'][$i][$g] = $high;
        }
        if (array_key_exists('curve', $block)) $post['curve_config'][$i] = $block['curve'];
    }
    if ($post['curve_config'] === []) unset($post['curve_config']);
    return $post;
};
$write = function (string $name, array $values) use ($cfgDir, $cfgText) {
    file_put_contents("$cfgDir/$name", $cfgText($values));
};

$legacyFan = [
    'custom' => 'JBOD', 'label' => 'JBOD', 'service' => '1', 'controller' => '', 'controller_identity' => '',
    'pwm' => '102', 'max' => '255', 'idle' => '0', 'low' => '40', 'high' => '60',
    'interval_sec' => '120', 'interval' => '2', 'disks' => 'sda', 'disk_group_count' => '1',
    'syslog' => '1', 'notify' => '1',
    'cpu_enable' => '0', 'cpu_sensor' => '', 'cpu_min_temp' => '', 'cpu_max_temp' => '',
    'aux_enable' => '0', 'aux_sensor' => '', 'aux_min_temp' => '', 'aux_max_temp' => '',
    'disk_group_0_name' => 'Group A', 'disk_group_0_disks' => 'sda', 'disk_group_0_low' => '40', 'disk_group_0_high' => '60',
];
// Whole-percent display must not quantize unedited raw cap and idle values.
$rawLegacy = array_replace($legacyFan, ['custom' => 'Raw', 'pwm' => '76', 'max' => '100', 'idle' => '76']);
$write('fanctrlplus2_Raw.cfg', $rawLegacy);
$rawRules = fcp_curve_rules($rawLegacy);
$reply = $save($request([['file' => 'fanctrlplus2_Raw.cfg', 'custom' => 'Raw', 'pwm' => '30', 'max' => '39', 'idle' => '30', 'curve' => json_encode($rawRules)]]));
$check(($reply['status'] ?? '') === 'ok', 'Saving unchanged displayed percentages must preserve valid raw idle and cap.');
$rawSaved = parse_ini_file("$cfgDir/fanctrlplus2_Raw.cfg");
$check($rawSaved['max'] === '100' && $rawSaved['idle'] === '76', 'Unedited raw PWM values must survive percentage display rounding.');

$write('fanctrlplus2_JBOD.cfg', $legacyFan);
$cpuOnly = ['cpu' => $with(['points' => [[40, 120], [70, 255]]]), 'aux' => $contract];
$reply = $save($request([['file' => 'fanctrlplus2_JBOD.cfg', 'custom' => 'JBOD', 'cpu' => '1', 'idle' => '40', 'groups' => [], 'curve' => json_encode($cpuOnly)]]));
$cpuSaved = parse_ini_file("$cfgDir/fanctrlplus2_JBOD.cfg");
$check(($reply['status'] ?? '') === 'ok' && $cpuSaved['disk_group_count'] === '0' && $cpuSaved['disks'] === '' && $cpuSaved['pwm'] === '120', 'A CPU-only fan must save without disks or a disk curve limiting idle speed.');
$check(fcp_build_disk_groups($cpuSaved) === [] && !$rejects(fn() => fcp_validate_config_backup($backupOf($cpuSaved))), 'A CPU-only fan must reopen and back up without disk groups.');
$write('fanctrlplus2_JBOD.cfg', $legacyFan);

// A stale pre-curve form must fail before overwriting any saved configuration.
$oldBefore = file_get_contents("$cfgDir/fanctrlplus2_JBOD.cfg");
$reply = $save($request([['file' => 'fanctrlplus2_JBOD.cfg', 'custom' => 'JBOD']]));
$check(($reply['status'] ?? '') === 'error' && str_contains(strtolower($reply['message'] ?? ''), 'refresh'), 'A stale form must request a refresh instead of inventing curve settings.');
$check(file_get_contents("$cfgDir/fanctrlplus2_JBOD.cfg") === $oldBefore, 'A stale form must leave saved settings unchanged.');
file_put_contents("$cfgDir/fanctrlplus2_JBOD.cfg", $oldBefore);

// Disabled sensor rules must not impose a hidden ceiling on Idle Speed.
$disabledRules = [
    'cpu' => $with(['points' => [[40, 10], [70, 255]]]),
    'aux' => $with(['points' => [[40, 15], [70, 255]]]),
    'disk:0' => $with(['points' => [[40, 102], [60, 255]]]),
];
$reply = $save($request([['file' => 'fanctrlplus2_JBOD.cfg', 'custom' => 'JBOD', 'idle' => '30', 'curve' => json_encode($disabledRules)]]));
$check(($reply['status'] ?? '') === 'ok', 'Disabled CPU/Aux curves must not block a valid disk idle speed.');
$cfg = parse_ini_file("$cfgDir/fanctrlplus2_JBOD.cfg");
$check($cfg['pwm'] === '102' && $cfg['idle'] === '77', 'The legacy floor and idle must use enabled temperature rules.');
if (($reply['status'] ?? '') === 'ok') $check(!$rejects(fn() => fcp_validate_config_backup($backupOf($cfg))), 'Backup validation must also ignore disabled rules when checking idle.');
$reply = $save($request([['file' => 'fanctrlplus2_JBOD.cfg', 'custom' => 'JBOD', 'idle' => '30', 'cpu' => '1', 'curve' => json_encode($disabledRules)]]));
$check(($reply['status'] ?? '') === 'error', 'Enabling the low CPU curve must enforce its first point.');

// Curve save: a middle group removed client-side renumbers 0..N-1, moving each rule with its group; the inactive CPU rule is kept.
$cpuRule = $with(['points' => [[40, 120], [70, 255]]]);
$cold = $with(['points' => [[35, 90], [60, 200]]]);
$reply = $save($request([['file' => 'fanctrlplus2_JBOD.cfg', 'custom' => 'JBOD', 'pwm' => '30', 'max' => '100', 'cpu' => '0',
    'groups' => [0 => ['Hot', ['sda'], '40', '60'], 2 => ['Cold', ['sdb'], '40', '60']],
    'curve' => json_encode(['cpu' => $cpuRule, 'aux' => $contract, 'disk:0' => $contract, 'disk:2' => $cold])]]));
$check(($reply['status'] ?? '') === 'ok', 'A curve save with non-contiguous groups must succeed: ' . json_encode($reply));
$cfg = parse_ini_file("$cfgDir/fanctrlplus2_JBOD.cfg");
$reloaded = fcp_curve_rules($cfg);
$check(($cfg['disk_group_count'] ?? '') === '2' && ($cfg['disk_group_0_name'] ?? '') === 'Hot' && ($cfg['disk_group_1_name'] ?? '') === 'Cold' && ($cfg['disk_group_1_disks'] ?? '') === 'sdb',
    'Disk groups must be renumbered contiguously with their names and disks.');
$check($reloaded['disk:0'] === $contract && $reloaded['disk:1'] === $cold && $reloaded['aux'] === $contract, 'Saved group rules must reload identically under their new indexes.');
$check($reloaded['cpu'] === $cpuRule && ($cfg['cpu_min_temp'] ?? '') === '', 'An inactive CPU curve must be preserved while its source stays disabled.');
$check(($cfg['pwm'] ?? '') === '76' && ($cfg['low'] ?? '') === '30' && ($cfg['high'] ?? '') === '50' && ($cfg['disk_group_1_low'] ?? '') === '35' && ($cfg['disk_group_1_high'] ?? '') === '60',
    'Legacy pwm and endpoints must be derived from the lowest first point and the group curves.');

// A malformed curve in a later block rejects the save before the earlier block is touched.
$write('fanctrlplus2_Two.cfg', array_replace($legacyFan, ['custom' => 'Two', 'label' => 'Two', 'disk_group_0_name' => 'Group B']));
$before = [file_get_contents("$cfgDir/fanctrlplus2_JBOD.cfg"), file_get_contents("$cfgDir/fanctrlplus2_Two.cfg")];
$reply = $save($request([
    ['file' => 'fanctrlplus2_JBOD.cfg', 'custom' => 'JBOD', 'curve' => json_encode(['cpu' => $contract, 'aux' => $contract, 'disk:0' => $cold])],
    ['file' => 'fanctrlplus2_Two.cfg', 'custom' => 'Two', 'curve' => json_encode(['cpu' => $contract, 'aux' => $contract, 'disk:0' => $with(['points' => [[30, 76], [30, 255]]])])],
]));
$check(($reply['status'] ?? '') === 'error' && ($reply['block'] ?? null) === 1, 'A malformed curve in a later block must reject the save and name that block.');
$check($before === [file_get_contents("$cfgDir/fanctrlplus2_JBOD.cfg"), file_get_contents("$cfgDir/fanctrlplus2_Two.cfg")], 'A rejected save must leave every earlier block byte-for-byte unchanged.');

// Idle must fit under the lowest curve point and under the normal max; it is refused, never clamped.
$allContract = json_encode(['cpu' => $contract, 'aux' => $contract, 'disk:0' => $contract]);
$reply = $save($request([['file' => 'fanctrlplus2_JBOD.cfg', 'custom' => 'JBOD', 'idle' => '40', 'curve' => $allContract]]));
$check(($reply['status'] ?? '') === 'error' && str_contains($reply['message'] ?? '', 'Idle'), 'Idle above the lowest curve point must be refused.');
$reply = $save($request([['file' => 'fanctrlplus2_JBOD.cfg', 'custom' => 'JBOD', 'idle' => '25', 'max' => '20', 'curve' => $allContract]]));
$check(($reply['status'] ?? '') === 'error' && str_contains($reply['message'] ?? '', 'Idle'), 'Idle above the normal max must be refused.');

// An invalid cap must be rejected rather than saved as an out-of-range PWM.
$capBefore = file_get_contents("$cfgDir/fanctrlplus2_JBOD.cfg");
$reply = $save($request([
    ['file' => 'fanctrlplus2_JBOD.cfg', 'custom' => 'JBOD', 'max' => '101', 'curve' => $allContract],
    ['file' => 'fanctrlplus2_Two.cfg', 'custom' => 'Two', 'curve' => $allContract],
]));
$check(($reply['status'] ?? '') === 'error', 'A normal cap above 100% must be refused.');
$check(file_get_contents("$cfgDir/fanctrlplus2_JBOD.cfg") === $capBefore, 'An invalid cap must not overwrite saved fan settings.');
file_put_contents("$cfgDir/fanctrlplus2_JBOD.cfg", $capBefore);

// Names are checked in preflight, so a refused save never leaves a managed file changed.
$fixture = function () use ($cfgDir, $write, $legacyFan) {
    $write('fanctrlplus2_JBOD.cfg', $legacyFan);
    $write('fanctrlplus2_Two.cfg', array_replace($legacyFan, ['custom' => 'Two', 'label' => 'Two', 'disk_group_0_name' => 'Group B']));
    $write('fanctrlplus2_spare.cfg', array_replace($legacyFan, ['custom' => 'Spare', 'label' => 'Spare']));
    file_put_contents("$cfgDir/order.cfg", "order0=\"fanctrlplus2_JBOD.cfg\"\norder1=\"fanctrlplus2_Two.cfg\"\norder2=\"fanctrlplus2_spare.cfg\"\n");
};
$managed = function () use ($cfgDir): array {
    $files = [];
    foreach (glob("$cfgDir/*") as $path) $files[basename($path)] = file_get_contents($path);
    ksort($files);
    return $files;
};

// 256 disk groups is the largest fan: it saves, reloads, restores, and writes its order to the selected directory.
$groups = [];
$groupCurves = ['cpu' => $contract, 'aux' => $contract];
for ($g = 0; $g < 256; $g++) {
    $groups[$g] = ["G$g", ["d$g"], '40', '60'];
    $groupCurves["disk:$g"] = $contract;
}
$fixture();
$reply = $save($request([['file' => 'fanctrlplus2_JBOD.cfg', 'custom' => 'JBOD', 'curve' => json_encode($groupCurves), 'groups' => $groups]]));
$check(($reply['status'] ?? '') === 'ok', 'A fan with exactly 256 disk groups must save: ' . json_encode($reply));
$full = parse_ini_file("$cfgDir/fanctrlplus2_JBOD.cfg");
$check(($full['disk_group_count'] ?? '') === '256' && ($full['disk_group_255_name'] ?? '') === 'G255' && count(fcp_curve_rules($full)) === 258, 'A 256-group fan must reload every group and its curve.');
$check(!$rejects(fn() => fcp_validate_config_backup($backupOf($full))), 'A 256-group fan must restore from a backup.');
$check(file_get_contents("$cfgDir/order.cfg") === "order0=\"fanctrlplus2_JBOD.cfg\"\n", 'The saved order must be written to the selected configuration directory.');

// A 257th group is refused in preflight, so the earlier block in the same save is not written either.
$groups[256] = ['G256', ['d256'], '40', '60'];
$groupCurves['disk:256'] = $contract;
$fixture();
$before = $managed();
$reply = $save($request([
    ['file' => 'fanctrlplus2_Two.cfg', 'custom' => 'Two', 'curve' => $allContract],
    ['file' => 'fanctrlplus2_JBOD.cfg', 'custom' => 'JBOD', 'curve' => json_encode($groupCurves), 'groups' => $groups],
]));
$check(($reply['status'] ?? '') === 'error' && ($reply['block'] ?? null) === 1 && str_contains($reply['message'] ?? '', '256'), 'A fan with 257 disk groups must be refused and name its block: ' . json_encode($reply));
$check($managed() === $before, 'A refused 257-group save must leave every managed file unchanged.');

// Every name that collides with another fan, or differs from one only in case, is refused before any file changes.
$collisions = [
    'a later block takes the name of a saved fan after an earlier curve changed' => [1, [
        ['file' => 'fanctrlplus2_JBOD.cfg', 'custom' => 'JBOD', 'curve' => json_encode(['cpu' => $contract, 'aux' => $contract, 'disk:0' => $cold])],
        ['file' => 'fanctrlplus2_Two.cfg', 'custom' => 'Spare', 'curve' => $allContract],
    ]],
    'two blocks share one name' => [1, [
        ['file' => 'fanctrlplus2_JBOD.cfg', 'custom' => 'Dup', 'curve' => $allContract],
        ['file' => 'fanctrlplus2_Two.cfg', 'custom' => 'Dup', 'curve' => $allContract],
    ]],
    'two blocks share one name except for case' => [1, [
        ['file' => 'fanctrlplus2_JBOD.cfg', 'custom' => 'Dup', 'curve' => $allContract],
        ['file' => 'fanctrlplus2_Two.cfg', 'custom' => 'dup', 'curve' => $allContract],
    ]],
    'a name differs from a saved fan only in case' => [0, [
        ['file' => 'fanctrlplus2_Two.cfg', 'custom' => 'SPARE', 'curve' => $allContract],
    ]],
];
foreach ($collisions as $label => [$block, $blocks]) {
    $fixture();
    $before = $managed();
    $reply = $save($request($blocks));
    $check(($reply['status'] ?? '') === 'error' && ($reply['block'] ?? null) === $block, "A save where $label must be refused and name block $block: " . json_encode($reply));
    $check($managed() === $before, "A save where $label must leave every managed file byte-identical.");
}

// A case-only rename of the fan's own file is not a collision, and the order follows the renamed file.
$fixture();
$reply = $save($request([['file' => 'fanctrlplus2_Two.cfg', 'custom' => 'two', 'curve' => $allContract]]));
$check(($reply['status'] ?? '') === 'ok' && is_file("$cfgDir/fanctrlplus2_two.cfg") && !is_file("$cfgDir/fanctrlplus2_Two.cfg"), 'Renaming a fan to its own name in another case must succeed.');
$check(file_get_contents("$cfgDir/order.cfg") === "order0=\"fanctrlplus2_two.cfg\"\n", 'The order must follow a rename within the selected configuration directory.');
@unlink("$cfgDir/fanctrlplus2_two.cfg");
$fixture();

// The daemon runs only from the production directory, so no scratch save may stop or restart it.
$check(fcp_daemon_owns_config('/boot/config/plugins/fanctrlplus2'), 'The production configuration directory must keep restarting the daemon.');
$check(fcp_daemon_owns_config('/boot/config/plugins/fanctrlplus2/') && !fcp_daemon_owns_config('/boot/config/plugins/fanctrlplus2-old'), 'The daemon directory must match exactly, ignoring a trailing slash.');
$check(!fcp_daemon_owns_config($cfgDir), 'A scratch configuration directory must never own the daemon.');
$check(!file_exists($daemonLog), 'Saves to a scratch configuration directory must never stop or restart the daemon.');

// Export and restore carry the curve settings intact.
$backup = fcp_export_config($cfgDir);
$restoredDir = "$tmp/restored";
mkdir($restoredDir, 0700);
fcp_restore_config($restoredDir, $backup);
$check(file_get_contents("$restoredDir/fanctrlplus2_JBOD.cfg") === file_get_contents("$cfgDir/fanctrlplus2_JBOD.cfg"), 'Curve settings must survive export and restore byte for byte.');

exec('rm -rf ' . escapeshellarg($tmp));
if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "curve config tests passed\n";
