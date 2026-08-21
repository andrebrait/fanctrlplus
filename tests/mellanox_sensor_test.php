<?php
// Mellanox NIC temperature source (issue #1): PCI discovery from sysfs and
// parsing of the mget_temp reading.

$sourceRoot = getenv('FCP_SOURCE_ROOT') ?: __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2';
require_once $sourceRoot . '/include/Common.php';

$failures = [];

function expect_equal($expected, $actual, string $message): void {
  global $failures;
  if ($expected === $actual) return;
  $failures[] = sprintf("%s\nExpected: %s\nActual: %s", $message, var_export($expected, true), var_export($actual, true));
}

// ===== parse_mget_temp =====
expect_equal(71, parse_mget_temp("71\n"),
  'A bare reading must parse.');
expect_equal(63, parse_mget_temp("Temperature: 63 C\n"),
  'A labelled reading must parse.');
expect_equal(null, parse_mget_temp("Failed to open device\n"),
  'An error message without a number must not become a temperature.');
expect_equal(null, parse_mget_temp(''),
  'Empty output must not become a temperature.');
expect_equal(0, parse_mget_temp("0\n"),
  'A zero reading is a reading; an unreadable card fails the call instead.');
expect_equal(200, parse_mget_temp("250\n"),
  'A reading just above the ceiling is clamped, not discarded.');
// The failure the reporter of #1 described: the tool exits 0 and prints a
// sentinel when it cannot read the card. Offering that as a sensor, or letting
// it drive a fan, would be worse than having no reading.
expect_equal(null, parse_mget_temp("10000\n"),
  'A sentinel far above any temperature is not a reading.');
expect_equal(null, parse_mget_temp("-10000\n"),
  'Nor is one far below.');

// ===== list_mellanox_pci =====
$root = sys_get_temp_dir() . '/fcp_mlx_' . getmypid();
$devices = [
  // A Mellanox ConnectX physical function with a bound network interface.
  '0000:77:00.0' => ['vendor' => "0x15b3\n", 'net' => 'eth2'],
  // Its second port: same card, own function, own reading.
  '0000:77:00.1' => ['vendor' => "0x15b3\n", 'net' => 'eth3'],
  // A Mellanox device that never got a netdev (still readable via mget_temp).
  '0000:04:00.0' => ['vendor' => "0x15b3\n", 'net' => null],
  // Somebody else's card must not be offered as a Mellanox sensor.
  '0000:05:00.0' => ['vendor' => "0x8086\n", 'net' => 'eth0'],
];
foreach ($devices as $bdf => $spec) {
  mkdir("$root/$bdf", 0777, true);
  file_put_contents("$root/$bdf/vendor", $spec['vendor']);
  if ($spec['net'] !== null) {
    mkdir("$root/$bdf/net/" . $spec['net'], 0777, true);
  }
}
// A virtual function borrows the vendor ID but is the same silicon: reading it
// again would list the same card several times over.
mkdir("$root/0000:77:00.2/net/eth4", 0777, true);
file_put_contents("$root/0000:77:00.2/vendor", "0x15b3\n");
symlink("$root/0000:77:00.0", "$root/0000:77:00.2/physfn");

$found = list_mellanox_pci($root);

expect_equal(
  ['0000:04:00.0' => '', '0000:77:00.0' => 'eth2', '0000:77:00.1' => 'eth3'],
  $found,
  'Every Mellanox physical function must be listed with its interface name, and nothing else.'
);

// Clean up the fake sysfs tree.
unlink("$root/0000:77:00.2/physfn");
foreach (array_merge(array_keys($devices), ['0000:77:00.2']) as $bdf) {
  foreach (glob("$root/$bdf/net/*") as $iface) rmdir($iface);
  @rmdir("$root/$bdf/net");
  @unlink("$root/$bdf/vendor");
  rmdir("$root/$bdf");
}
rmdir($root);

if ($failures) {
  fwrite(STDERR, implode("\n\n", $failures) . "\n");
  fwrite(STDERR, count($failures) . " Mellanox sensor assertion(s) failed.\n");
  exit(1);
}
echo "Mellanox sensor tests passed.\n";
