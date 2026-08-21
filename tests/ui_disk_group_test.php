<?php

$sourceRoot = getenv('FCP_SOURCE_ROOT') ?: __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2';
$render = file_get_contents($sourceRoot . '/include/FanBlockRender.php');
$page = file_get_contents($sourceRoot . '/fanctrlplus2.page');
$css = file_get_contents($sourceRoot . '/css/fcp.base.css');
$failures = [];

// The cell hosting the nested table carries the same 1px cell padding as every
// other cell, and the nested cells add their own on top, so the group labels
// start one pixel right of the labels above them. The hosting cell gives its
// padding up so the two tables share a left edge.
if (!preg_match('/<td colspan="2" class="[^"]*fcp-groups-cell/', $render)) {
  $failures[] = 'The cell hosting the disk groups must be identifiable for its padding to be removed.';
}
if (!preg_match('/\.fcp-groups-cell\s*\{[^}]*padding-left:\s*0;[^}]*padding-right:\s*0;/s', $css)) {
  $failures[] = 'The disk groups cell must not indent its nested table.';
}

// Disk groups render in their own nested table, so its label column sizes
// itself independently of the fan block's and the two drift apart. The widest
// label in the outer table is the reference both are calibrated to.
// The reference is derived rather than written down, so renaming a label
// cannot silently leave the calibration pointing at the wrong string. Length in
// characters stands in for width in pixels: a rough proxy, but enough to catch
// the calibration drifting off the widest label entirely.
preg_match_all('/>\s*([A-Za-z0-9 ()\/]+:)\s*<\/td>/s', $render, $labelMatches);
$rowLabels = $labelMatches[1] ?? [];
usort($rowLabels, fn($a, $b) => strlen($b) <=> strlen($a));
$referenceLabel = $rowLabels[0] ?? '';
if ($referenceLabel === '') {
  $failures[] = 'No row labels were found in the fan block markup.';
}
if (!preg_match('/\.disk-group-row table td:first-child::before\s*\{[^}]*content:\s*"' . preg_quote($referenceLabel, '/') . '"/s', $css)) {
  $failures[] = "The disk group label column must be calibrated to \"$referenceLabel\".";
}
// Checked by removing every @media block and looking for the rule in what is
// left, since a regex cannot tell nesting from adjacency.
$cssOutsideMediaQueries = $css;
while (($at = strpos($cssOutsideMediaQueries, '@media')) !== false) {
  $open = strpos($cssOutsideMediaQueries, '{', $at);
  if ($open === false) break;
  $depth = 0;
  $end = $open;
  for ($k = $open, $len = strlen($cssOutsideMediaQueries); $k < $len; $k++) {
    if ($cssOutsideMediaQueries[$k] === '{') $depth++;
    if ($cssOutsideMediaQueries[$k] === '}') {
      $depth--;
      if ($depth === 0) { $end = $k; break; }
    }
  }
  $cssOutsideMediaQueries = substr($cssOutsideMediaQueries, 0, $at) . substr($cssOutsideMediaQueries, $end + 1);
}
if (!str_contains($cssOutsideMediaQueries, '.disk-group-row table td:first-child::before')) {
  $failures[] = 'The calibration must apply at every width: the columns need to line up on a phone too.';
}
if (!preg_match('/\.disk-group-row[^{]*td:first-child::before\s*\{[^}]*height:\s*0;/s', $css)) {
  $failures[] = 'The calibration must not occupy any visible space of its own.';
}

// Row labels are kept short so a fan block fits a phone screen without the
// label column pushing the controls sideways.
foreach ([
  'Group Temperature Range:',
  'CPU Temp Monitor:',
  'CPU Temperature Range:',
  'Aux Temp Monitor:',
  'Aux Temperature Range:',
  'Include Sensor(s):',
  'Include Disk(s):',
  'Fan Speed Range:',
  'Fan Speed on Idle:',
] as $retired) {
  // Whitespace tolerant: some labels sit on their own line in the markup.
  if (preg_match('/>\s*' . preg_quote($retired, '/') . '\s*<\/td>/s', $render)) {
    $failures[] = "The long row label \"$retired\" must be shortened for narrow screens.";
  }
}
foreach ([
  'Range:' => 3, 'Monitor:' => 2, 'Sensor(s):' => 1,
  'Disk(s):' => 1, 'Speed Range:' => 1, 'Speed on Idle:' => 1,
] as $label => $expected) {
  $actual = preg_match_all('/>\s*' . preg_quote($label, '/') . '\s*<\/td>/s', $render);
  if ($actual !== $expected) {
    $failures[] = "Expected $expected occurrences of the label \"$label\" in the fan block, found $actual.";
  }
}

