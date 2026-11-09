<?php
// The settings page hint for a machine where no PWM controller is found.
$sourceRoot = __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2';
require_once "$sourceRoot/include/Common.php";

$failures = [];
$check = function ($condition, string $message) use (&$failures) {
    if (!$condition) $failures[] = $message;
};
$root = sys_get_temp_dir() . '/fcp_no_pwm_notice_' . getmypid();
$installed = "$root/dynamix.system.temp";
mkdir($installed, 0777, true);

$pwm = [['chip' => 'nct6798', 'name' => 'pwm1', 'sensor' => '/sys/devices/platform/nct6775.656/hwmon/hwmon3/pwm1']];
$check(fcp_no_pwm_notice($pwm, $installed) === '', 'With a controller found there is nothing to explain.');

$hint = fcp_no_pwm_notice([], $installed);
$check(str_contains($hint, 'No PWM controllers found.'), 'The hint must say what is wrong.');
$check(str_contains($hint, 'driver for the mainboard\'s fan and sensor chip is probably not loaded'), 'The hint must name the likely cause.');
$check(str_contains($hint, '<a href="/Settings/TempSettings">Settings → System Temp</a>'), 'With System Temp installed, the hint must link to its page.');
$check(preg_match('#Detect</strong>, then <strong>Save</strong> and <strong>Load drivers#', $hint) === 1, 'The hint must give the steps on that page, in order.');
$check(!str_contains($hint, 'install <strong>Dynamix System Temperature</strong>'), 'An installed plugin must not be offered for installation.');

$check(str_contains($hint, 'If Detect finds no fan chip'), 'The hint must say what to do when the chip has no built-in driver, with an example.');

$missing = fcp_no_pwm_notice([], "$root/absent");
$check(str_contains($missing, 'install <strong>Dynamix System Temperature</strong> from Apps first'), 'Without System Temp, the hint must say to install it first.');
$check(!str_contains($missing, '<a href='), 'A link to a page that does not exist must not be offered.');

$page = file_get_contents("$sourceRoot/fanctrlplus2.page");
$check(str_contains($page, '<?= fcp_no_pwm_notice($pwms) ?>'), 'The settings page must show the hint for the controllers it lists.');

exec('rm -rf ' . escapeshellarg($root));

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "no PWM notice tests passed\n";
