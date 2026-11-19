<?php
// A saved controller or sensor that is not present stays selected, marked
// missing, so saving the page neither drops it nor swaps in another one.
require_once __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2/include/FanBlockRender.php';

$failures = [];
$check = function (bool $ok, string $message) use (&$failures) { if (!$ok) $failures[] = $message; };
$root = sys_get_temp_dir() . '/fcp_missing_ui_' . getmypid();
@mkdir($root, 0777, true);
$present = "$root/temp2_input";
touch($present);

$pwms = [['chip' => 'nct6798', 'name' => 'pwm2', 'sensor' => '/sys/devices/platform/nct6775.656/hwmon/hwmon2/pwm2']];
$cpu = ['/sys/devices/platform/coretemp.0/hwmon/hwmon1/temp1_input' => 'coretemp - Package'];
$aux = ['hwmon' => [['path' => 'hwmon:present', 'label' => 'Present']]];
$render = fn(array $cfg) => render_fan_block($cfg + ['custom' => 'Array', 'file' => 'fanctrlplus2_Array.cfg'], 0, $pwms, [], [], $cpu, $aux);
// The option element carrying $value, or '' when there is none.
$option = function (string $html, string $value): string {
    return preg_match('#<option value="' . preg_quote(htmlspecialchars($value), '#') . '"[^>]*>[^<]*#', $html, $m) ? $m[0] : '';
};
$flagged = fn(string $opt) => strpos($opt, 'data-missing=') !== false && strpos($opt, ' selected') !== false;

$gone = '/sys/devices/platform/it87.2624/hwmon/hwmon2/pwm2';
$html = $render([
    'controller' => $gone,
    'cpu_enable' => '1', 'cpu_sensor' => '/sys/devices/platform/it87.2624/hwmon/hwmon2/temp1_input',
    'aux_enable' => '1', 'aux_sensor' => "hwmon:present,storcli:c0:roc,$present",
]);
$check($flagged($option($html, $gone)), 'A missing controller must stay selected and be marked missing.');
$check($flagged($option($html, '/sys/devices/platform/it87.2624/hwmon/hwmon2/temp1_input')), 'A missing CPU sensor must stay selected instead of the first one listed.');
$check($flagged($option($html, 'storcli:c0:roc')), 'A tool-backed aux sensor that is not listed must stay selected and be flagged.');
$opt = $option($html, $present);
$check($opt !== '' && !$flagged($opt) && strpos($opt, ' selected') !== false, 'A saved aux file that exists but is not listed must stay selected without a flag.');
$check(strpos($option($html, 'hwmon:present'), 'data-missing') === false, 'A listed aux sensor must not be marked missing.');

$class = '/sys/class/hwmon/hwmon2/pwm2';
$opt = $option($render(['controller' => $class]), $class);
$check($flagged($opt) && strpos($opt, 'Select again') !== false, 'A controller saved by hwmon number must be flagged for selecting again.');

// Blanked controllers (unresolved, or a conflict loser) show their identity
// only when that device is absent.
$check($flagged($option($render(['controller' => '', 'controller_identity' => $gone]), $gone)), 'A blanked controller must show its absent identity as missing.');
$usb = 'usb:' . bin2hex(json_encode(['3904', 'f001', 'ABC123', '/interface:1.0/hwmon/hwmon*/pwm1']));
$opt = $option($render(['controller' => '', 'controller_identity' => $usb]), $usb);
$check($flagged($opt) && strpos($opt, 'USB 3904:f001, serial ABC123') !== false, 'A blanked USB controller must show its identity, readably, as missing.');
$check(strpos($render(['controller' => '', 'controller_identity' => $present]), 'data-saved') === false, 'A blanked controller whose device exists lost a conflict and must not be shown.');

$html = $render(['controller' => $pwms[0]['sensor'], 'cpu_sensor' => array_key_first($cpu)]);
$check(strpos($html, 'data-missing') === false && strpos($html, 'data-saved') === false, 'Present selections must not be marked missing.');

exec('rm -rf ' . escapeshellarg($root));
if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "missing binding UI tests passed\n";
