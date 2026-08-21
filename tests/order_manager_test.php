<?php
// Fan block ordering. Blocks are arranged in columns on the settings page and
// their order is persisted per column, so the file has to carry as many columns
// as the layout shows -- and still read the two-column files written before.

$sourceRoot = getenv('FCP_SOURCE_ROOT') ?: __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2';
require_once $sourceRoot . '/include/OrderManager.php';

$failures = [];

function expect_equal($expected, $actual, string $message): void {
  global $failures;
  if ($expected === $actual) return;
  $failures[] = sprintf("%s\nExpected: %s\nActual: %s", $message, var_export($expected, true), var_export($actual, true));
}

$orderFile = sys_get_temp_dir() . '/fcp_order_' . getmypid() . '.cfg';
OrderManager::useOrderFile($orderFile);
$write = fn(string $body) => file_put_contents($orderFile, $body);
$cleanup = function () use ($orderFile) { if (is_file($orderFile)) unlink($orderFile); };

// ===== Reading =====
$cleanup();
expect_equal([], OrderManager::readOrder(), 'No order file means no recorded order.');

$write("order0=\"array.cfg\"\norder1=\"cpu.cfg\"\norder2=\"nvme.cfg\"\n");
expect_equal(
  ['array.cfg', 'cpu.cfg', 'nvme.cfg'],
  OrderManager::readOrder(),
  'The order is one list, in the order recorded.'
);

// Indexes are positions, not a count: gaps must not reorder anything.
$write("order5=\"last.cfg\"\norder2=\"first.cfg\"\n");
expect_equal(['first.cfg', 'last.cfg'], OrderManager::readOrder(), 'Blocks are ordered by index.');

// Files written while the blocks were arranged in columns are read back in the
// order they appeared on screen: left to right along each row, then down.
$write("col0_0=\"a.cfg\"\ncol0_1=\"c.cfg\"\ncol1_0=\"b.cfg\"\ncol1_1=\"d.cfg\"\n");
expect_equal(
  ['a.cfg', 'b.cfg', 'c.cfg', 'd.cfg'],
  OrderManager::readOrder(),
  'A column arrangement is flattened the way it was read on screen.'
);

// Same for the two-column files written before that.
$write("left0=\"a.cfg\"\nleft1=\"c.cfg\"\nright0=\"b.cfg\"\n");
expect_equal(
  ['a.cfg', 'b.cfg', 'c.cfg'],
  OrderManager::readOrder(),
  'A left/right file is flattened the same way.'
);

// A short column must not leave a hole in the list.
$write("col0_0=\"a.cfg\"\ncol0_1=\"c.cfg\"\ncol1_0=\"b.cfg\"\n");
expect_equal(
  ['a.cfg', 'b.cfg', 'c.cfg'],
  OrderManager::readOrder(),
  'A column running out early leaves no gap.'
);

// ===== Writing =====
$cleanup();
OrderManager::writeOrder(['array.cfg', 'cpu.cfg']);
expect_equal(
  "order0=\"array.cfg\"\norder1=\"cpu.cfg\"\n",
  file_get_contents($orderFile),
  'The order is written as one list.'
);
expect_equal(
  ['array.cfg', 'cpu.cfg'],
  OrderManager::readOrder(),
  'What was written reads back unchanged.'
);

// Saving a file in either older shape migrates it.
$write("left0=\"array.cfg\"\nright0=\"cpu.cfg\"\n");
OrderManager::writeOrder(OrderManager::readOrder());
$migrated = file_get_contents($orderFile);
expect_equal(false, str_contains($migrated, 'left0'), 'Saving migrates a two-column file off the old keys.');
expect_equal(['array.cfg', 'cpu.cfg'], OrderManager::readOrder(), 'Migration preserves the order.');

// ===== Removing =====
$write("order0=\"array.cfg\"\norder1=\"cpu.cfg\"\norder2=\"nvme.cfg\"\n");
OrderManager::remove('cpu.cfg');
expect_equal(
  ['array.cfg', 'nvme.cfg'],
  OrderManager::readOrder(),
  'A removed block leaves no gap behind it.'
);

// ===== Renaming =====
$write("order0=\"array.cfg\"\norder1=\"cpu.cfg\"\n");
OrderManager::replaceFileName('cpu.cfg', 'processor.cfg');
expect_equal(
  ['array.cfg', 'processor.cfg'],
  OrderManager::readOrder(),
  'A renamed config keeps its place.'
);

// A rename must still work on a file that has not been migrated yet.
$write("left0=\"array.cfg\"\nright0=\"cpu.cfg\"\n");
OrderManager::replaceFileName('cpu.cfg', 'processor.cfg');
expect_equal(
  ['array.cfg', 'processor.cfg'],
  OrderManager::readOrder(),
  'A rename reaches into an older file too.'
);

$cleanup();

if ($failures) {
  fwrite(STDERR, implode("\n\n", $failures) . "\n");
  fwrite(STDERR, count($failures) . " order manager assertion(s) failed.\n");
  exit(1);
}
echo "order manager tests passed.\n";
