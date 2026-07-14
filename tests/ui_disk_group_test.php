<?php

$sourceRoot = getenv('FCP_SOURCE_ROOT') ?: __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2';
$render = file_get_contents($sourceRoot . '/include/FanBlockRender.php');
$page = file_get_contents($sourceRoot . '/fanctrlplus2.page');
$css = file_get_contents($sourceRoot . '/css/fcp.base.css');
$failures = [];

if (!str_contains($render, 'class="disk-group-heading"')) {
  $failures[] = 'The disk group name and remove button must share a constrained wrapper.';
}
if (preg_match('/class="disk-group-name-input[^\"]*fcp-w-300/', $render)) {
  $failures[] = 'The disk group name input must not force a fixed 300px width.';
}
if (!str_contains($css, 'grid-template-columns: minmax(0, 1fr) auto;')) {
  $failures[] = 'The disk group heading must reserve space for the remove button.';
}
if (preg_match('/if \(!sortableUnlocked\) \{\s*\$col\.find\(\'\.sortable-placeholder\'\)\.remove\(\);\s*return;/s', $page)) {
  $failures[] = 'Empty columns must keep their drag target while sorting is locked.';
}
if (!preg_match('/function initSortableUnlocked\(\).*?\$cols\.sortable\(opts\);\s*ensureColumnDroppable\(\);/s', $page)) {
  $failures[] = 'Unlocking sorting must add a drop target to each empty column.';
}
if (!preg_match('/function destroySortableLocked\(\).*?ensureColumnDroppable\(\);/s', $page)) {
  $failures[] = 'Locking sorting must preserve empty-column drag targets.';
}
if (!preg_match('/\.ui-dropdownchecklist-item\s+\.ui-dropdownchecklist-text\s*\{[^}]*white-space:\s*nowrap\s*!important;/s', $css)) {
  $failures[] = 'Expanded disk selector rows must not wrap into adjacent rows.';
}
if (str_contains($page, 'fcp.base.css?v=1.3.1a')) {
  $failures[] = 'The stylesheet must not use a stale hard-coded cache key.';
}
if (!preg_match('/filemtime\([^\n]*css\/fcp\.base\.css/', $page)) {
  $failures[] = 'The stylesheet cache key must follow the installed file version.';
}

if ($failures) {
  fwrite(STDERR, implode("\n", $failures) . "\n");
  exit(1);
}

echo "disk group UI tests passed\n";
