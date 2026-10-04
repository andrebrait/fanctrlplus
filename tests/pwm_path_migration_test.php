<?php

require_once __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2/include/Common.php';

$failures = [];
$root = sys_get_temp_dir() . '/fcp_pwm_migration_' . getmypid();
$port = "$root/devices/pci0000:00/0000:00:14.0/usb1/1-14/1-14:1.0";

$make = function (string $path): void {
    @mkdir(dirname($path), 0777, true);
    touch($path);
};
$make("$root/devices/platform/nct6775.656/hwmon/hwmon2/pwm2");
$make("$port/0003:3904:F001.0008/hwmon/hwmon7/pwm9");
$make("$root/devices/pci0000:00/0000:00:14.0/usb1/1-9/1-9:1.0/0003:3904:F001.0009/hwmon/hwmon8/pwm9");

// The issue's case: the HID instance and the hwmon index both changed.
$found = find_moved_pwm_path("$port/0003:3904:F001.0005/hwmon/hwmon5/pwm9");
if ($found !== "$port/0003:3904:F001.0008/hwmon/hwmon7/pwm9") {
    $failures[] = 'A re-enumerated USB fan controller must be found on its own port, got ' . var_export($found, true);
}

$found = find_moved_pwm_path("$root/devices/platform/nct6775.656/hwmon/hwmon4/pwm2");
if ($found !== "$root/devices/platform/nct6775.656/hwmon/hwmon2/pwm2") {
    $failures[] = 'A renumbered hwmon index must resolve to the same platform device, got ' . var_export($found, true);
}

// An identical hub on another port is a different device.
if (find_moved_pwm_path("$root/devices/pci0000:00/0000:00:14.0/usb1/1-3/1-3:1.0/0003:3904:F001.0005/hwmon/hwmon5/pwm9") !== null) {
    $failures[] = 'A controller must not be matched to an identical one on a different USB port.';
}
if (find_moved_pwm_path("$port/0003:3904:F001.0005/hwmon/hwmon5/pwm3") !== null) {
    $failures[] = 'A channel the device no longer has must not match.';
}
if (find_moved_pwm_path('/sys/class/hwmon/hwmon5/pwm2') !== null) {
    $failures[] = 'A /sys/class path carries no device identity and must be left to the chip-name fallback.';
}

exec('rm -rf ' . escapeshellarg($root));

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "pwm path migration tests passed\n";
