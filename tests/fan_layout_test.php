<?php
// The settings page lays fan blocks out side by side and wraps to the next row
// when it runs out of width. They are one list: nothing arranges them into
// columns, and nothing needs to rearrange them when the window changes.

$sourceRoot = getenv('FCP_SOURCE_ROOT') ?: __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2';
$page   = file_get_contents($sourceRoot . '/fanctrlplus2.page');
$css    = file_get_contents($sourceRoot . '/css/fcp.base.css');
$logic  = file_get_contents($sourceRoot . '/include/FanctrlLogic.php');
$update = file_get_contents($sourceRoot . '/include/update.fanctrlplus2.php');
$failures = [];

// Nothing may carve the blocks into columns of its own: the grid does that, and
// every hand-rolled version of it was a source of bugs.
foreach ([
  'fan-column' => 'column containers',
  'fitColumns' => 'a column fitting pass',
  'displayColumnFor' => 'a column placement rule',
  'rememberBlockColumns' => 'per-block column memory',
] as $needle => $what) {
  if (str_contains($page, $needle)) {
    $failures[] = "The page still carries $what ($needle); the layout is the grid's job.";
  }
}
if (str_contains($css, '--fcp-columns') || str_contains($css, '.fan-column')) {
  $failures[] = 'The stylesheet still carries a column count of its own.';
}
// The standing empty-column blocks are gone; jQuery UI's own drag placeholder,
// ui-sortable-placeholder, is a different thing and stays.
if (preg_match('/(?<!ui-)sortable-placeholder/', $page) || preg_match('/(?<!ui-)sortable-placeholder/', $css)) {
  $failures[] = 'Standing empty-column blocks must be gone; only the drag placeholder remains.';
}

// The grid wraps on its own, from the width one block needs.
if (!preg_match('/#fan-area\s*\{[^}]*grid-template-columns:\s*repeat\(auto-fill,\s*minmax\((\d+)px/s', $css, $m)) {
  $failures[] = 'The block area must wrap automatically at the width a block needs.';
} elseif ((int)$m[1] < 400 || (int)$m[1] > 700) {
  $failures[] = "A block floor of {$m[1]}px is outside the plausible range for a fan block.";
}
// auto-fit would collapse the empty tracks and stretch a lone block across the
// whole page.
if (preg_match('/#fan-area\s*\{[^}]*auto-fit/s', $css)) {
  $failures[] = 'auto-fit stretches a single block across the page; auto-fill keeps a column width.';
}

// The order is one list everywhere it is read or written.
if (!str_contains($page, 'OrderManager::readOrder()')) {
  $failures[] = 'The page must read its order from OrderManager.';
}
if (preg_match('/strpos\(\$k, \'left\'\)/', $page)) {
  $failures[] = 'The page must not parse order.cfg itself.';
}
if (!preg_match('/name="order\[/', $page)) {
  $failures[] = 'The order must be posted as one list.';
}
foreach (['order_left' => $update, 'order_right' => $update, 'order_col' => $update] as $field => $haystack) {
  if (str_contains($haystack, $field)) {
    $failures[] = "The save handler still reads the \"$field\" fields.";
  }
}
if (str_contains($logic, "'columns'")) {
  $failures[] = 'The saveorder handler still expects columns.';
}

// One sortable, over the one container: two implementations drifted apart
// before, and the one that lost track of connecting them won.
$setups = preg_match_all('/\.sortable\(opts\)/', $page);
if ($setups !== 1) {
  $failures[] = "There must be exactly one sortable setup, found $setups.";
}
if (str_contains($page, 'initSortableUnlocked') || str_contains($page, 'destroySortableLocked')) {
  $failures[] = 'The second, divergent sortable setup must be gone.';
}

// The lock state is module-scope: a refactor that removes the block it was
// declared in leaves every reference to it throwing ReferenceError, and only
// when the user clicks the lock. Assert the declaration survives.
foreach (['sortableUnlocked'] as $stateVariable) {
  $declarations = preg_match_all('/\b(?:let|const|var)\s+' . preg_quote($stateVariable, '/') . '\b/', $page);
  $references = preg_match_all('/\b' . preg_quote($stateVariable, '/') . '\b/', $page);
  if ($references > 0 && $declarations !== 1) {
    $failures[] = "\"$stateVariable\" is referenced $references times but declared $declarations times.";
  }
}

if ($failures) {
  fwrite(STDERR, implode("\n", $failures) . "\n");
  fwrite(STDERR, count($failures) . " fan layout assertion(s) failed.\n");
  exit(1);
}
echo "fan layout tests passed.\n";
