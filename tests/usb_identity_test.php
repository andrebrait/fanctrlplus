<?php
require_once __DIR__.'/../src/usr/local/emhttp/plugins/fanctrlplus2/include/Common.php';

$root = realpath(sys_get_temp_dir()).'/fcp_usb_identity_'.getmypid();
$cfg = "$root/cfg";
$hwmon = "$root/class/hwmon";
mkdir($cfg, 0777, true);
mkdir($hwmon, 0777, true);
$failures = [];
$check = function ($condition, string $message) use (&$failures) {
    if (!$condition) $failures[] = $message;
};
$device = function (string $port, string $index, string $serial, string $instance = '0008') use ($root, $hwmon): string {
    $usb = "$root/devices/$port";
    $dir = "$usb/$port:1.0/0003:3904:F001.$instance/hwmon/$index";
    mkdir($dir, 0777, true);
    file_put_contents("$usb/idVendor", "3904\n");
    file_put_contents("$usb/idProduct", "f001\n");
    file_put_contents("$usb/serial", "$serial\n");
    file_put_contents("$dir/name", "arctic_fan\n");
    touch("$dir/pwm10");
    symlink($dir, "$hwmon/$index");
    return "$dir/pwm10";
};
$path = $device('1-14', 'hwmon5', '2080376E4548');
$file = "$cfg/fanctrlplus2_jbod.cfg";
$metadata = 'disk_group_0_name="Top \` (group)"'."\n";
file_put_contents($file, "custom=\"jbod\"\nservice=\"1\"\ncontroller=\"$path\"\n".$metadata);
file_put_contents("$cfg/pwm_labels.cfg", "$path=JBOD\n__FCP_HISTORY__=1\n");
$migrate = function () use ($cfg, $hwmon) { migrate_cfg_and_labels('fanctrlplus2', $cfg, "$hwmon/hwmon*"); };
$migrate();
$identity = parse_ini_file($file)['controller_identity'] ?? '';
$check($identity !== '', 'Startup must persist USB serial identity before a port move.');
$check(strpos(file_get_contents($file), $metadata) !== false, 'Identity capture must not change unrelated escaped group labels.');

