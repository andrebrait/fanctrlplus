const assert = require('assert');
const fs = require('fs');
const path = require('path');

// The settings page carries one large inline script. Editing it by hand can
// leave it unparseable, which breaks the whole page rather than one feature, so
// it is parsed here as part of the suite.
const sourceRoot = process.env.FCP_SOURCE_ROOT || path.join(
  __dirname, '..', 'src', 'usr', 'local', 'emhttp', 'plugins', 'fanctrlplus2'
);
const page = fs.readFileSync(path.join(sourceRoot, 'fanctrlplus2.page'), 'utf8');

const open = page.indexOf('<script>', 5000);
assert(open !== -1, 'The page must carry its inline script');
const script = page
  .slice(open + '<script>'.length, page.lastIndexOf('</script>'))
  // Server-rendered values stand in as strings so the rest parses as JavaScript.
  .replace(/<\?(?:php|=)[\s\S]*?\?>/g, '"__php__"');

try {
  new Function(script);
} catch (error) {
  assert.fail(`The page's inline script does not parse: ${error.message}`);
}

console.log('page script tests passed.');
