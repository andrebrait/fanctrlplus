const fs = require('fs');
const vm = require('vm');
const assert = require('node:assert/strict');
const file = `${__dirname}/../src/usr/local/emhttp/plugins/fanctrlplus2/include/curve-editor.js`;
const context = { window: {} };
vm.createContext(context);
if (fs.existsSync(file)) vm.runInContext(fs.readFileSync(file, 'utf8'), context);
const curves = context.window.FcpCurves;
assert.ok(curves, 'Curve editing must validate and evaluate multi-point rules.');
assert.equal(curves.inputPwm({ value: '30%', dataset: { pwm: '76' } }), 76, 'Unedited whole-percent display preserves raw PWM.');
assert.equal(curves.inputPwm({ value: '31%', dataset: { pwm: '76' } }), 79, 'An edited percentage converts to a new raw PWM.');
const rule = { points: [[30, 76], [35, 89], [40, 115], [45, 153], [50, 255]], mode: 'linear', hysteresis: 2, emergency_temp: 55, emergency_pwm: 255 };
assert.equal(curves.validate(rule), null);
assert.equal(curves.evaluate(rule, 37, 200), 99);
assert.equal(curves.evaluate(rule, 10, 200), 76);
assert.equal(curves.evaluate(rule, 60, 100), 255, 'Emergency overrides the normal cap.');
assert.equal(curves.evaluate(rule, 52, 100), 100);
assert.equal(rule.points[4][1], 255, 'Changing cap does not rewrite points.');
const stepped = { ...rule, mode: 'stepped' };
assert.equal(curves.evaluate(stepped, 39, 255), 89);
assert.equal(curves.evaluate(stepped, 40, 255), 115, 'A step starts at its exact boundary.');
assert.equal(curves.validate({ ...rule, points: [[30, 76], [40, 76]] }), null, 'Flat sections are valid.');
const floorRules = { cpu: { ...rule, points: [[40, 10], [70, 255]] }, aux: { ...rule, points: [[40, 15], [70, 255]] }, 'disk:0': rule };
const enabled = { cpu: '0', aux: '0' };
const block = { querySelector: selector => ({ value: selector.includes('cpu') ? enabled.cpu : enabled.aux }) };
assert.equal(curves.floor(block, floorRules), 76, 'Disabled CPU/Aux rules cannot cap idle speed.');
enabled.cpu = '1';
assert.equal(curves.floor(block, floorRules), 10, 'An enabled CPU rule bounds idle speed.');
enabled.cpu = '0'; enabled.aux = '1';
assert.equal(curves.floor(block, floorRules), 15, 'An enabled Aux rule bounds idle speed.');
const earlyEmergency = { ...rule, points: [[40, 76], [60, 200]], emergency_temp: 30 };
assert.equal(typeof curves.bounds, 'function', 'The editor and preview need shared emergency-aware temperature bounds.');
for (const padding of [1, 5]) {
  const range = curves.bounds(earlyEmergency, padding);
  assert.ok(range.min < 30 && range.max > 60, 'Show normal output below an early emergency threshold without clipping the last point.');
}
assert.equal(curves.bounds({ ...earlyEmergency, emergency_temp: 0 }).min, 0, 'The displayed temperature domain cannot go below zero.');
assert.equal(curves.bounds({ ...earlyEmergency, emergency_temp: 300 }).max, 300, 'A threshold at the maximum temperature remains visible.');
for (const bad of [
  { ...rule, points: [[30, 76], [30, 100]] },
  { ...rule, points: [[30, 100], [40, 76]] },
  { ...rule, points: [[30, 76]] },
  { ...rule, points: Array.from({ length: 9 }, (_, i) => [i, i]) },
  { ...rule, points: [[30, 76], [301, 100]] },
  { ...rule, points: [[30, 76], [40, NaN]] },
  { ...rule, mode: 'spline' },
  { ...rule, hysteresis: -1 },
  { ...rule, emergency_pwm: 100 },
]) assert.equal(typeof curves.validate(bad), 'string', `Invalid rule accepted: ${JSON.stringify(bad)}`);
console.log('curve editor tests passed');