if ($identity !== '') {
    // Move the controller, then install an identical model at its old port.
    rename("$root/devices/1-14", "$root/removed");
    unlink("$hwmon/hwmon5");
    $moved = $device('2-3.4.5', 'hwmon9', '2080376E4548', '0011');
    $replacement = $device('1-14', 'hwmon5', 'DIFFERENT');
    $migrate();
    $check(parse_ini_file($file)['controller'] === $moved, 'Serial must follow a move across buses/hubs instead of the replacement at the old port.');
    $check(fcp_load_pwm_labels("$cfg/pwm_labels.cfg", "$hwmon/hwmon*") === [$moved => 'JBOD'], 'Labels must follow the serial and keep channel 10.');
    $check(parse_ini_file($file)['controller_identity'] === $identity, 'Re-enumeration must not change the saved serial/channel identity.');
    $check(strpos(file_get_contents($file), $metadata) !== false, 'A port move must preserve unrelated configuration bytes.');
    $check(strpos(file_get_contents("$cfg/pwm_labels.cfg"), '__FCP_HISTORY__=1') !== false, 'Serial migration must preserve dashboard flags.');

    // Duplicate serial numbers cannot safely identify either controller.
    $duplicate = $device('3-2', 'hwmon12', '2080376E4548');
    $migrate();
    $check(parse_ini_file($file)['controller'] === '', 'Duplicate serials must disable the runtime controller, not guess.');
    $check(parse_ini_file($file)['controller_identity'] === $identity, 'Unresolved identity must remain saved for recovery.');
    $check(fcp_load_pwm_labels("$cfg/pwm_labels.cfg", "$hwmon/hwmon*") === [], 'Ambiguous serial labels must not attach to either device.');

    unlink("$hwmon/hwmon12");
    $migrate();
    $check(parse_ini_file($file)['controller'] === $moved, 'A resolved serial must restore its runtime path after ambiguity clears.');
    $check(fcp_load_pwm_labels("$cfg/pwm_labels.cfg", "$hwmon/hwmon*") === [$moved => 'JBOD'], 'Labels must survive ambiguity and reappear on recovery.');
    unlink("$hwmon/hwmon9");
    $migrate();
    $check(parse_ini_file($file)['controller'] === '', 'An absent serial must not fall back to an identical replacement.');

    // No serial: keep the legacy same-port behavior, not a VID:PID guess.
    $noSerial = $device('4-1', 'hwmon15', '');
    $check(fcp_pwm_identity($noSerial) === null, 'A device without a serial must not get a serial identity.');
    $legacyFile = "$cfg/fanctrlplus2_legacy.cfg";
    file_put_contents($legacyFile, "custom=\"legacy\"\ncontroller=\"$noSerial\"\n");
    $migrate();
    $check(strpos((string)(parse_ini_file($legacyFile)['controller_identity'] ?? ''), 'usb:') !== 0, 'A serial-less configuration must retain its path identity.');
    rename("$root/devices/4-1", "$root/removed_no_serial");
    unlink("$hwmon/hwmon15");
    $noSerialMoved = $device('4-2', 'hwmon16', '');
    $migrate();
    $check(parse_ini_file($legacyFile)['controller'] !== $noSerialMoved, 'A serial-less port move must not be guessed by VID:PID.');

    // Duplicate serials present at upgrade must not collapse working port
    // assignments into an ambiguous key.
    $a = $device('5-1', 'hwmon20', 'FAKE');
    $b = $device('5-2', 'hwmon21', 'FAKE');
    $dupCfg = "$root/duplicates";
    mkdir($dupCfg);
    file_put_contents("$dupCfg/pwm_labels.cfg", "$a=Front\n$b=Rear\n");
    file_put_contents("$dupCfg/fanctrlplus2_a.cfg", "controller=\"$a\"\n");
    file_put_contents("$dupCfg/fanctrlplus2_b.cfg", "controller=\"$b\"\n");
    for ($i = 0; $i < 2; $i++) migrate_cfg_and_labels('fanctrlplus2', $dupCfg, "$hwmon/hwmon*");
    $check(fcp_load_pwm_labels("$dupCfg/pwm_labels.cfg", "$hwmon/hwmon*") === [$a=>'Front', $b=>'Rear'], 'Ambiguous serials must leave both existing path labels intact.');
    $check(parse_ini_file("$dupCfg/fanctrlplus2_a.cfg")['controller'] === $a && parse_ini_file("$dupCfg/fanctrlplus2_b.cfg")['controller'] === $b, 'Ambiguous serials at capture must not disable existing port assignments.');

    // A manually reassigned current path wins over a moved identity,
    // independent of the two filenames.
    foreach ([['aa', 'zz'], ['zz', 'aa']] as [$serialName, $liveName]) {
        $collision = "$root/collision_$serialName";
        mkdir($collision);
        $serialFile = "$collision/fanctrlplus2_$serialName.cfg";
        $liveFile = "$collision/fanctrlplus2_$liveName.cfg";
        $known = fcp_pwm_identity($replacement);
        file_put_contents($serialFile, "controller=\"$root/gone/hwmon1/pwm10\"\ncontroller_identity=\"$known\"\n");
        file_put_contents($liveFile, "controller=\"$replacement\"\n");
        migrate_cfg_and_labels('fanctrlplus2', $collision, "$hwmon/hwmon*");
        $check(parse_ini_file($liveFile)['controller'] === $replacement && parse_ini_file($serialFile)['controller'] === '', 'A current manual assignment must win without two loops, in either filename order.');
        unlink($liveFile);
        migrate_cfg_and_labels('fanctrlplus2', $collision, "$hwmon/hwmon*");
        $check(parse_ini_file($serialFile)['controller'] === $replacement, 'A blocked identity must recover when the other assignment is removed.');
    }

    // Two path-only configs on one live channel also cannot launch two
    // loops. The losing path binding remains available for later recovery.
    $same = "$root/same_channel";
    mkdir($same);
    $first = "$same/fanctrlplus2_a.cfg";
    $second = "$same/fanctrlplus2_b.cfg";
    file_put_contents($first, "controller=\"$noSerialMoved\"\n");
    file_put_contents($second, "controller=\"$noSerialMoved\"\n");
    migrate_cfg_and_labels('fanctrlplus2', $same, "$hwmon/hwmon*");
    $check(parse_ini_file($first)['controller'] === $noSerialMoved && parse_ini_file($second)['controller'] === '', 'Two live path-only assignments must leave exactly one runtime controller.');
    unlink($first);
    migrate_cfg_and_labels('fanctrlplus2', $same, "$hwmon/hwmon*");
    $check(parse_ini_file($second)['controller'] === $noSerialMoved, 'A blocked path binding must recover when its owner is removed.');

    // USB drivers need not be HID drivers: the relative path does not
    // itself carry VID:PID, so the identity must include both explicitly.
    $nonHid = [];
    foreach ([['6-1', 'hwmon30', '1111', '2222'], ['6-2', 'hwmon31', '3333', '4444']] as [$port, $index, $vid, $pid]) {
        $usb = "$root/devices/$port";
        $dir = "$usb/$port:1.0/hwmon/$index";
        mkdir($dir, 0777, true);
        foreach (['idVendor'=>$vid, 'idProduct'=>$pid, 'serial'=>'SHARED'] as $key=>$value) file_put_contents("$usb/$key", "$value\n");
        file_put_contents("$dir/name", "usb_fan\n");
        touch("$dir/pwm10");
        symlink($dir, "$hwmon/$index");
        $nonHid[] = "$dir/pwm10";
    }
    $keyA = fcp_pwm_identity($nonHid[0]);
    $keyB = fcp_pwm_identity($nonHid[1]);
    $check($keyA !== null && $keyB !== null && $keyA !== $keyB, 'VID:PID must distinguish devices reporting the same serial.');
    $map = fcp_pwm_identity_map(list_pwm("$hwmon/hwmon*"));
    $check(($map[$keyA] ?? null) === $nonHid[0] && ($map[$keyB] ?? null) === $nonHid[1], 'Different VID:PID pairs must not become an ambiguous serial.');
}

exec('rm -rf '.escapeshellarg($root));
if ($failures) { fwrite(STDERR, implode("\n", $failures)."\n"); exit(1); }
echo "USB serial identity tests passed\n";
