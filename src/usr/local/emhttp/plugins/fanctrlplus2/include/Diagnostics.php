<?php
// Every exported value is rebuilt from a strict allowlist; arbitrary text is
// never copied. Hardware characteristics can still identify a machine.
require_once __DIR__.'/ConfigBackup.php';

const FCP_DIAG_MAX_LOG_BYTES = 2097152;
const FCP_DIAG_MAX_EVENTS = 1000;

// Numeric cfg settings that may be reported, with the range each may take.
const FCP_DIAG_SETTINGS = [
    'service'=>[0,1], 'syslog'=>[0,1], 'notify'=>[0,1], 'log_enable'=>[0,1], 'cpu_enable'=>[0,1], 'aux_enable'=>[0,1],
    'pwm'=>[0,255], 'max'=>[0,255], 'idle'=>[0,255], 'idle_percent'=>[0,100],
    'low'=>[-100,300], 'high'=>[-100,300], 'cpu_min_temp'=>[-100,300], 'cpu_max_temp'=>[-100,300],
    'aux_min_temp'=>[-100,300], 'aux_max_temp'=>[-100,300],
    'interval'=>[1,1440], 'interval_sec'=>[1,86400], 'disk_group_count'=>[0,256],
];

// One syslog line from this plugin. The host is matched but never captured.
const FCP_DIAG_LOG_LINE = '/^(?<time>(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) [ 1-3]\d \d\d:\d\d:\d\d|\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)(?:\.\d{1,9})?(?:Z|[+-]\d\d:\d\d)? \S+ fanctrlplus2(?:\[\d{1,10}\])?: (?<msg>[^\r\n]*)$/D';

// The only messages that become events. A message must match one of these
// whole; the named groups are the only text that can be read out of it, and
// each is a number or an enum. A fan name is looked up, never copied.
const FCP_DIAG_EVENTS = [
    'pwm_write' => '/^(?<manual>Manual Run )?\[(?<name>[^\]\r\n]{1,255})\] Temp=(?<temp>-?\d{1,3}|\*)°C(?: \((?<src>CPU|Aux|Idle|Disk)(?:: [^)\r\n]{0,255})?\))? +→ PWM=(?<pwm>\d{1,3}) → RPM=(?<rpm>\d{1,5}|\?)?$/D',
    'pwm_write_failed' => '/^\[(?<name>[^\]\r\n]{1,255})\] Could not write PWM=(?<pwm>\d{1,3}) to \S+; keeping the previous output and trying again\.$/D',
    'curve_state_unavailable' => '/^\[(?<name>[^\]\r\n]{1,255})\] Curve state unavailable; continuing fan control with in-memory state\.$/D',
    'curve_state_unsaved' => '/^\[(?<name>[^\]\r\n]{1,255})\] Curve state could not be saved; keeping it in memory until it can\.$/D',
    'started' => '/^Started \[(?<name>[^\]\r\n]{1,255})\] from \S+$/D',
    'stopped_all' => '/^All fanctrlplus2 stopped$/D',
    'stopped_control' => '/^Stopped control of pwm(?<channel>\d{1,3}); set to 50% \(manual mode\) to ensure sufficient airflow and avoid overheating$/D',
    'manual_failed' => '/^Manual Run \[(?<name>[^\]\r\n]{1,255})\]: [^\r\n]{1,768}$/D',
];

const FCP_DIAG_README = <<<'TXT'
FanCtrl Plus 2 diagnostics

Everything here is restricted to numbers, known hardware names and fixed values;
arbitrary text is excluded. Left out entirely: fan names and labels,
disk names, serials and identifiers, host and network names, file paths,
custom sensor names and scripts (never run, never read), raw logs, credentials.

fans.json      Fan settings and curves, numbered for this report.
hardware.json  Current hwmon PWM, mode and RPM readings.
events.json    Fan-control events from the system log: numbers and event types.
manifest.json  Format version, plugin version, kernel release, time of export.

Chip names, channel numbers and the combination of values can still make a
system recognisable. Review the files before sharing them.
TXT;

// A bounded integer from text, or null. Anything else is dropped, not coerced.
function fcp_diag_int(mixed $text, int $min, int $max): ?int {
    if (!is_string($text) || !preg_match('/^(?:0|-?[1-9]\d{0,8})$/D', $text)) return null;
    $number = (int)$text;
    return $number >= $min && $number <= $max ? $number : null;
}

