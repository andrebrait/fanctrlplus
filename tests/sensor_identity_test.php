<?php
// CPU and aux temperature sensors are saved as /sys/class/hwmon/hwmonN paths,
// and hwmonN follows probe order. These fixtures are the hwmon devices of an
// Asustor NAS, renumbered between two boots the way NVMe drives probing
// later or earlier renumber them.
require_once __DIR__.'/../src/usr/local/emhttp/plugins/fanctrlplus2/include/Common.php';
require_once __DIR__.'/../src/usr/local/emhttp/plugins/fanctrlplus2/include/ConfigBackup.php';
require_once __DIR__.'/../src/usr/local/emhttp/plugins/fanctrlplus2/include/FanBlockRender.php';

$root = realpath(sys_get_temp_dir()).'/fcp_sensor_identity_'.getmypid();
$cfg = "$root/cfg";
$class = "$root/class/hwmon";
mkdir($cfg, 0777, true);
$failures = [];
$check = function ($condition, string $message) use (&$failures) {
    if (!$condition) $failures[] = $message;
};

$devices = [
    'nvme0'   => ['pci0000:00/0000:00:1c.0/0000:01:00.0/nvme/nvme0', 'nvme', 1],
    'nvme1'   => ['pci0000:00/0000:00:1c.4/0000:02:00.0/nvme/nvme1', 'nvme', 1],
    'acpitz'  => ['virtual/thermal/thermal_zone0', 'acpitz', 1],
    'it8625'  => ['platform/asustor_it87.2608/hwmon', 'it8625', 3],
    'coretemp'=> ['platform/coretemp.0/hwmon', 'coretemp', 2],
];
// One boot: the hwmonN each device gets, in probe order.
$boot = function (array $order) use ($root, $class, $devices) {
    exec('rm -rf '.escapeshellarg("$root/devices").' '.escapeshellarg($class));
    // Each service start is a new PHP process, with nothing cached.
    clearstatcache(true);
    mkdir($class, 0777, true);
    foreach ($order as $n => $device) {
        [$parent, $name, $inputs] = $devices[$device];
        $dir = "$root/devices/$parent/hwmon$n";
        mkdir($dir, 0777, true);
        file_put_contents("$dir/name", "$name\n");
        for ($t = 1; $t <= $inputs; $t++) file_put_contents("$dir/temp{$t}_input", (string)(30000 + 1000 * $n + $t)."\n");
        symlink($dir, "$class/hwmon$n");
    }
};
$migrate = function () use ($cfg, $class) { migrate_cfg_and_labels('fanctrlplus2', $cfg, "$class/hwmon*"); };
$read = fn() => parse_ini_file("$cfg/fanctrlplus2_nas.cfg");

$boot(['nvme0', 'nvme1', 'acpitz', 'it8625', 'coretemp']);
$check(fcp_sensor_identity("$class/hwmon4/temp1_input") === 'hwmon:coretemp:temp1:platform/coretemp.0',
    'A platform chip must be identified by its name, device and input, without hwmonN.');
$check(fcp_sensor_identity("$class/hwmon2/temp1_input") === 'hwmon:acpitz:temp1:virtual/thermal/thermal_zone0',
    'A thermal zone, with its hwmon node directly below the device, must be identified the same way.');
$check(fcp_sensor_identity("$class/hwmon4/temp9_input") === null, 'An input the chip does not have has no identity.');
$check(fcp_sensor_identity("$class/hwmon4/pwm1") === null, 'Only temperature inputs have a sensor identity.');

// A configuration saved before identities existed: plain paths.
$metadata = 'disk_group_0_name="Top \` (group)"'."\n";
file_put_contents("$cfg/fanctrlplus2_nas.cfg", "custom=\"nas\"\nservice=\"1\"\ncpu_enable=\"1\"\n"
    ."cpu_sensor=\"$class/hwmon4/temp1_input\"\naux_enable=\"1\"\n"
    ."aux_sensor=\"$class/hwmon3/temp2_input,$class/hwmon2/temp1_input,custom:ambient\"\n".$metadata);
$migrate();
$saved = $read();
$check($saved['cpu_sensor'] === "$class/hwmon4/temp1_input", 'Recording an identity must not move a sensor that is where it was saved.');
$check(($saved['cpu_sensor_identity'] ?? '') === 'hwmon:coretemp:temp1:platform/coretemp.0', 'Startup must record the CPU sensor identity of an older configuration.');
$check(($saved['aux_sensor_identity'] ?? '') === 'hwmon:it8625:temp2:platform/asustor_it87.2608,hwmon:acpitz:temp1:virtual/thermal/thermal_zone0,custom:ambient',
    'Startup must record each aux identity in order, and keep a script sensor as it is.');
$check(strpos(file_get_contents("$cfg/fanctrlplus2_nas.cfg"), $metadata) !== false, 'Identity capture must not change unrelated escaped values.');
$before = file_get_contents("$cfg/fanctrlplus2_nas.cfg");
$migrate();
$check(file_get_contents("$cfg/fanctrlplus2_nas.cfg") === $before, 'A start with nothing to change must not rewrite the configuration.');

