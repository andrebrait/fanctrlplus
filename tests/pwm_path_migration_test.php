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
// Two instances of the same device on one port: nothing pins either one.
$twin = "$root/devices/pci0000:00/0000:00:14.0/usb2/2-1/2-1:1.0";
$make("$twin/0003:3904:F001.000A/hwmon/hwmon9/pwm1");
$make("$twin/0003:3904:F001.000B/hwmon/hwmon10/pwm1");
if (find_moved_pwm_path("$twin/0003:3904:F001.0002/hwmon/hwmon3/pwm1") !== null) {
    $failures[] = 'An ambiguous match must not be picked.';
}
if (find_moved_pwm_path("$twin/0003:3904:F001.000A/hwmon/hwmon9/pwm1") !== "$twin/0003:3904:F001.000A/hwmon/hwmon9/pwm1") {
    $failures[] = 'A path that still exists must be kept even when it has siblings.';
}

$make("$root/class/hwmon/hwmon3/pwm2");
if (find_moved_pwm_path("$root/class/hwmon/hwmon5/pwm2") !== null) {
    $failures[] = 'A /sys/class path carries no device identity and must be left to the chip-name fallback.';
}
if (find_moved_pwm_path("$root/devices/platform/nct6775.*/hwmon/hwmon4/pwm2") !== null) {
    $failures[] = 'A saved path must not be used as a glob pattern.';
}

// The reporter's state after re-assigning by hand: stale lines for the old
// instance next to new ones for the current path.
$make("$port/0003:3904:F001.0008/hwmon/hwmon7/pwm8");
$old = "$port/0003:3904:F001.0005/hwmon/hwmon5";
$new = "$port/0003:3904:F001.0008/hwmon/hwmon7";
$cfg = "$root/cfg";
@mkdir($cfg);
file_put_contents("$cfg/pwm_labels.cfg", implode("\n", [
    '__FCP_HISTORY__=1',
    "$old/pwm9=JBOD_old",
    "$old/pwm8=Rear",
    "$new/pwm9=JBOD",
    "$port/0003:3904:F001.0003/hwmon/hwmon4/pwm9=JBOD_older",
]) . "\n");
file_put_contents("$cfg/fanctrlplus2_old.cfg", "custom=\"old\"\ncontroller=\"$old/pwm9\"\n");
file_put_contents("$cfg/fanctrlplus2_new.cfg", "custom=\"new\"\ncontroller=\"$new/pwm9\"\n");
file_put_contents("$cfg/fanctrlplus2_rear.cfg", "custom=\"rear\"\ncontroller=\"$old/pwm8\"\n");

migrate_cfg_and_labels('fanctrlplus2', $cfg);

$labels = file("$cfg/pwm_labels.cfg", FILE_IGNORE_NEW_LINES);
if ($labels !== ['__FCP_HISTORY__=1', "$new/pwm9=JBOD", "$new/pwm8=Rear"]) {
    $failures[] = "A label saved for the current path must win over a stale one migrated onto it, once. Got:\n" . implode("\n", $labels);
}
$controller = fn(string $name) => parse_ini_file("$cfg/fanctrlplus2_$name.cfg")['controller'];
if ($controller('old') !== "$old/pwm9") {
    $failures[] = 'A fan must not be migrated onto a PWM another fan already controls.';
}
if ($controller('rear') !== "$new/pwm8") {
    $failures[] = 'A fan on a re-enumerated controller must be migrated to its current path.';
}

exec('rm -rf ' . escapeshellarg($root));

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "pwm path migration tests passed\n";
