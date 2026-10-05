<?php
$module = __DIR__.'/../src/usr/local/emhttp/plugins/fanctrlplus2/include/ConfigBackup.php';
if (!is_file($module)) { fwrite(STDERR, "Configuration backup implementation is missing.\n"); exit(1); }
require_once $module;
$root = sys_get_temp_dir().'/fcp_backup_'.getmypid();
mkdir($root, 0700, true);
$failures = [];
$check = function ($condition, string $message) use (&$failures) { if (!$condition) $failures[] = $message; };
$fan = <<<'CFG'
custom="JBOD"
label="JBOD"
service="0"
controller="/sys/devices/platform/nct6775.656/hwmon/hwmon5/pwm1"
pwm="102"
max="255"
idle="0"
low="40"
high="60"
interval_sec="120"
interval="2"
disk_group_count="1"
disk_group_0_name="Top \` \" \$ label"
disk_group_0_disks="ata_TEST_DISK"
disk_group_0_low="40"
disk_group_0_high="60"
syslog="1"
cpu_enable="0"
cpu_sensor=""
cpu_min_temp=""
cpu_max_temp=""
aux_enable="0"
aux_sensor=""
aux_min_temp=""
aux_max_temp=""
CFG;
$fan .= "\n";
$identity = 'usb:'.bin2hex(json_encode(['3904','f001','2080376E4548','/interface:1.0/0003:3904:F001.*/hwmon/hwmon*/pwm10']));
$labels = "$identity=JBOD\n__FCP_HISTORY__=1\n";
$files = ['fanctrlplus2_JBOD.cfg'=>$fan, 'pwm_labels.cfg'=>$labels, 'order.cfg'=>"order0=\"fanctrlplus2_JBOD.cfg\"\n"];
foreach ($files as $name=>$text) file_put_contents("$root/$name", $text);
mkdir("$root/sensors.d");
file_put_contents("$root/sensors.d/ambient", "#!/bin/sh\necho 37\n");
file_put_contents("$root/notes.txt", 'keep');
file_put_contents("$root/fanctrlplus2_temp_0.cfg", 'unsaved');
$backup = fcp_export_config($root);
$doc = json_decode($backup, true);
$check($doc['format'] === 'fanctrlplus2-config' && $doc['version'] === 1, 'Snapshot must identify its format/version.');
$exported = $doc['files']; ksort($exported); $expected=$files; ksort($expected);
$check($exported === $expected, 'Export must preserve saved labels, groups and ordering, excluding scripts and unsaved data.');
file_put_contents("$root/fanctrlplus2_JBOD.cfg", str_replace('pwm="102"', 'pwm="120"', $fan));
file_put_contents("$root/fanctrlplus2_extra.cfg", str_replace('JBOD','extra',$fan));
fcp_restore_config($root, $backup);
foreach ($files as $name=>$text) $check(file_get_contents("$root/$name") === $text, "Restore must preserve $name exactly, including escaping and serial identity.");
$check(!file_exists("$root/fanctrlplus2_extra.cfg"), 'Snapshot replacement must remove fan configurations absent from the backup.');
$check(!file_exists("$root/fanctrlplus2_temp_0.cfg"), 'Restore must discard unsaved temporary fan blocks.');
$check(file_get_contents("$root/notes.txt") === 'keep' && file_get_contents("$root/sensors.d/ambient") === "#!/bin/sh\necho 37\n", 'Restore must leave unrelated files and sensor code untouched.');

$invalid = [];
$encode = function ($map, $version=1) { return json_encode(['format'=>'fanctrlplus2-config','version'=>$version,'files'=>(object)$map]); };
$invalid[] = $encode($files, 2);
$invalid[] = '{broken';
$invalid[] = $encode(['../../escape.cfg'=>$fan]);
$invalid[] = $encode(['sensors.d/evil'=>$fan]);
$invalid[] = $encode(['fanctrlplus2_JBOD.cfg'=>$fan."PATH=\"/tmp\"\n"]);
$invalid[] = $encode(['fanctrlplus2_JBOD.cfg'=>str_replace('pwm="102"', 'pwm="array[\$(touch /tmp/fcp_backup_pwn)]"', $fan)]);
$invalid[] = $encode(['fanctrlplus2_JBOD.cfg'=>str_replace('/sys/devices/platform/nct6775.656/hwmon/hwmon5/pwm1','/tmp/pwm1',$fan)]);
$invalid[] = $encode(['fanctrlplus2_JBOD.cfg'=>str_replace('/sys/devices/platform/','/sys/devices/../platform/',$fan)]);
$invalid[] = $encode(['order.cfg'=>"order0=\"../../escape.cfg\"\n"]);
$invalid[] = $encode(['pwm_labels.cfg'=>"/tmp/pwm1=bad\n"]);
$invalid[] = $encode(['fanctrlplus2_JBOD.cfg'=>$fan, 'fanctrlplus2_jbod.cfg'=>str_replace('JBOD','jbod',$fan)]);
$invalid[] = $encode(['fanctrlplus2_one.cfg'=>$fan, 'fanctrlplus2_two.cfg'=>$fan]);
$invalid[] = $encode(['fanctrlplus2_JBOD.cfg'=>$fan."pwm=\"120\"\n"]);
$invalid[] = $encode(['fanctrlplus2_JBOD.cfg'=>str_replace('pwm="102"', 'pwm="09"', $fan)]);
$invalid[] = $encode(['fanctrlplus2_JBOD.cfg'=>str_replace('label="JBOD"', 'label="$(touch /tmp/fcp_backup_pwn)"', $fan)]);
$invalid[] = $encode(['fanctrlplus2_JBOD.cfg'=>str_replace('label="JBOD"', 'label="`touch /tmp/fcp_backup_pwn`"', $fan)]);
$invalid[] = json_encode(['format'=>'fanctrlplus2-config','version'=>1,'files'=>(object)[],'padding'=>str_repeat('x',FCP_BACKUP_MAX_BYTES)]);
foreach ($invalid as $payload) {
    $before=fcp_export_config($root);
    try { fcp_restore_config($root, $payload); $check(false, 'An unsafe/unsupported snapshot was accepted.'); }
    catch (InvalidArgumentException $e) { }
    $check(fcp_export_config($root) === $before, 'Rejected imports must not change any configuration.');
}

