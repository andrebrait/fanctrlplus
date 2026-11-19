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
    $failures[] = 'A /sys/class path carries no device identity, so it cannot be followed to another index.';
}
if (find_moved_pwm_path("$root/devices/platform/nct6775.*/hwmon/hwmon4/pwm2") !== null) {
    $failures[] = 'A saved path must not be used as a glob pattern.';
}

// The reporter's state after renumbering twice and re-assigning by hand:
// stale lines for older instances next to new ones for the current path.
$make("$port/0003:3904:F001.0008/hwmon/hwmon7/pwm8");
$make("$port/0003:3904:F001.0008/hwmon/hwmon7/pwm7");
$older = "$port/0003:3904:F001.0003/hwmon/hwmon4";
$old = "$port/0003:3904:F001.0005/hwmon/hwmon5";
$new = "$port/0003:3904:F001.0008/hwmon/hwmon7";
$cfg = "$root/cfg";
@mkdir($cfg);
file_put_contents("$cfg/pwm_labels.cfg", implode("\n", [
    '__FCP_HISTORY__=1',
    "$old/pwm9=JBOD_old",
    "$old/pwm8=Rear",
    "$new/pwm8=",
    "$new/pwm9=JBOD",
    "$older/pwm9=JBOD_older",
    "$older/pwm7=Top_older",
    "$old/pwm7=Top",
]) . "\n");
file_put_contents("$cfg/fanctrlplus2_old.cfg", "custom=\"old\"\ncontroller=\"$old/pwm9\"\n");
file_put_contents("$cfg/fanctrlplus2_new.cfg", "custom=\"new\"\ncontroller=\"$new/pwm9\"\n");
file_put_contents("$cfg/fanctrlplus2_rear.cfg", "custom=\"rear\"\ncontroller=\"$old/pwm8\"\n");
file_put_contents("$cfg/fanctrlplus2_rear2.cfg", "custom=\"rear2\"\ncontroller=\"$older/pwm8\"\n");

migrate_cfg_and_labels('fanctrlplus2', $cfg);

$labels = file("$cfg/pwm_labels.cfg", FILE_IGNORE_NEW_LINES);
if ($labels !== ['__FCP_HISTORY__=1', "$new/pwm9=JBOD", "$new/pwm8=Rear", "$new/pwm8=", "$new/pwm7=Top"]) {
    $failures[] = "Each current path must keep one label: the one saved for it, else the last stale one. Got:\n" . implode("\n", $labels);
}
$controller = fn(string $name) => parse_ini_file("$cfg/fanctrlplus2_$name.cfg")['controller'];
if ($controller('old') !== "$old/pwm9") {
    $failures[] = 'A fan must not be migrated onto a PWM another fan already controls.';
}
if ($controller('rear') !== "$new/pwm8" || $controller('rear2') !== "$older/pwm8") {
    $failures[] = 'Exactly one of two stale fans on the same PWM must be migrated to its current path.';
}

// #41: a chip whose driver has not loaded yet. Another chip now holds its
// hwmon index and has a channel of the same name; the fan must not move there.
$sys = "$root/chips";
$class = "$sys/class/hwmon";
@mkdir($class, 0777, true);
$chip = function (string $device, string $index, string $name) use ($sys, $class): string {
    $dir = "$sys/devices/platform/$device/hwmon/$index";
    @mkdir($dir, 0777, true);
    file_put_contents("$dir/name", "$name\n");
    touch("$dir/pwm2");
    symlink($dir, "$class/$index");
    return "$dir/pwm2";
};
$other = $chip('nct6775.656', 'hwmon2', 'nct6798');
$it87 = "$sys/devices/platform/it87.2624/hwmon/hwmon2/pwm2";
$boot = "$root/boot";
@mkdir($boot);
file_put_contents("$boot/fanctrlplus2_array.cfg", "custom=\"array\"\ncontroller=\"$it87\"\ncontroller_identity=\"$it87\"\n");
// Configurations an older version blanked keep their binding in the identity.
file_put_contents("$boot/fanctrlplus2_blanked.cfg", "custom=\"blanked\"\ncontroller=\"\"\ncontroller_identity=\"$it87\"\n");
migrate_cfg_and_labels('fanctrlplus2', $boot, "$class/hwmon*");
if (parse_ini_file("$boot/fanctrlplus2_array.cfg")['controller'] !== $it87) {
    $failures[] = "A missing chip's fan must keep its saved controller, not move to another chip's channel.";
}
$blanked = parse_ini_file("$boot/fanctrlplus2_blanked.cfg");
if ($blanked['controller'] !== '' || $blanked['controller_identity'] !== $it87) {
    $failures[] = 'A blanked controller must stay blank (it may have lost a conflict) and keep its identity, not move to another chip.';
}
unlink("$class/hwmon2");
rename("$sys/devices/platform/nct6775.656/hwmon/hwmon2", "$sys/devices/platform/nct6775.656/hwmon/hwmon3");
symlink("$sys/devices/platform/nct6775.656/hwmon/hwmon3", "$class/hwmon3");
$loaded = $chip('it87.2624', 'hwmon2', 'it8686');
migrate_cfg_and_labels('fanctrlplus2', $boot, "$class/hwmon*");
if (parse_ini_file("$boot/fanctrlplus2_array.cfg")['controller'] !== $loaded) {
    $failures[] = 'Once its chip loads, the fan must bind to it again.';
}

