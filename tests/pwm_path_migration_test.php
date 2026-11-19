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

// Legacy /sys/class bindings name an index, not a device. The first migration
// pins controllers, their labels and sensors to the device holding it; an
// absent index is kept, and never resolved on later runs.
$legacy = "$root/legacy";
@mkdir($legacy);
$temp = "$sys/devices/platform/it87.2624/hwmon/hwmon2/temp1_input";
$classPwm = "$sys/devices/platform/it87.2624/hwmon/hwmon2/pwm2";
touch($temp);
$classPwm3 = "$sys/devices/platform/it87.2624/hwmon/hwmon2/pwm3";
$classPwm4 = "$sys/devices/platform/it87.2624/hwmon/hwmon2/pwm4";
touch($classPwm3);
touch($classPwm4);
file_put_contents("$legacy/pwm_labels.cfg", "$class/hwmon2/pwm2=Front intake\n$class/hwmon7/pwm2=Absent\n$classPwm3=Saved\n$class/hwmon2/pwm3=Old\n$class/hwmon2/pwm4=Blank\n");
file_put_contents("$legacy/fanctrlplus2_old.cfg", implode("\n", [
    'custom="old"',
    "controller=\"$class/hwmon2/pwm2\"",
    "cpu_sensor=\"$class/hwmon2/temp1_input\"",
    "aux_sensor=\"storcli:c0:roc,$class/hwmon2/temp1_input,$class/hwmon7/temp1_input\"",
    'note="a \\` b"',
]) . "\n");
// 2026.11.12 saved a class controller as its own identity, or only as its
// identity when its chip was missing at a start.
file_put_contents("$legacy/fanctrlplus2_ided.cfg", "custom=\"ided\"\ncontroller=\"$class/hwmon2/pwm3\"\ncontroller_identity=\"$class/hwmon2/pwm3\"\n");
file_put_contents("$legacy/fanctrlplus2_blank.cfg", "custom=\"blank\"\ncontroller=\"\"\ncontroller_identity=\"$class/hwmon2/pwm4\"\n");
// 2026.11.12 blanked a conflict loser and kept the class path as its identity;
// it sorts first, but the winner saved on that path keeps the device.
file_put_contents("$legacy/fanctrlplus2_aloser.cfg", "custom=\"aloser\"\ncontroller=\"\"\ncontroller_identity=\"$class/hwmon2/pwm2\"\n");
file_put_contents("$legacy/fanctrlplus2_absent.cfg", "custom=\"absent\"\ncontroller=\"$class/hwmon7/pwm2\"\n");
file_put_contents("$legacy/fanctrlplus2_twin.cfg", "custom=\"twin\"\ncontroller=\"$class/hwmon2/pwm2\"\n");
migrate_cfg_and_labels('fanctrlplus2', $legacy, "$class/hwmon*");
$cfgLegacy = parse_ini_file("$legacy/fanctrlplus2_old.cfg");
if ($cfgLegacy['controller'] !== $classPwm) {
    $failures[] = 'A /sys/class controller must be pinned to the device holding that index, got ' . $cfgLegacy['controller'];
}
$got = parse_ini_file("$legacy/fanctrlplus2_aloser.cfg")['controller'];
if ($got !== '') $failures[] = "A blanked conflict loser must not take the device from the fan saved on it, got $got";
foreach (['ided' => $classPwm3, 'blank' => $classPwm4] as $name => $want) {
    $got = parse_ini_file("$legacy/fanctrlplus2_$name.cfg")['controller'];
    if ($got !== $want) $failures[] = "A /sys/class controller saved as its identity ($name) must be pinned too, got $got";
}
// A label already saved for the device wins over the class one.
if (file("$legacy/pwm_labels.cfg", FILE_IGNORE_NEW_LINES) !== ["$classPwm=Front intake", "$class/hwmon7/pwm2=Absent", "$classPwm3=Saved", "$classPwm4=Blank"]) {
    $failures[] = "A label must follow its pinned class controller, unless the device has one; others kept. Got:\n" . file_get_contents("$legacy/pwm_labels.cfg");
}
if ($cfgLegacy['cpu_sensor'] !== $temp) {
    $failures[] = 'A /sys/class CPU sensor must be pinned to the device holding that index, got ' . $cfgLegacy['cpu_sensor'];
}
if ($cfgLegacy['aux_sensor'] !== "storcli:c0:roc,$temp,$class/hwmon7/temp1_input") {
    $failures[] = 'Aux hwmon sensors must be pinned, other sources and absent ones kept, got ' . $cfgLegacy['aux_sensor'];
}
$got = parse_ini_file("$legacy/fanctrlplus2_twin.cfg")['controller'];
if ($got !== "$class/hwmon2/pwm2") {
    $failures[] = "A /sys/class controller must not be pinned to a device another fan already drives, got $got";
}
// Later runs: an absent number that appears on another chip is not resolved.
$chip('nct6775.656', 'hwmon7', 'nct6798');
touch("$sys/devices/platform/nct6775.656/hwmon/hwmon7/temp1_input");
// NVMe hwmon sits directly under its class device, without a hwmon/ directory.
$nvme = "$sys/devices/pci0000:00/0000:01:00.0/nvme/nvme0";
@mkdir("$nvme/hwmon4", 0777, true);
touch("$nvme/hwmon4/temp1_input");
file_put_contents("$legacy/fanctrlplus2_nvme.cfg", "custom=\"nvme\"\naux_sensor=\"$nvme/hwmon2/temp1_input,,storcli:c0:roc\"\n");
// A device-path sensor follows its chip to a new hwmon number.
file_put_contents("$legacy/fanctrlplus2_moved.cfg", "custom=\"moved\"\ncpu_sensor=\"$sys/devices/platform/it87.2624/hwmon/hwmon9/temp1_input\"\n");
migrate_cfg_and_labels('fanctrlplus2', $legacy, "$class/hwmon*");
$got = parse_ini_file("$legacy/fanctrlplus2_absent.cfg");
if ($got['controller'] !== "$class/hwmon7/pwm2" || isset($got['controller_identity'])) {
    $failures[] = "A /sys/class controller absent on the first run must be kept as saved, got {$got['controller']}";
}
if (file("$legacy/pwm_labels.cfg", FILE_IGNORE_NEW_LINES)[1] !== "$class/hwmon7/pwm2=Absent") {
    $failures[] = 'A label under an absent class controller must not move on later runs.';
}
if (parse_ini_file("$legacy/fanctrlplus2_old.cfg")['aux_sensor'] !== "storcli:c0:roc,$temp,$class/hwmon7/temp1_input") {
    $failures[] = 'A /sys/class sensor absent on the first run must not be pinned later.';
}
if (parse_ini_file("$legacy/fanctrlplus2_nvme.cfg")['aux_sensor'] !== "$nvme/hwmon4/temp1_input,,storcli:c0:roc") {
    $failures[] = 'An NVMe sensor must follow its hwmon number, keeping the other entries as saved.';
}
if (strpos(file_get_contents("$legacy/fanctrlplus2_moved.cfg"), 'controller') !== false) {
    $failures[] = 'Migration must not add controller keys a configuration does not have.';
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
