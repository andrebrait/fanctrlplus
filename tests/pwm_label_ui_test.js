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

const helperMatch = page.match(
  /function pwmLabelForSensor\(pwmList, sensor\) \{[\s\S]*?^  \}/m
);
assert(helperMatch, 'PWM label lookup helper must exist');

const pwmLabelForSensor = new Function(
  `${helperMatch[0]}; return pwmLabelForSensor;`
)();

const controllers = [
  { sensor: 'pwm1', label: 'Rear Fan' },
  { sensor: 'pwm2', label: '' }
];
assert.strictEqual(pwmLabelForSensor(controllers, 'pwm1'), 'Rear Fan');
assert.strictEqual(pwmLabelForSensor(controllers, 'pwm2'), '');
assert.strictEqual(pwmLabelForSensor(controllers, 'missing'), '');

assert.match(
  page,
  /'identify-pwm-select':\s*'identify-label-input'/,
  'The page Identify selector must target its Custom Label field'
);
assert.match(
  page,
  /'identify-modal-select':\s*'identify-modal-label-input'/,
  'The Identify modal selector must target its Custom Label field'
);
assert.match(
  page,
  /function bindPWMLabelInput\(selectId, pwmList\)[\s\S]*?\.off\('change\.fcpLabel'\)[\s\S]*?\.on\('change\.fcpLabel',[\s\S]*?pwmLabelForSensor\(pwmList, select\.val\(\)\)[\s\S]*?updateLabel\(\);/,
  'Loading and changing an Identify selector must populate its label input'
);
assert.match(
  page,
  /pwmList\.forEach\(pwm => \{[\s\S]*?bindPWMLabelInput\(selectId, pwmList\);/,
  'PWM option loading must bind label synchronization after populating options'
);

console.log('PWM label UI tests passed');