// The widget writes an inline pixel width on the open menu, sized to its widest
// option, which on a phone is wider than the screen and pushes the page
// sideways. On narrow screens it is capped at the cell instead.
if (!preg_match('/@media \(max-width: 1024px\)\s*\{.*\.fcp-ddcl-cell \.ui-dropdownchecklist-dropcontainer-wrapper[^}]*max-width:\s*100%/s', $css)) {
  $failures[] = 'The open menu must be capped at its cell on narrow screens.';
}
// Each option row has a fixed height, so a wrapped option is drawn over the
// next one and its second line starts under the icon rather than the text. The
// options stay on one line at every width; the capped menu scrolls instead.
if (preg_match('/\.fcp-ddcl-cell[^{]*\.ui-dropdownchecklist-item \.ui-dropdownchecklist-text\s*\{[^}]*white-space:\s*normal/s', $css)) {
  $failures[] = 'Option text must never wrap: the row height is fixed, so it would overlap the next option.';
}
if (!preg_match('/@media \(max-width: 1024px\)\s*\{.*\.fcp-ddcl-cell \.ui-dropdownchecklist-dropcontainer\s*\{[^}]*overflow-x:\s*auto/s', $css)) {
  $failures[] = 'A menu capped narrower than its options must scroll horizontally.';
}

// The dropdownchecklist widget positions its open menu with
// jQuery .position(), which measures against elem.offsetParent -- the table
// cell, by the table-cell rule, whether or not it is positioned. The menu
// itself is position:absolute, so its containing block is the nearest
// POSITIONED ancestor. Unless the hosting cell is positioned those are two
// different boxes and the menu lands far from its field, near the top left of
// whichever ancestor happens to be positioned.
foreach (['disk-select', 'aux-select'] as $selectClass) {
  $offset = 0;
  $found = 0;
  while (($pos = strpos($render, $selectClass, $offset)) !== false) {
    $offset = $pos + 1;
    $found++;
    $cellStart = strrpos(substr($render, 0, $pos), '<td');
    if ($cellStart === false) {
      $failures[] = "A $selectClass is not inside a table cell.";
      continue;
    }
    $cellTag = substr($render, $cellStart, strpos($render, '>', $cellStart) - $cellStart + 1);
    if (!str_contains($cellTag, 'fcp-ddcl-cell')) {
      $failures[] = "The cell hosting a $selectClass must carry fcp-ddcl-cell so the open menu "
        . "is positioned against the same box the widget measured: $cellTag";
    }
  }
  if ($found === 0) {
    $failures[] = "No $selectClass was found in the fan block markup.";
  }
}
if (!preg_match('/\.fcp-ddcl-cell\s*\{[^}]*position:\s*relative;/s', $css)) {
  $failures[] = 'fcp-ddcl-cell must be positioned, or it cannot be the menu\'s containing block.';
}

if (!str_contains($render, 'class="disk-group-heading"')) {
  $failures[] = 'The disk group name and remove button must share a constrained wrapper.';
}
if (preg_match('/class="disk-group-name-input[^\"]*fcp-w-300/', $render)) {
  $failures[] = 'The disk group name input must not force a fixed 300px width.';
}
if (!str_contains($css, 'grid-template-columns: minmax(0, 1fr) auto;')) {
  $failures[] = 'The disk group heading must reserve space for the remove button.';
}
if (!preg_match('/\.disk-group-heading\s*\{[^}]*max-width:\s*300px;/s', $css)) {
  $failures[] = 'The disk group heading must align with neighboring 300px controls.';
}
if (!preg_match('/\.disk-group-heading\s+\.remove-disk-group-btn\s*\{[^}]*width:\s*32px;[^}]*height:\s*32px;[^}]*padding:\s*0;/s', $css)) {
  $failures[] = 'The icon-only disk group remove button must stay square at every viewport width.';
}
if (!preg_match('/\.disk-group-row\s*\+\s*\.disk-group-row\s*\{[^}]*border-top:\s*1px\s+solid\s+rgba\(127,\s*127,\s*127,\s*0\.35\);[^}]*margin-top:\s*10px;[^}]*padding-top:\s*10px;/s', $css)) {
  $failures[] = 'Adjacent disk groups must have a spaced horizontal separator.';
}
// The empty-column drop zones these used to pin are gone: blocks are one list
// that wraps, so there are no empty columns to stand in for. That they stay
// gone is asserted in tests/fan_layout_test.php.
if (!preg_match('/\.remove-disk-group-btn.*?const groupName\s*=.*?const msg\s*=.*?if \(!confirm\(msg\)\) return;.*?\$row\.remove\(\);/s', $page)) {
  $failures[] = 'Removing a disk group must require named confirmation before changing the page.';
}
if (str_contains($page, 'Drag Fan Configuration Here')) {
  $failures[] = 'The layout must not carry instructional text for empty drop zones.';
}
if (!preg_match('/\.fcp-asset-update-notice\s*\{[^}]*flex-wrap:\s*wrap;/s', $css)) {
  $failures[] = 'The asset update notice must wrap on narrow screens.';
}
if (!preg_match('/\.fcp-asset-update-notice\s+button\s*\{[^}]*flex:\s*none;[^}]*width:\s*auto;[^}]*white-space:\s*nowrap;/s', $css)) {
  $failures[] = 'The reload button must keep its intrinsic text width.';
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
