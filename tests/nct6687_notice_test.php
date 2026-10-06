<?php

require_once __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2/include/Common.php';

$failures = [];
$root = sys_get_temp_dir() . '/fcp_nct6687_notice_' . getmypid();
$module = "$root/module";
$hwmon = "$root/devices/platform/nct6687.2592/hwmon/hwmon7";
$other = "$root/devices/platform/it87.552/hwmon/hwmon3";
$modprobe = "$root/modprobe.d";

@mkdir("$module/parameters", 0777, true);
@mkdir($hwmon, 0777, true);
@mkdir($other, 0777, true);
@mkdir($modprobe, 0777, true);
file_put_contents("$hwmon/name", "nct6687\n");
file_put_contents("$other/name", "it8728\n");
file_put_contents("$modprobe/nct6683.conf", "blacklist nct6683\ninstall nct6683 /bin/false\n");

$check = function (string $label, bool $expected, array $controllers) use (&$failures, $module, $modprobe): void {
    $actual = fcp_nct6687_brute_force_missing($controllers, $module, "$modprobe/*.conf");
    if ($actual !== $expected) {
        $failures[] = "$label: expected " . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};

$sys_fan = ["$other/pwm3", "$hwmon/pwm5"];

$check('driver not loaded', false, $sys_fan);

file_put_contents("$module/parameters/fan_config", "default\n");
$check('default register layout', false, $sys_fan);

file_put_contents("$module/parameters/fan_config", "msi_alt1\n");
$check('msi_alt1 without the option', true, $sys_fan);
$check('first system channel', true, ["$hwmon/pwm3"]);
$nct6686 = "$root/devices/platform/nct6687.2593/hwmon/hwmon8";
@mkdir($nct6686, 0777, true);
file_put_contents("$nct6686/name", "nct6686\n");
$check('nct6686 chip', true, ["$other/pwm3", "$nct6686/pwm3"]);
$check('only CPU and pump channels', false, ["$hwmon/pwm1", "$hwmon/pwm2", "$hwmon/pwm1_enable", "$hwmon/pwm3_enable"]);
$check('system channel on another chip', false, ["$other/pwm5"]);
$check('no fans configured', false, []);

foreach ([
    'options nct6687 msi_fan_brute_force=0' => true,
    'options nct6687 msi_fan_brute_force=n' => true,
    '# options nct6687 msi_fan_brute_force=1' => true,
    'options nct6683 msi_fan_brute_force=1' => true,
    'options nct6687x msi_fan_brute_force=1' => true,
    'options nct6687 not_msi_fan_brute_force=1' => true,
    'options nct6687 msi_fan_brute_force=1' => false,
    "# Set by hand\noptions nct6687 msi_fan_brute_force=1" => false,
    "options nct6687 fan_config=msi_alt1 msi-fan-brute-force=Y\n" => false,
    'options  nct6687 msi_fan_brute_force' => false,
    'options nct6687 msi_fan_brute_force=on' => false,
    'options nct6687 msi_fan_brute_force="true"' => false,
] as $line => $expected) {
    file_put_contents("$modprobe/nct6687.conf", "$line\n");
    $check("modprobe line '$line'", $expected, $sys_fan);
}
unlink("$modprobe/nct6687.conf");

touch("$hwmon/fan_control_watchdog");
$check('watchdog attribute present', false, $sys_fan);

exec('rm -rf ' . escapeshellarg($root));

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "nct6687 notice: OK\n";