function fcp_diag_read(string $file, int $bytes): string {
    return is_file($file) ? trim((string)@file_get_contents($file, false, null, 0, $bytes)) : '';
}

// Hardware readings, and the real path of every PWM file mapped to its
// anonymous identity so a fan's saved controller can be matched to a reading.
function fcp_diag_hwmon(string $hwmonGlob): array {
    $max = ['pwm'=>255, 'enable'=>9, 'mode'=>9, 'rpm'=>99999];
    $chips = []; $controllers = [];
    foreach (glob($hwmonGlob) ?: [] as $hwmon) {
        $id = basename($hwmon);
        if (!preg_match('/^hwmon\d{1,4}$/D', $id)) continue;
        // Older drivers keep their attributes on the parent device, which then names the chip.
        $chip = null; $channels = [];
        foreach ([$hwmon, "$hwmon/device"] as $dir) {
            $name = fcp_diag_read("$dir/name", 64);
            if (preg_match('/^(?:nct\d{4}[a-z]{0,4}|it\d{4}[a-z]{0,4}|w\d{5}[a-z]{0,4}|f\d{5}[a-z]{0,4}|dell_smm|corsaircpro|corsairpsu|nzxtsmart2|coretemp|k10temp|acpitz|nvme)$/D', $name)) $chip = $name;
        }
        foreach ([$hwmon, "$hwmon/device"] as $dir) {
            foreach (glob("$dir/*") ?: [] as $file) {
                $base = basename($file);
                if (preg_match('/^pwm(\d{1,3})(?:_(enable|mode))?$/D', $base, $m)) { $n = (int)$m[1]; $key = $m[2] ?? 'pwm'; }
                elseif (preg_match('/^fan(\d{1,3})_input$/D', $base, $m)) { $n = (int)$m[1]; $key = 'rpm'; }
                else continue;
                $channels[$n][$key] ??= fcp_diag_int(fcp_diag_read($file, 16), 0, $max[$key]);
                if ($key === 'pwm' && ($real = realpath($file)) !== false) $controllers[$real] = ['hwmon'=>$id, 'chip'=>$chip, 'pwm'=>$n];
            }
        }
        if ($channels === []) continue;
        ksort($channels);
        $rows = [];
        foreach ($channels as $n => $values) $rows[] = array_merge(['channel'=>$n, 'pwm'=>null, 'enable'=>null, 'mode'=>null, 'rpm'=>null], $values);
        $row = ['id'=>$id, 'chip'=>$chip, 'channels'=>$rows];
        if (in_array($chip, ['nct6683', 'nct6686', 'nct6687'], true)) {
            $row['fan_control_watchdog_present'] = is_file("$hwmon/fan_control_watchdog") || is_file("$hwmon/device/fan_control_watchdog");
        }
        $chips[] = $row;
    }
    usort($chips, fn($a, $b) => strnatcmp($a['id'], $b['id']));
    return [$chips, $controllers];
}

// The driver option behind the stuck-fan notice. Parameters are fixed enums.
function fcp_diag_nct6687(string $moduleDir): array {
    $fanConfig = fcp_diag_read("$moduleDir/parameters/fan_config", 32);
    $brute = fcp_diag_read("$moduleDir/parameters/msi_fan_brute_force", 8);
    return [
        'loaded' => is_dir($moduleDir),
        'fan_config' => in_array($fanConfig, ['default', 'msi_alt1'], true) ? $fanConfig : null,
        'msi_fan_brute_force' => in_array($brute, ['Y', 'N', '0', '1'], true) ? $brute : null,
    ];
}

