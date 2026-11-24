<?php
// A Monitor only fan's speed and PWM are read straight from its controller.
require __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2/include/Common.php';

$failures = [];
$check = function (bool $ok, string $message) use (&$failures) { if (!$ok) $failures[] = $message; };

$dir = sys_get_temp_dir() . '/fcp_monitor_' . getmypid();
mkdir("$dir/devices/hwmon/hwmon4", 0777, true);
mkdir("$dir/class/hwmon/hwmon4", 0777, true);
$chip = "$dir/devices/hwmon/hwmon4";
file_put_contents("$chip/pwm2", "153\n");
file_put_contents("$chip/fan2_input", "1459\n");
file_put_contents("$dir/class/hwmon/hwmon4/pwm2", "153\n");
file_put_contents("$dir/class/hwmon/hwmon4/fan2_input", "1459\n");

$check(fcp_monitor_reading("$chip/pwm2") === ['rpm' => '1459', 'pwm' => '153'], 'The speed and PWM must be read from the controller and its fan input.');
file_put_contents("$chip/fan2_input", "");
$check(fcp_monitor_reading("$chip/pwm2") === ['rpm' => '-', 'pwm' => '153'], 'A fan input without a reading must show no speed.');
$check(fcp_monitor_reading("$chip/pwm3") === ['rpm' => '-', 'pwm' => '-'], 'A missing controller must show no reading.');
$check(fcp_monitor_reading("$dir/class/hwmon/hwmon4/pwm2") === ['rpm' => '-', 'pwm' => '-'], 'A controller saved by its hwmon number must not be read (#41).');
$check(fcp_monitor_reading('') === ['rpm' => '-', 'pwm' => '-'], 'A fan without a controller must show no reading.');

exec('rm -rf ' . escapeshellarg($dir));
if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "monitor reading tests passed\n";
