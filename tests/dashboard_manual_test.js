const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const root = process.env.FCP_SOURCE_ROOT || path.join(__dirname, '../src/usr/local/emhttp/plugins/fanctrlplus2');
const page = fs.readFileSync(path.join(root, 'FanctrlPlus2.Dashboard.page'), 'utf8');
const script = page.slice(page.indexOf('<script>') + 8, page.lastIndexOf('</script>'));
let response = { ok: false, text: async () => JSON.stringify({ status: 'error', message: 'Curve-state lock unavailable' }) };
const context = {
  fetch: async () => response,
  document: { addEventListener() {}, getElementById: () => null },
  setTimeout() {},
};
vm.createContext(context);
vm.runInContext(script, context);
(async () => {
  await assert.rejects(Promise.resolve(context.refreshSingleFan('Fan')), /Curve-state lock unavailable/,
    'A refused manual run must reject instead of appearing successful on the dashboard.');
  response = { ok: false, text: async () => '<html>Gateway failure</html>' };
  await assert.rejects(Promise.resolve(context.refreshSingleFan('Fan')), /Request failed/,
    'A non-JSON gateway failure must still be reported as a manual-run failure.');
  console.log('dashboard manual tests passed');
})().catch(error => { console.error(error); process.exit(1); });