// One fan, anonymised. $cfg is the parsed configuration or null when it did not parse.
function fcp_diag_fan(?array $cfg, int $index, array $controllers, string $sensorDir): array {
    if ($cfg === null) return ['index'=>$index, 'parsed'=>false];
    $fan = ['index'=>$index, 'parsed'=>true];
    foreach (FCP_DIAG_SETTINGS as $key => [$min, $max]) {
        $value = fcp_diag_int($cfg[$key] ?? null, $min, $max);
        if ($value !== null) $fan[$key] = $value;
    }

    // Disk identifiers hold model and serial: only how many disks, never which.
    $disks = fn(string $list): int => $list === '' ? 0 : min(999, substr_count($list, ',') + 1);
    if (isset($cfg['disks'])) $fan['disks'] = $disks($cfg['disks']);
    $fan['disk_groups'] = [];
    for ($g = 0, $groups = fcp_curve_count($cfg); $g < $groups; $g++) $fan['disk_groups'][] = ['disks'=>$disks($cfg["disk_group_{$g}_disks"] ?? '')];

    $fan['cpu_sensor_configured'] = ($cfg['cpu_sensor'] ?? '') !== '';
    $kinds = array_fill_keys(['hwmon', 'custom', 'storcli', 'nvidia', 'mlx', 'other'], 0);
    $missing = 0;
    foreach (explode(',', $cfg['aux_sensor'] ?? '') as $sensor) {
        if ($sensor === '') continue;
        $kind = str_starts_with($sensor, '/sys/') ? 'hwmon' : (preg_match('/^(custom|storcli|nvidia|mlx):/', $sensor, $m) ? $m[1] : 'other');
        $kinds[$kind]++;
        // Only whether the script file exists; its name and contents are never reported or read.
        if ($kind === 'custom') {
            $name = substr($sensor, 7);
            if (!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/D', $name) || !is_file("$sensorDir/$name")) $missing++;
        }
    }
    $fan['aux_sensors'] = $kinds;
    $fan['custom_sensors_missing'] = $missing;

    $path = $cfg['controller'] ?? '';
    $real = $path === '' ? false : realpath($path);
    $fan['controller'] = $real !== false && isset($controllers[$real])
        ? $controllers[$real] + ['present'=>true]
        : ['present'=>false] + (preg_match('/pwm(\d{1,3})$/D', $path, $m) ? ['pwm'=>(int)$m[1]] : []);
    $fan['controller']['binding'] = str_starts_with($cfg['controller_identity'] ?? '', 'usb:') ? 'usb' : 'path';

    // Validated and rebuilt as integers and the linear/stepped enum.
    try { $fan['curves'] = fcp_curve_rules($cfg); }
    catch (InvalidArgumentException) { $fan['curves'] = null; }
    return $fan;
}

// Strictly matched events from syslog text. $names maps a configured fan name to
// its anonymous index; an unknown name yields a null index, never the name.
function fcp_diag_events(string $log, array $names): array {
    $events = [];
    foreach (explode("\n", $log) as $line) {
        $line = rtrim($line, "\r");
        if (strlen($line) > 1024 || !preg_match(FCP_DIAG_LOG_LINE, $line, $l)) continue;
        foreach (FCP_DIAG_EVENTS as $type => $pattern) {
            if (!preg_match($pattern, $l['msg'], $m, PREG_UNMATCHED_AS_NULL)) continue;
            $event = ['time'=>$l['time'], 'type'=>$type];
            if (isset($m['channel'])) $event['channel'] = (int)$m['channel'];
            if (array_key_exists('name', $m)) $event['fan'] = $names[$m['name']] ?? null;
            if (array_key_exists('src', $m)) {
                $temp = $m['temp'] === '*' ? null : (int)$m['temp'];
                if ($temp !== null && ($temp < -100 || $temp > 300)) continue 2;
                $event += ['manual'=>$m['manual'] !== null, 'source'=>$m['src'] === null ? null : strtolower($m['src']), 'temp_c'=>$temp];
            }
            if (array_key_exists('pwm', $m)) {
                if ((int)$m['pwm'] > 255) continue 2;
                $event['pwm'] = (int)$m['pwm'];
            }
            if (array_key_exists('rpm', $m)) $event['rpm'] = $m['rpm'] !== null && ctype_digit($m['rpm']) ? (int)$m['rpm'] : null;
            $events[] = $event;
            break;
        }
    }
    return array_slice($events, -FCP_DIAG_MAX_EVENTS);
}

function fcp_diag_log_tail(string $file): string {
    $size = is_file($file) ? (int)@filesize($file) : 0;
    return $size > 0 ? (string)@file_get_contents($file, false, null, max(0, $size - FCP_DIAG_MAX_LOG_BYTES), FCP_DIAG_MAX_LOG_BYTES) : '';
}

function fcp_diag_json(array $document): string {
    return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
}

function fcp_diag_zip(array $files): string {
    if (!class_exists('ZipArchive')) throw new RuntimeException('ZIP support is unavailable.');
    $path = tempnam(sys_get_temp_dir(), 'fcp-diag-');
    if ($path === false) throw new RuntimeException('Could not create diagnostics.');
    $zip = new ZipArchive();
    $opened = false;
    try {
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true;
        if (!$opened) throw new RuntimeException('Could not create diagnostics.');
        foreach ($files as $name => $content) {
            if (!$zip->addFromString($name, $content)) throw new RuntimeException('Could not create diagnostics.');
        }
        $closed = $zip->close();
        $opened = false;
        if (!$closed) throw new RuntimeException('Could not create diagnostics.');
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('Could not create diagnostics.');
        return $bytes;
    } finally {
        if ($opened) $zip->close();
        @unlink($path);
    }
}

// Never throws a message that holds user data: fixed RuntimeExceptions pass
// through, anything else becomes a generic failure.
function fcp_diagnostics_zip(
    string $cfgDir,
    string $hwmonGlob = '/sys/class/hwmon/hwmon*',
    string $syslog = '/var/log/syslog',
    string $moduleDir = '/sys/module/nct6687'
): string {
    try {
        $texts = []; $skipped = 0;
        if (is_dir($cfgDir)) {
            $lock = fcp_config_lock($cfgDir, LOCK_SH);
            try {
                foreach (glob("$cfgDir/fanctrlplus2_*.cfg") ?: [] as $path) {
                    $name = basename($path);
                    if (preg_match('/^fanctrlplus2_temp_\d+\.cfg$/D', $name)) continue;
                    if (!fcp_backup_fan_name($name) || is_link($path) || !is_file($path) ||
                        count($texts) >= FCP_BACKUP_MAX_FILES || @filesize($path) > FCP_BACKUP_MAX_BYTES ||
                        ($text = @file_get_contents($path)) === false) { $skipped++; continue; }
                    $texts[$name] = $text;
                }
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
        [$chips, $controllers] = fcp_diag_hwmon($hwmonGlob);
        $fans = []; $names = [];
        foreach ($texts as $file => $text) {
            if (!fcp_backup_fan_name($file)) continue;
            try { $cfg = fcp_backup_assignments($text); } catch (InvalidArgumentException) { $cfg = null; }
            if (isset($cfg['custom'])) $names[$cfg['custom']] = count($fans);
            $fans[] = fcp_diag_fan($cfg, count($fans), $controllers, "$cfgDir/sensors.d");
        }
        $events = fcp_diag_events(fcp_diag_log_tail($syslog), $names);
        $kernel = php_uname('r');
        $plugin = (string)@file_get_contents(dirname(rtrim($cfgDir, '/')).'/fanctrlplus2.plg', false, null, 0, 8192);

        return fcp_diag_zip([
            'README.txt' => FCP_DIAG_README."\n",
            'manifest.json' => fcp_diag_json([
                'format'=>'fanctrlplus2-diagnostics', 'version'=>1, 'generated_utc'=>gmdate('Y-m-d\TH:i:s\Z'),
                'plugin_version'=>preg_match('/^\s*version="(\d{4}\.\d{2}\.\d{2}[a-z]?)"/m', $plugin, $m) ? $m[1] : null,
                'kernel'=>preg_match('/^\d+\.\d+\.\d+(?:-Unraid)?$/D', $kernel) ? $kernel : null,
            ]),
            'fans.json' => fcp_diag_json([
                'skipped_configs'=>$skipped,
                'custom_sensor_scripts'=>count(array_filter(glob("$cfgDir/sensors.d/*") ?: [], 'is_file')),
                'fans'=>$fans,
            ]),
            'hardware.json' => fcp_diag_json(['chips'=>$chips, 'nct6687'=>fcp_diag_nct6687($moduleDir)]),
            'events.json' => fcp_diag_json(['events'=>$events]),
        ]);
    } catch (RuntimeException $error) {
        throw $error;
    } catch (Throwable) {
        throw new RuntimeException('Diagnostics could not be generated.');
    }
}
