const assert = require('assert');
const fs = require('fs');
const path = require('path');

const sourceRoot = process.env.FCP_SOURCE_ROOT || path.join(
  __dirname, '..', 'src', 'usr', 'local', 'emhttp', 'plugins', 'fanctrlplus2'
);
const page = fs.readFileSync(path.join(sourceRoot, 'fanctrlplus2.page'), 'utf8');
const render = fs.readFileSync(path.join(sourceRoot, 'include', 'FanBlockRender.php'), 'utf8');
const css = fs.readFileSync(path.join(sourceRoot, 'css', 'fcp.base.css'), 'utf8');

// Dragging is unusable on a touch screen -- it fights scrolling, and there is
// no reliable way to see where a block will land -- so a narrow layout reorders
// with a button per direction instead. Where a block lands, and whether the
// button is offered at all, come from one rule so they cannot disagree.
const ruleMatch = page.match(/function reorderTargetIndex\(index, direction, count\) \{[\s\S]*?\n  \}/);
assert(ruleMatch, 'The reorder rule must exist');
const reorderTargetIndex = new Function(`${ruleMatch[0]}; return reorderTargetIndex;`)();

assert.strictEqual(reorderTargetIndex(1, -1, 3), 0, 'Moving up goes one earlier');
assert.strictEqual(reorderTargetIndex(1, 1, 3), 2, 'Moving down goes one later');
assert.strictEqual(reorderTargetIndex(0, -1, 3), null, 'The first block cannot move up');
assert.strictEqual(reorderTargetIndex(2, 1, 3), null, 'The last block cannot move down');
assert.strictEqual(reorderTargetIndex(0, 1, 1), null, 'A lone block cannot move at all');
assert.strictEqual(reorderTargetIndex(0, -1, 1), null, 'in either direction');
assert.strictEqual(reorderTargetIndex(0, 1, 2), 1, 'Two blocks can still swap');

// The list wraps, so "earlier" is up in a single column and to the left once
// blocks sit side by side. The arrows follow what the eye sees.
const iconMatch = page.match(/function reorderArrowIcons\(columnsShown\) \{[\s\S]*?\n  \}/);
assert(iconMatch, 'The arrow direction rule must exist');
const reorderArrowIcons = new Function(`${iconMatch[0]}; return reorderArrowIcons;`)();

assert.match(reorderArrowIcons(1).earlier, /arrow-up/, 'One column: earlier is up');
assert.match(reorderArrowIcons(1).later, /arrow-down/, 'One column: later is down');
assert.match(reorderArrowIcons(2).earlier, /arrow-left/, 'Side by side: earlier is to the left');
assert.match(reorderArrowIcons(3).later, /arrow-right/, 'Side by side: later is to the right');
assert.match(reorderArrowIcons(0).earlier, /arrow-up/, 'An unknown column count reads as one');

// How many columns are on screen is asked of the browser rather than derived
// from a breakpoint, so it cannot drift from what the grid actually did.
assert.match(
  page,
  /getComputedStyle\([^)]*\)\.gridTemplateColumns/,
  'The column count must come from the resolved grid, not a duplicated breakpoint'
);

// Both buttons exist on every block, and their disabled state is refreshed
// rather than set once: it changes every time a block moves.
assert.match(render, /class="[^"]*fcp-move-up/, 'Each block must offer a move-up button');
assert.match(render, /class="[^"]*fcp-move-down/, 'Each block must offer a move-down button');
assert.match(page, /function updateReorderButtons\(\)/, 'The disabled state must be refreshable');
assert.match(
  page,
  /updateReorderButtons[\s\S]{0,600}reorderTargetIndex\(/,
  'The disabled state must come from the same rule as the move'
);

// Which block is first and last changes when one is deleted, so the buttons
// have to be refreshed there too: the block above the deleted one would
// otherwise keep a disabled arrow that should now work.
const deleteMatch = page.match(/\$\(document\)\.on\('click', '\.delete-btn'[\s\S]*?\n  \}\);/);
assert(deleteMatch, 'The delete handler must exist');
assert.match(
  deleteMatch[0],
  /updateReorderButtons\(\)/,
  'Deleting a block must refresh which blocks can still move'
);

// A flex item will not shrink below its content unless told to, and the page's
// own button rules set a font size in rem. Both would make these wider than
// they are asked to be, so the size is pinned rather than merely requested.
const buttonRule = css.match(/\.fcp-reorder button \{[^}]*\}/);
assert(buttonRule, 'The reorder buttons must be sized');
assert.match(buttonRule[0], /min-width:\s*0/, 'They must be allowed to shrink to the width given');
assert.match(buttonRule[0], /flex:\s*0 0 auto/, 'and must not stretch to fill the row');
assert.match(buttonRule[0], /width:\s*(\d+)px/, 'with an explicit width');
assert.match(buttonRule[0], /height:\s*(\d+)px/, 'and an explicit height');
assert.match(buttonRule[0], /font-size:\s*\d+px/, 'sized in px, so the page\'s rem base cannot change it');
const width = Number(buttonRule[0].match(/width:\s*(\d+)px/)[1]);
assert.ok(width >= 20 && width <= 34, `A ${width}px reorder button is outside a sensible touch target`);

// The buttons are the only way to reorder, at every width. Dragging is gone,
// and with it the drag ghost, the helper styling, the handle and the machinery
// that maintained them.
assert.doesNotMatch(page, /\.sortable\(/, 'Nothing may set up a sortable any more');
assert.doesNotMatch(page, /usesButtonReorder/, 'There is no longer a second way to reorder');
assert.doesNotMatch(page, /drag-handle/, 'The drag handle must be gone from the page');
assert.doesNotMatch(render, /drag-handle/, 'and from the markup');
assert.doesNotMatch(css, /fan-placeholder|ui-sortable-helper|drag-handle/, 'and from the styling');
// Checked by removing every @media block and looking at what is left, since a
// regex cannot tell nesting from adjacency.
let cssOutsideMediaQueries = css;
for (;;) {
  const at = cssOutsideMediaQueries.indexOf('@media');
  if (at === -1) break;
  const open = cssOutsideMediaQueries.indexOf('{', at);
  if (open === -1) break;
  let depth = 0;
  let end = open;
  for (let k = open; k < cssOutsideMediaQueries.length; k++) {
    if (cssOutsideMediaQueries[k] === '{') depth++;
    if (cssOutsideMediaQueries[k] === '}') { depth--; if (depth === 0) { end = k; break; } }
  }
  cssOutsideMediaQueries = cssOutsideMediaQueries.slice(0, at) + cssOutsideMediaQueries.slice(end + 1);
}
assert.match(
  cssOutsideMediaQueries,
  /\.fan-block\.reorderable \.fcp-reorder\s*\{[^}]*display:\s*flex/,
  'The buttons must be offered at every width, not only on a narrow screen'
);

console.log('reorder button tests passed.');
