<?php
// A saved controller or sensor that is not present stays selected, marked
// missing, so saving the page neither drops it nor swaps in another one.
require_once __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2/include/FanBlockRender.php';

$failures = [];
$pwms = [['chip' => 'nct6798', 'name' => 'pwm2', 'sensor' => '/sys/devices/platform/nct6775.656/hwmon/hwmon2/pwm2']];
$cpu = ['/sys/class/hwmon/hwmon1/temp1_input' => 'coretemp - Package'];
$aux = ['hwmon' => [['path' => 'hwmon:present', 'label' => 'Present']]];
$render = fn(array $cfg) => render_fan_block($cfg + ['custom' => 'Array', 'file' => 'fanctrlplus2_Array.cfg'], 0, $pwms, [], [], $cpu, $aux);

$gone = '/sys/devices/platform/it87.2624/hwmon/hwmon2/pwm2';
$html = $render([
    'controller' => $gone,
    'cpu_enable' => '1', 'cpu_sensor' => '/sys/class/hwmon/hwmon9/temp1_input',
    'aux_enable' => '1', 'aux_sensor' => 'hwmon:present,storcli:c0:roc',
]);
foreach ([
    "value=\"$gone\" data-missing=\"PWM controller\" selected" => 'A missing controller must stay selected and be marked missing.',
    'value="/sys/class/hwmon/hwmon9/temp1_input" data-missing="CPU sensor" selected' => 'A missing CPU sensor must stay selected instead of the first one listed.',
    'value="storcli:c0:roc" data-missing="auxiliary sensor" selected' => 'A missing aux sensor must stay selected instead of being dropped.',
] as $needle => $message) {
    if (strpos($html, $needle) === false) $failures[] = $message;
}
if (strpos($html, 'value="hwmon:present" data-missing') !== false) {
    $failures[] = 'A present aux sensor must not be marked missing.';
}

$html = $render(['controller' => $pwms[0]['sensor'], 'cpu_sensor' => array_key_first($cpu)]);
if (strpos($html, 'data-missing') !== false) {
    $failures[] = 'Present selections must not be marked missing.';
}

if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "missing binding UI tests passed\n";
