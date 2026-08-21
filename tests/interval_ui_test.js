const assert = require('assert');
const fs = require('fs');
const path = require('path');

const sourceRoot = process.env.FCP_SOURCE_ROOT || path.join(
  __dirname,
  '..',
  'src',
  'usr',
  'local',
  'emhttp',
  'plugins',
  'fanctrlplus2'
);
const page = fs.readFileSync(path.join(sourceRoot, 'fanctrlplus2.page'), 'utf8');
const render = fs.readFileSync(path.join(sourceRoot, 'include', 'FanBlockRender.php'), 'utf8');
const update = fs.readFileSync(path.join(sourceRoot, 'include', 'update.fanctrlplus2.php'), 'utf8');

// The interval field is a seconds field everywhere it is bound, so a fan can
// react faster than once a minute (issue #1).
const bindings = page.match(/\{ selector: 'input\[id\^="interval_input_"\]'[^}]*\}/g);
assert(bindings && bindings.length >= 2,
  'Every bindUnitInputs call must bind the interval field');
bindings.forEach(binding => {
  assert.match(binding, /unit: ' sec'/, `Interval binding must use seconds: ${binding}`);
  assert.match(binding, /min: 5\b/, `Interval binding must enforce the 5s floor: ${binding}`);
  assert.match(binding, /max: 3600\b/, `Interval binding must cap at one hour: ${binding}`);
});

// The seconds suffix has to come back off before the value is posted or
// compared against the saved config, or every field looks permanently dirty.
const stripMatch = page.match(/function stripUnit\(val\) \{[\s\S]*?\n  \}/);
assert(stripMatch, 'stripUnit must exist');
const stripUnit = new Function(`${stripMatch[0]}; return stripUnit;`)();
assert.strictEqual(stripUnit('300 sec'), '300');
assert.strictEqual(stripUnit('5 min'), '5');
assert.strictEqual(stripUnit('60 °C'), '60');
assert.strictEqual(stripUnit('40%'), '40');

// The rendered field shows seconds, converted from whatever the config holds.
assert.match(render, /cfg_interval_seconds\(\$cfg\)/,
  'The interval field must render through the shared seconds helper');
assert.match(render, /' sec'/,
  'The interval field must carry the seconds suffix');
assert.doesNotMatch(render, /\$cfg\['interval'\]/,
  'The raw minutes key must no longer be rendered');

// Saving writes the seconds key; the minutes key is not written back, so a
// config is migrated the first time it is saved.
assert.match(update, /'interval_sec'\s*=>/,
  'The save handler must write the seconds key');
// The minutes key is still written, rounded up, purely so that rolling the
// plugin back to a version that reads it does not leave the loop sleeping 0s.
assert.match(update, /'interval'\s*=>\s*\(string\)max\(1, \(int\)ceil\(\$interval_seconds \/ 60\)\)/,
  'The save handler must keep a rounded minutes key for plugin rollback');

// Both new-fan templates start from a seconds interval.
assert.match(page, /interval_sec="120"/,
  'The bootstrap config template must use the seconds key');
const logic = fs.readFileSync(path.join(sourceRoot, 'include', 'FanctrlLogic.php'), 'utf8');
assert.match(logic, /interval_sec="120"/,
  'The new-fan config template must use the seconds key');

console.log('interval UI tests passed.');
