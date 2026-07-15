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
if (!preg_match('/if \(!sortableUnlocked\) \{\s*\$col\.find\(\'\.sortable-placeholder\'\)\.remove\(\);\s*return;/s', $page)) {
  $failures[] = 'Empty-column drop zones must stay hidden while sorting is locked.';
}
if (!preg_match('/function initSortableUnlocked\(\).*?\$cols\.sortable\(opts\);\s*ensureColumnDroppable\(\);/s', $page)) {
  $failures[] = 'Unlocking sorting must add a drop target to each empty column.';
}
if (!preg_match('/function destroySortableLocked\(\).*?\$cols\.find\(\'\.sortable-placeholder\'\)\.remove\(\);/s', $page)) {
  $failures[] = 'Locking sorting must remove empty-column drop zones.';
}
if (str_contains($page, 'Drag Fan Configuration Here')) {
  $failures[] = 'Empty-column drop zones must not contain instructional text.';
}
if (!preg_match('/\.sortable-placeholder\s*\{[^}]*border:\s*1px\s+dashed\s+#bbb;/s', $css)) {
  $failures[] = 'Empty-column drop zones must use a dashed border.';
}
if (!preg_match('/\.sortable-placeholder\s*\{[^}]*background-color:\s*rgba\(249,\s*249,\s*249,\s*0\.4\);/s', $css)) {
  $failures[] = 'Empty-column drop zones must use a 40% opaque background.';
}
if (!preg_match('/\.ui-dropdownchecklist-item\s+\.ui-dropdownchecklist-text\s*\{[^}]*white-space:\s*nowrap\s*!important;/s', $css)) {
  $failures[] = 'Expanded disk selector rows must not wrap into adjacent rows.';
}
if (str_contains($page, 'fcp.base.css?v=1.3.1a')) {
  $failures[] = 'The stylesheet must not use a stale hard-coded cache key.';
}
if (!str_contains($page, "fcp_asset_url('css/fcp.base.css')")) {
  $failures[] = 'The stylesheet cache key must follow the installed content hash.';
}

if ($failures) {
  fwrite(STDERR, implode("\n", $failures) . "\n");
  exit(1);
}

echo "disk group UI tests passed\n";
