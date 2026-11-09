<?php
// The frozen-sensor conclusion on the settings page, and its alert switch.
$sourceRoot = __DIR__.'/../src/usr/local/emhttp/plugins/fanctrlplus2';
require_once "$sourceRoot/include/Common.php";
require_once "$sourceRoot/include/ConfigBackup.php";
require_once "$sourceRoot/include/FanBlockRender.php";

$root = sys_get_temp_dir().'/fcp_frozen_state_'.getmypid();
mkdir($root, 0777, true);
$failures = [];
$check = function ($condition, string $message) use (&$failures) {
    if (!$condition) $failures[] = $message;
};

$acpi = '/sys/class/hwmon/hwmon2/temp1_input';
$now = 1800000000;
$since = $now - 6 * 3600 - 600;
file_put_contents("$root/frozen_Bay", implode("\n", [
    "$acpi|27|$since|$now|cpu|48|71",
    "cpu|41|$since|$now|disk:0|30|42",
    "custom:old|30|$since|".($now - 3600).'|cpu|48|71',
    'garbage|line',
    "custom:x|27|$since|now|cpu|48|71",
])."\n");
$frozen = fcp_frozen_sensors("$root/frozen_Bay", 540, $now);
$check(array_keys($frozen) === [$acpi, 'cpu'], 'Only well-formed conclusions the loop renewed recently are shown, got '.json_encode(array_keys($frozen)));
$check(($frozen[$acpi]['lo'] ?? null) === 48 && ($frozen[$acpi]['hi'] ?? null) === 71 && ($frozen[$acpi]['other'] ?? '') === 'cpu', 'A conclusion keeps the source that moved and its range.');
$check(fcp_frozen_sensors("$root/none", 540, $now) === [], 'A fan without a published file has no conclusion.');
$check(fcp_frozen_text($frozen[$acpi], 'CPU') === 'Unchanged at 27 °C for 6 h while CPU varied 48–71 °C: may be a fixed placeholder.',
    'The state says what was seen, for how long, and against what.');

$cfg = ['file' => 'fanctrlplus2_Bay.cfg', 'custom' => 'Bay', 'cpu_enable' => '1', 'aux_enable' => '1',
    'cpu_sensor' => '/sys/class/hwmon/hwmon4/temp1_input', 'aux_sensor' => "$acpi,custom:ambient",
    'disk_group_count' => '1', 'disk_group_0_name' => 'NVMe', 'disk_group_0_disks' => 'nvme0'];
$cpu = ['/sys/class/hwmon/hwmon4/temp1_input' => 'coretemp - Package id 0 (55°C)'];
$aux = ['acpitz' => [['path' => $acpi, 'label' => 'acpitz - temp1 (27.8°C)']], 'Custom Sensors' => [['path' => 'custom:ambient', 'label' => 'ambient (24°C)']]];
$block = render_fan_block($cfg, 0, [], [], [], $cpu, $aux, $frozen);
$check(str_contains($block, '<span class="fcp-sensor-state" role="status">Unchanged at 41 °C for 6 h while Disk: NVMe varied 30–42 °C: may be a fixed placeholder.</span>'),
    'The CPU sensor shows its state, naming the disk group that moved.');
$check(str_contains($block, '<span class="fcp-sensor-state" role="status">acpitz - temp1: Unchanged at 27 °C for 6 h while CPU varied 48–71 °C: may be a fixed placeholder.</span>'),
    'An aux sensor shows its state, named as the list names it.');
$check(substr_count($block, 'fcp-sensor-state') === 2, 'A sensor without a conclusion shows nothing.');
$check(!str_contains(render_fan_block($cfg, 0, [], [], [], $cpu, $aux, []), 'fcp-sensor-state'), 'No conclusions, no state.');
$check(!str_contains(render_fan_block(array_replace($cfg, ['aux_sensor' => 'custom:ambient']), 0, [], [], [], $cpu, $aux, $frozen), 'acpitz - temp1:'),
    'A sensor no longer selected for the fan shows no state.');

// The switch: one more column of the per-fan alert switches, on by default.
$page = file_get_contents("$sourceRoot/fanctrlplus2.page");
$logic = file_get_contents("$sourceRoot/include/FanctrlLogic.php");
$update = file_get_contents("$sourceRoot/include/update.fanctrlplus2.php");
$check(str_contains($page, '<th class="switch">Frozen Sensors</th>') && str_contains($page, "'frozen_notify' => 'frozen'") && str_contains($page, "hasClass('frozen-switch')"),
    'Syslog & Alerts must have a Frozen Sensors switch per fan.');
$check(str_contains($page, "'setfrozennotify'") && str_contains($logic, "'setfrozennotify' => 'frozen_notify'"),
    'The switch must be saved under its own key.');
$check(str_contains($update, "'frozen_notify' => (string)(\$previous['frozen_notify'] ?? '1')"),
    'Saving the fan settings must keep the switch, on when never set.');
$fan = "custom=\"Bay\"\nfrozen_notify=\"0\"\n";
try { $check(fcp_validate_fan_config($fan)['frozen_notify'] === '0', 'A backup must carry the switch.'); }
catch (InvalidArgumentException $error) { $check(false, 'A backup with the switch must restore: '.$error->getMessage()); }
$rejected = false;
try { fcp_validate_fan_config("custom=\"Bay\"\nfrozen_notify=\"2\"\n"); } catch (InvalidArgumentException $error) { $rejected = true; }
$check($rejected, 'The switch is on or off.');

exec('rm -rf '.escapeshellarg($root));

if ($failures) {
    fwrite(STDERR, implode("\n", $failures)."\n");
    exit(1);
}

echo "frozen sensor state tests passed\n";
