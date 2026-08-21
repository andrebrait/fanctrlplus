<?php
// User-supplied sensor scripts: any executable dropped in the sensors.d
// directory that prints a temperature in Celsius becomes an aux sensor.

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
expect_equal(200, parse_custom_sensor_output("250\n"),
  'A reading just above the ceiling is clamped, not discarded.');
expect_equal(null, parse_custom_sensor_output("10000\n"),
  'A value no sensor could produce is not a reading at all.');
expect_equal(0, parse_custom_sensor_output("-5\n"),
  'A below-zero reading is clamped; errors are reported by the exit status.');

// ===== detect_custom_temps =====
$dir = sys_get_temp_dir() . '/fcp_sensors_' . getmypid();
mkdir($dir, 0777, true);

$write = function (string $name, string $body, int $mode = 0755) use ($dir): void {
  file_put_contents("$dir/$name", "#!/bin/sh\n$body\n");
  chmod("$dir/$name", $mode);
};

$write('ambient', 'echo 24');
$write('nic', 'echo 71');
// A script that reports an error is not offered as a sensor.
$write('broken', 'exit 1');
// Neither is one that ignores the contract.
$write('chatty', 'echo "about 40 C"');
// A file that was never made executable is not a sensor at all.
$write('readme', 'echo 50', 0644);
// A sensor sitting at zero is a dead sensor, and is not worth offering.
$write('unpopulated', 'echo 0');

$found = detect_custom_temps($dir);

expect_equal(
  [
    ['path' => 'custom:ambient', 'label' => 'ambient (24°C)', 'chip' => 'Custom Sensors', 'idx' => 0],
    ['path' => 'custom:nic',     'label' => 'nic (71°C)',     'chip' => 'Custom Sensors', 'idx' => 1],
  ],
  $found,
  'Only the executables honouring the contract are offered, in name order.'
);

expect_equal([], detect_custom_temps("$dir/nowhere"),
  'A missing drop-in directory must simply mean no custom sensors.');

array_map('unlink', glob("$dir/*"));
rmdir($dir);

if ($failures) {
  fwrite(STDERR, implode("\n\n", $failures) . "\n");
  fwrite(STDERR, count($failures) . " custom sensor assertion(s) failed.\n");
  exit(1);
}
echo "custom sensor tests passed.\n";
