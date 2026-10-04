<?php

require_once __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2/include/Common.php';

$failures = [];
$root = sys_get_temp_dir() . '/fcp_pwm_discovery_' . getmypid();
$device = "$root/devices/usb1/1-14/1-14:1.0/0003:3904:F001.0005/hwmon/hwmon5";
@mkdir($device, 0777, true);
@mkdir("$root/class/hwmon", 0777, true);
symlink($device, "$root/class/hwmon/hwmon5");
file_put_contents("$device/name", "arctic_fan_controller\n");
foreach (['pwm1', 'pwm2', 'pwm10', 'pwm1_enable', 'pwm10_enable', 'pwm1_auto_point1_pwm'] as $f) {
    touch("$device/$f");
}

$pwms = list_pwm("$root/class/hwmon/hwmon*");

$names = array_column($pwms, 'name');
if ($names !== ['pwm1', 'pwm2', 'pwm10']) {
    $failures[] = 'Channels must include pwm10, exclude per-channel attributes and sort numerically, got ' . implode(',', $names);
}
// Labels and controller settings are keyed by the /sys/devices path; a
// /sys/class/hwmon/hwmonN path would orphan every saved one.
if (($pwms[0]['sensor'] ?? null) !== "$device/pwm1") {
    $failures[] = 'The sensor must be the resolved device path, got ' . var_export($pwms[0]['sensor'] ?? null, true);
}

exec('rm -rf ' . escapeshellarg($root));

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "pwm discovery tests passed\n";