// Legacy /sys/class bindings name an index, not a device. A controller is
// never resolved through one; a sensor is pinned to the device holding it.
$legacy = "$root/legacy";
@mkdir($legacy);
$temp = "$sys/devices/platform/it87.2624/hwmon/hwmon2/temp1_input";
touch($temp);
file_put_contents("$legacy/fanctrlplus2_old.cfg", implode("\n", [
    'custom="old"',
    "controller=\"$class/hwmon2/pwm2\"",
    "cpu_sensor=\"$class/hwmon2/temp1_input\"",
    "aux_sensor=\"storcli:c0:roc,$class/hwmon2/temp1_input,$class/hwmon7/temp1_input\"",
    'note="a \\` b"',
]) . "\n");
migrate_cfg_and_labels('fanctrlplus2', $legacy, "$class/hwmon*");
$cfgLegacy = parse_ini_file("$legacy/fanctrlplus2_old.cfg");
if ($cfgLegacy['controller'] !== "$class/hwmon2/pwm2") {
    $failures[] = 'A /sys/class controller must be kept as saved, for the user to pick again, not resolved to the chip at that index.';
}
if ($cfgLegacy['cpu_sensor'] !== $temp) {
    $failures[] = 'A /sys/class CPU sensor must be pinned to the device holding that index, got ' . $cfgLegacy['cpu_sensor'];
}
if ($cfgLegacy['aux_sensor'] !== "storcli:c0:roc,$temp,$class/hwmon7/temp1_input") {
    $failures[] = 'Aux hwmon sensors must be pinned, other sources and absent ones kept, got ' . $cfgLegacy['aux_sensor'];
}
// A class controller older saves recorded as its own identity is kept too.
file_put_contents("$legacy/fanctrlplus2_ided.cfg", "custom=\"ided\"\ncontroller=\"$class/hwmon2/pwm2\"\ncontroller_identity=\"$class/hwmon2/pwm2\"\n");
// A device-path sensor follows its chip to a new hwmon number.
file_put_contents("$legacy/fanctrlplus2_moved.cfg", "custom=\"moved\"\ncpu_sensor=\"$sys/devices/platform/it87.2624/hwmon/hwmon9/temp1_input\"\n");
migrate_cfg_and_labels('fanctrlplus2', $legacy, "$class/hwmon*");
if (parse_ini_file("$legacy/fanctrlplus2_ided.cfg")['controller'] !== "$class/hwmon2/pwm2") {
    $failures[] = 'A /sys/class controller saved with itself as identity must be kept, not blanked or resolved.';
}
if (parse_ini_file("$legacy/fanctrlplus2_moved.cfg")['cpu_sensor'] !== $temp) {
    $failures[] = 'A device-path sensor must follow its chip when its hwmon number changes.';
}
if (strpos(file_get_contents("$legacy/fanctrlplus2_old.cfg"), 'note="a \\` b"') === false) {
    $failures[] = 'Pinning sensors must not rewrite unrelated configuration lines.';
}

exec('rm -rf ' . escapeshellarg($root));

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "pwm path migration tests passed\n";
