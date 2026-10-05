<?php
// User-supplied sensor scripts: any readable file dropped in the sensors.d
// directory that prints a temperature in Celsius becomes an aux sensor. The
// directory lives on flash storage that cannot carry an execute bit, so the
// files are plain 0600 and are run from a private runtime copy.

$sourceRoot = getenv('FCP_SOURCE_ROOT') ?: __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2';
require_once $sourceRoot . '/include/Common.php';

$failures = [];

function expect_equal($expected, $actual, string $message): void {
  global $failures;
  if ($expected === $actual) return;
  $failures[] = sprintf("%s\nExpected: %s\nActual: %s", $message, var_export($expected, true), var_export($actual, true));
}

// ===== parse_custom_sensor_output =====
expect_equal(24, parse_custom_sensor_output("24\n"),
  'A bare temperature must parse.');
expect_equal(44, parse_custom_sensor_output("44.7\n"),
  'A fractional reading must be reported in whole degrees.');
expect_equal(null, parse_custom_sensor_output("Temperature is 55 degrees\n"),
  'The contract is the temperature and nothing else.');
expect_equal(null, parse_custom_sensor_output(''),
  'No output means nothing to report.');
expect_equal(250, parse_custom_sensor_output("250\n"),
  'A hot reading is reported as measured, not trimmed.');
expect_equal(300, parse_custom_sensor_output("300\n"),
  'The top of the plausible range is still a reading.');
expect_equal(null, parse_custom_sensor_output("301\n"),
  'Just past it is not.');
expect_equal(null, parse_custom_sensor_output("10000\n"),
  'A value no sensor could produce is not a reading at all.');
expect_equal(0, parse_custom_sensor_output("-5\n"),
  'A below-zero reading is clamped; errors are reported by the exit status.');

// ===== detect_custom_temps =====
$dir = sys_get_temp_dir() . '/fcp_sensors_' . getmypid();
$runDir = "$dir-run";
mkdir($dir, 0777, true);
putenv("FCP_CUSTOM_SENSOR_RUNTIME_DIR=$runDir");

$write = function (string $name, string $body, int $mode = 0600) use ($dir): void {
  file_put_contents("$dir/$name", "#!/bin/sh\n$body\n");
  chmod("$dir/$name", $mode);
};

$write('ambient', 'echo 24');
$write('nic', 'echo 71');
// A script that reports an error is not offered as a sensor.
$write('broken', 'exit 1');
// Neither is one that ignores the contract.
$write('chatty', 'echo "about 40 C"');
// Flash cannot hold an execute bit, so a script that was never executable is
// exactly what a sensor looks like; its interpreter line still decides how it runs.
$write('plain', 'echo 50', 0644);
$interp = "$dir-interp";
file_put_contents($interp, "#!/bin/sh\nsed -n 2p \"\$1\"\n");
chmod($interp, 0755);
file_put_contents("$dir/interpreted", "#!$interp\n58\n");
chmod("$dir/interpreted", 0600);
// Unusable entries: hidden, unaddressable by the Bash reader, or not a file.
$write('.hidden', 'echo 60');
$write('with space', 'echo 61');
mkdir("$dir/adir");
// A sensor sitting at zero is a dead sensor, and is not worth offering.
$write('unpopulated', 'echo 0');

$found = detect_custom_temps($dir);

expect_equal(
  [
    ['path' => 'custom:ambient', 'label' => 'ambient (24°C)', 'chip' => 'Custom Sensors', 'idx' => 0],
    ['path' => 'custom:interpreted', 'label' => 'interpreted (58°C)', 'chip' => 'Custom Sensors', 'idx' => 1],
    ['path' => 'custom:nic',     'label' => 'nic (71°C)',     'chip' => 'Custom Sensors', 'idx' => 2],
    ['path' => 'custom:plain',   'label' => 'plain (50°C)',   'chip' => 'Custom Sensors', 'idx' => 3],
  ],
  $found,
  'Every readable script honouring the contract is offered, executable or not, in name order.'
);
expect_equal([], glob("$runDir/*") ?: [],
  'Discovery must not leave runtime copies of the scripts behind.');

file_put_contents("$dir/ambient", "#!/bin/sh\necho 31\n");
touch("$dir/ambient", 1577836800);
$edited = array_column(detect_custom_temps($dir), 'label', 'path');
expect_equal('ambient (31°C)', $edited['custom:ambient'] ?? null,
  'An edited script must be read afresh.');

expect_equal([], detect_custom_temps("$dir/nowhere"),
  'A missing drop-in directory must simply mean no custom sensors.');

foreach (array_merge(glob("$dir/*") ?: [], glob("$dir/.hidden") ?: []) as $f) is_dir($f) ? rmdir($f) : unlink($f);
rmdir($dir);
@rmdir($runDir);
@unlink("$dir-interp");

if ($failures) {
  fwrite(STDERR, implode("\n\n", $failures) . "\n");
  fwrite(STDERR, count($failures) . " custom sensor assertion(s) failed.\n");
  exit(1);
}
echo "custom sensor tests passed.\n";
