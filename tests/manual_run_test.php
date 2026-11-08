<?php
// Run Now: the helper behind the refresh_single endpoint runs the manual-run
// script to completion and answers with what it really did. A lock, setup or
// controller failure is a failure, never an OK after a background spawn.

$pluginRoot = __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2';
require_once "$pluginRoot/include/ManualRun.php";

$failures = [];
$check = function ($condition, string $message) use (&$failures) {
    if (!$condition) $failures[] = $message;
};

$tmp = sys_get_temp_dir() . '/fcp-manual-run-' . getmypid();
mkdir("$tmp/cfg", 0700, true);
$custom = 'php_manual_' . getmypid();
$runtime = '/var/tmp/fanctrlplus2';
register_shutdown_function(function () use ($tmp, $custom, $runtime) {
    exec('rm -rf ' . escapeshellarg($tmp));
    foreach (glob("$runtime/*_fanctrlplus2_$custom*") ?: [] as $file) @unlink($file);
    exec('rm -rf ' . escapeshellarg("$runtime/faults_$custom"));
});

// ===== The script's status is the answer =====
file_put_contents("$tmp/ok.sh", "echo fine\n");
file_put_contents("$tmp/refuse.sh", "echo 'controller refused' >&2\nexit 3\n");
file_put_contents("$tmp/args.sh", "printf '%s|' \"\$@\"\n");

$run = fcp_manual_run('Fan', "$tmp/ok.sh");
$check($run === ['ok' => true, 'message' => 'fine'], 'A script that exits 0 is a success.');

$run = fcp_manual_run('Fan', "$tmp/refuse.sh");
$check($run === ['ok' => false, 'message' => 'controller refused'], 'A script that exits non-zero is a failure that carries its stderr.');

// The fan name is one argument and never shell.
$injected = "$tmp/injected";
$run = fcp_manual_run("a'; touch $injected; echo '", "$tmp/args.sh");
$check(!file_exists($injected), 'A fan name must not reach a shell.');
$check($run['message'] === "a'; touch $injected; echo '|", 'The fan name reaches the script whole, as its first argument.');
$check(fcp_manual_run('', "$tmp/ok.sh")['ok'] === false, 'An empty fan name is refused without running anything.');
$check(fcp_manual_run("a\0b", "$tmp/ok.sh")['ok'] === false, 'A fan name with a NUL is refused without running anything.');

// ===== The real script against fixtures =====
// Its libraries load from the repository, and its sleep for the RPM settle is
// skipped; the rest (the lock, the curve state, the controller write) is real.
file_put_contents("$tmp/env.sh", <<<'SH'
source() {
  case "$1" in
    /usr/local/emhttp/plugins/fanctrlplus2/*) builtin source "$FCP_TEST_ROOT/${1#/usr/local/emhttp/plugins/fanctrlplus2/}" ;;
    *) builtin source "$1" ;;
  esac
}
logger() { :; }
sleep() { [[ "$1" == 4 ]] && return 0; command sleep "$@"; }
SH);
putenv('BASH_ENV=' . "$tmp/env.sh");
putenv('FCP_TEST_ROOT=' . realpath($pluginRoot));
putenv('FCP_CONFIG_DIR=' . "$tmp/cfg");

$run = fcp_manual_run($custom . '_missing');
$check($run['ok'] === false && str_contains($run['message'], 'No configuration found'), 'A fan with no configuration is a failure that says so.');

file_put_contents("$tmp/cfg/fanctrlplus2_$custom.cfg", <<<CFG
custom="$custom"
controller="$tmp/pwm1"
pwm="100"
max="255"
cpu_enable="1"
cpu_sensor="$tmp/temp1_input"
cpu_min_temp="30"
cpu_max_temp="70"
aux_enable="0"
CFG);
file_put_contents("$tmp/temp1_input", "40000\n");
file_put_contents("$tmp/pwm1", "0\n");

// 40 °C on the legacy ramp 30..70 from 100 to 255 is 138.
$run = fcp_manual_run($custom);
$check($run['ok'] === true, 'A run that sets the controller is a success. ' . $run['message']);
$check(trim((string)file_get_contents("$tmp/pwm1")) === '138', 'A successful run leaves the controller at the curve value.');

// A controller that refuses writes (a directory) fails the run.
unlink("$tmp/pwm1");
mkdir("$tmp/pwm1");
$run = fcp_manual_run($custom);
$check($run['ok'] === false && str_contains($run['message'], 'Could not write PWM=138'), 'A refused controller write is a failure that names the write.');

if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "manual run tests passed\n";