// The next boot: the NVMe drives probe last, and every other hwmonN shifts.
// hwmon4, the saved CPU path, is now an NVMe drive.
$boot(['acpitz', 'coretemp', 'it8625', 'nvme0', 'nvme1']);
$migrate();
$saved = $read();
$check($saved['cpu_sensor'] === "$class/hwmon1/temp1_input", 'The CPU sensor must follow its chip to its new hwmonN, got '.$saved['cpu_sensor']);
$check($saved['aux_sensor'] === "$class/hwmon2/temp2_input,$class/hwmon0/temp1_input,custom:ambient", 'Every aux sensor must follow its chip, got '.$saved['aux_sensor']);
$check($saved['cpu_sensor_identity'] === 'hwmon:coretemp:temp1:platform/coretemp.0', 'Renumbering must not change the saved identity.');
$check(strpos(file_get_contents("$cfg/fanctrlplus2_nas.cfg"), $metadata) !== false, 'Renumbering must preserve unrelated configuration bytes.');

// Two inputs with one identity pin neither: the sensor is not guessed.
$twin = "$root/devices/platform/coretemp.0/hwmon/hwmon7";
mkdir($twin, 0777, true);
file_put_contents("$twin/name", "coretemp\n");
file_put_contents("$twin/temp1_input", "41000\n");
symlink($twin, "$class/hwmon7");
$migrate();
$saved = $read();
$check($saved['cpu_sensor'] === 'hwmon:coretemp:temp1:platform/coretemp.0', 'An ambiguous identity must leave no readable path, got '.$saved['cpu_sensor']);
$check($saved['cpu_sensor_identity'] === 'hwmon:coretemp:temp1:platform/coretemp.0', 'An ambiguous identity must remain saved for recovery.');
exec('rm -rf '.escapeshellarg($twin).' '.escapeshellarg("$class/hwmon7"));
clearstatcache(true);
$migrate();
$check($read()['cpu_sensor'] === "$class/hwmon1/temp1_input", 'The sensor must come back once the ambiguity is gone.');

// A chip that is gone (driver not loaded) leaves its identity in place of the path.
$boot(['acpitz', 'coretemp', 'nvme0', 'nvme1']);
$migrate();
$saved = $read();
$check($saved['aux_sensor'] === "hwmon:it8625:temp2:platform/asustor_it87.2608,$class/hwmon0/temp1_input,custom:ambient",
    'A missing chip must not be replaced by whatever now has its hwmonN, got '.$saved['aux_sensor']);
$boot(['acpitz', 'coretemp', 'nvme0', 'nvme1', 'it8625']);
$migrate();
$check($read()['aux_sensor'] === "$class/hwmon4/temp2_input,$class/hwmon0/temp1_input,custom:ambient", 'A chip that is back must be found again.');

// What the settings page saves for a selection.
$identities = fcp_sensor_identity_map("$class/hwmon*");
$check(fcp_sensor_binding("$class/hwmon1/temp1_input", $identities) === 'hwmon:coretemp:temp1:platform/coretemp.0', 'A selected sensor must be saved by identity.');
$check(fcp_sensor_binding('nvidia:gpu0', $identities) === 'nvidia:gpu0', 'A tool sensor is already stable and must be kept as it is.');
$check(fcp_sensor_binding('hwmon:it8625:temp2:platform/asustor_it87.2608', []) === 'hwmon:it8625:temp2:platform/asustor_it87.2608',
    'Saving an unresolved sensor must keep its identity.');

// The settings page keeps a saved sensor it cannot list selected, instead of
// selecting the first one listed and saving that.
$block = render_fan_block(['file' => 'fanctrlplus2_nas.cfg', 'custom' => 'nas', 'cpu_enable' => '1', 'aux_enable' => '1',
    'cpu_sensor' => 'hwmon:coretemp:temp1:platform/coretemp.0', 'aux_sensor' => 'custom:ambient'],
    0, [], [], [], ["$class/hwmon1/temp1_input" => 'coretemp - Package id 0 (45°C)'], []);
$check(preg_match('#<option value="hwmon:coretemp:temp1:platform/coretemp.0" selected>Not found: coretemp - temp1 \(platform/coretemp.0\)</option>#', $block) === 1,
    'An unresolved CPU sensor must stay selected and say it was not found.');
$check(preg_match('#<optgroup label="Unavailable">\s*<option value="custom:ambient" selected>No reading: custom:ambient</option>#', $block) === 1,
    'An aux sensor without a reading must stay selected.');

// Backups carry the identities.
$text = "custom=\"nas\"\ncpu_sensor=\"hwmon:coretemp:temp1:platform/coretemp.0\"\ncpu_sensor_identity=\"hwmon:coretemp:temp1:platform/coretemp.0\"\n"
    ."aux_sensor=\"/sys/class/hwmon/hwmon2/temp2_input,custom:ambient\"\naux_sensor_identity=\"hwmon:it8625:temp2:platform/asustor_it87.2608,custom:ambient\"\n";
$rejects = function (string $text): bool {
    try { fcp_validate_fan_config($text); return false; } catch (InvalidArgumentException $e) { return true; }
};
$check(!$rejects($text), 'A configuration with sensor identities must restore from a backup.');
$check($rejects("custom=\"nas\"\ncpu_sensor_identity=\"hwmon:coretemp:temp1:platform/../../etc\"\n"), 'An identity must not climb out of /sys/devices.');
$check($rejects("custom=\"nas\"\naux_sensor_identity=\"hwmon:coretemp:temp1:platform/coretemp.0 x\"\n"), 'An identity must hold only device path characters.');
$check($rejects("custom=\"nas\"\ncpu_sensor_identity=\"custom:ambient\"\n"), 'A script is an aux sensor, not a CPU one.');

exec('rm -rf '.escapeshellarg($root));

if ($failures) {
    fwrite(STDERR, implode("\n", $failures)."\n");
    exit(1);
}

echo "sensor identity tests passed\n";
