<?php
// cfg_interval_seconds(): the PHP side of the seconds interval, including the
// grandfathering of configs written when "interval" still meant minutes.

$sourceRoot = getenv('FCP_SOURCE_ROOT') ?: __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2';
require_once $sourceRoot . '/include/Common.php';

$failures = [];

function expect_equal($expected, $actual, string $message): void {
  global $failures;
  if ($expected === $actual) return;
  $failures[] = sprintf("%s\nExpected: %s\nActual: %s", $message, var_export($expected, true), var_export($actual, true));
}

expect_equal(30, cfg_interval_seconds(['interval_sec' => '30']),
  'A seconds config must be used as-is.');

expect_equal(300, cfg_interval_seconds(['interval' => '5']),
  'A legacy minute config must render as the same cadence in seconds.');

expect_equal(45, cfg_interval_seconds(['interval_sec' => '45', 'interval' => '5']),
  'interval_sec must win over a leftover minutes key.');

expect_equal(120, cfg_interval_seconds([]),
  'A config with no interval at all must use the default.');

expect_equal(120, cfg_interval_seconds(['interval_sec' => 'abc', 'interval' => '']),
  'A non-numeric interval_sec must fall back to the default.');

expect_equal(5, cfg_interval_seconds(['interval_sec' => '1']),
  'Below-floor values must be clamped up to the floor.');

expect_equal(3600, cfg_interval_seconds(['interval_sec' => '99999']),
  'Above-ceiling values must be clamped down to the ceiling.');

expect_equal(3600, cfg_interval_seconds(['interval' => '60']),
  'The old 60 min maximum must survive the conversion unclamped.');

if ($failures) {
  fwrite(STDERR, implode("\n\n", $failures) . "\n");
  fwrite(STDERR, count($failures) . " interval config assertion(s) failed.\n");
  exit(1);
}
echo "cfg_interval_seconds tests passed.\n";
