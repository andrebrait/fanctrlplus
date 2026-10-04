<?php

require_once __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2/include/Common.php';

$failures = [];
$root = realpath(sys_get_temp_dir()) . '/fcp_pwm_discovery_' . getmypid();

$chip = function (string $hwmon, string $device, string $name, array $files) use ($root): void {
    @mkdir("$root/devices/$device", 0777, true);
    @mkdir("$root/class/hwmon", 0777, true);
    symlink("$root/devices/$device", "$root/class/hwmon/$hwmon");
    file_put_contents("$root/devices/$device/name", "$name\n");
    foreach ($files as $f) touch("$root/devices/$device/$f");
};
$usb = 'usb1/1-14/1-14:1.0/0003:3904:F001.0005/hwmon/hwmon5';
$chip('hwmon5', $usb, 'arctic_fan_controller',
    ['pwm1', 'pwm2', 'pwm10', 'pwm1_enable', 'pwm10_enable', 'pwm1_auto_point1_pwm']);

// Older drivers put the attributes on the parent device; hwmonN is a stub.
$legacy = 'platform/w83627hf.656';
@mkdir("$root/devices/$legacy/hwmon/hwmon2", 0777, true);
symlink("$root/devices/$legacy", "$root/devices/$legacy/hwmon/hwmon2/device");
$chip('hwmon2', "$legacy/hwmon/hwmon2", 'w83627hf', []);
unlink("$root/devices/$legacy/hwmon/hwmon2/name");
file_put_contents("$root/devices/$legacy/name", "w83627hf\n");
touch("$root/devices/$legacy/pwm1");

$pwms = list_pwm("$root/class/hwmon/hwmon*");

$expected = [
    ['chip' => 'w83627hf',              'name' => 'pwm1',  'sensor' => "$root/devices/$legacy/pwm1"],
    ['chip' => 'arctic_fan_controller', 'name' => 'pwm1',  'sensor' => "$root/devices/$usb/pwm1"],
    ['chip' => 'arctic_fan_controller', 'name' => 'pwm2',  'sensor' => "$root/devices/$usb/pwm2"],
    ['chip' => 'arctic_fan_controller', 'name' => 'pwm10', 'sensor' => "$root/devices/$usb/pwm10"],
];
// Labels and controller settings are keyed by the /sys/devices path, so a
// /sys/class/hwmon/hwmonN path would orphan every saved one.
if ($pwms !== $expected) {
    $failures[] = "PWM channels must include pwm10 and legacy-layout channels, exclude per-channel attributes,\n"
        . "sort numerically and use the resolved device path. Got:\n" . var_export($pwms, true);
}

exec('rm -rf ' . escapeshellarg($root));

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "pwm discovery tests passed\n";