// Bash does not treat control-byte-prefixed '#' as a comment. Exercise the
// consumer as well as validation: even a regressed restore must not execute it.
foreach (["\r", "\x0b"] as $control) {
    foreach (['fanctrlplus2_JBOD.cfg', 'order.cfg'] as $name) {
        $marker="$root/command-ran";
        $injected=$files;
        $injected[$name] .= $control.'# ; touch '.escapeshellarg($marker)."\n";
        $rejected=false;
        try { fcp_restore_config($root,$encode($injected)); }
        catch (InvalidArgumentException $e) { $rejected=true; }
        exec('bash -c '.escapeshellarg('source '.escapeshellarg("$root/$name")).' 2>/dev/null');
        $check($rejected && !file_exists($marker), 'Control-prefixed comments must be rejected without executing shell code.');
        if (file_exists($marker)) unlink($marker);
        fcp_restore_config($root,$backup);
    }
}

// Safe absent order references are normal after deleting or adding a block.
$oldOrder=$files['order.cfg'];
file_put_contents("$root/order.cfg",$oldOrder."order1=\"fanctrlplus2_temp_0.cfg\"\norder2=\"fanctrlplus2_gone.cfg\"\n");
$withMissing=fcp_export_config($root);
try { fcp_restore_config($root,$withMissing); }
catch (InvalidArgumentException $e) { $check(false,'An exported order with stale/temporary references must restore.'); }
fcp_restore_config($root,$backup);
$legacy=$files;
$legacy['fanctrlplus2_JBOD.cfg'] .= "idle_percent=\"10\"\nfan=\"/sys/devices/platform/nct6775.656/hwmon/hwmon5/fan1_input\"\n";
$legacy['fanctrlplus2_JBOD.cfg'] = str_replace(['ata_TEST_DISK','Top '],['ata_TEST#=@DISK',"Top\t"],$legacy['fanctrlplus2_JBOD.cfg']);
fcp_restore_config($root,$encode($legacy));
$check(fcp_validate_config_backup(fcp_export_config($root))['fanctrlplus2_JBOD.cfg'] === $legacy['fanctrlplus2_JBOD.cfg'], 'Legacy idle settings, tabs in group names and valid udev identifiers must round-trip.');
fcp_restore_config($root,$backup);

// A restart failure after replacement must roll back the real files, not
// discard the sole copy of the previous working settings.
$modified=$files; $modified['fanctrlplus2_JBOD.cfg']=str_replace('pwm="102"','pwm="130"',$fan);
$before=fcp_export_config($root);
try { fcp_restore_config($root, $encode($modified), null, function () { throw new RuntimeException('restart failed'); }); $check(false, 'A failed restart must be reported.'); }
catch (RuntimeException $e) { }
$check(fcp_export_config($root) === $before, 'A post-replacement failure must restore the old configuration files.');
$check((glob("$root/.restore-*") ?: []) === [], 'Successful rollback must not leave staged copies behind.');

// A pre-existing symlink is never followed or replaced by an import.
$outside="$root-outside"; file_put_contents($outside,'untouched');
unlink("$root/pwm_labels.cfg"); symlink($outside,"$root/pwm_labels.cfg");
try { fcp_restore_config($root,$backup); $check(false,'Import must refuse existing managed symlinks.'); }
catch (RuntimeException $e) { }
$check(file_get_contents($outside)==='untouched' && is_link("$root/pwm_labels.cfg"), 'Rejected symlink targets must remain untouched.');
unlink("$root/pwm_labels.cfg"); unlink($outside);
fcp_restore_config($root,$encode([]));
$check((glob("$root/fanctrlplus2_*.cfg") ?: []) === [], 'An explicitly empty snapshot must restore an empty managed configuration.');
$check(file_get_contents("$root/notes.txt")==='keep', 'Empty restoration must retain unrelated files.');

// Long-lived service children must not retain their parent's configuration
// lock. Synchronize after exec rather than relying on a sleep or timing.
$lock = fcp_config_lock($root);
$child = proc_open(['/bin/sh','-c','printf "ready\\n"; read reply'], [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']], $pipes);
if (!is_resource($child)) throw new RuntimeException('Could not start lock regression child.');
fgets($pipes[1]);
$contender = fopen("$root/.config.lock", 'c');
$entered = flock($contender, LOCK_EX | LOCK_NB);
$check(!$entered, 'A concurrent configuration writer must not bypass the held lock.');
if ($entered) flock($contender, LOCK_UN);
fclose($contender);
fclose($lock);
$probe = fopen("$root/.config.lock", 'c');
$acquired = flock($probe, LOCK_EX | LOCK_NB);
$check($acquired, 'An execed service child must not keep the configuration locked after the request ends.');
if ($acquired) flock($probe, LOCK_UN);
fclose($probe);
fwrite($pipes[0], "\n"); fclose($pipes[0]); fclose($pipes[1]); proc_close($child);
exec('rm -rf '.escapeshellarg($root));
if ($failures) { fwrite(STDERR,implode("\n",$failures)."\n"); exit(1); }
echo "configuration backup tests passed\n";
