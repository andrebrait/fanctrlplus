const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(
  `${__dirname}/../src/usr/local/emhttp/plugins/fanctrlplus2/include/asset-version.js`,
  'utf8'
);

(async () => {
  const failures = [];
  const notice = { hidden: true };
  const applyButton = { disabled: false };
  let reloadHandler = null;
  let reloads = 0;
  let confirmResult = false;

  const context = {
    window: {
      FCP_LOADED_ASSET_VERSION: '1111111111111111',
      confirm: () => confirmResult,
      location: { reload: () => { reloads += 1; } },
    },
    document: {
      readyState: 'complete',
      getElementById: id => ({
        'fcp-asset-update-notice': notice,
        'fcp-asset-reload': { addEventListener: (_event, handler) => { reloadHandler = handler; } },
        'apply-btn': applyButton,
      })[id] || null,
    },
    fetch: async (_url, options) => {
      if (options?.cache !== 'no-store') failures.push('Asset-version requests must bypass browser caches.');
      return { ok: true, json: async () => ({ version: '2222222222222222' }) };
    },
    setInterval: () => 42,
    clearInterval: () => {},
  };
  vm.createContext(context);
  vm.runInContext(source, context);
  await new Promise(resolve => setImmediate(resolve));

  if (notice.hidden) failures.push('A version mismatch must display the reload notice.');
  if (typeof reloadHandler !== 'function') failures.push('The reload button was not bound.');

  reloadHandler();
  if (reloads !== 0) failures.push('Declining the unsaved-changes warning must cancel reload.');

  applyButton.disabled = true;
  reloadHandler();
  if (reloads !== 1) failures.push('The reload button must reload when there are no unapplied changes.');

  if (failures.length) {
    console.error(failures.join('\n'));
    process.exit(1);
  }
  console.log('asset version monitor tests passed');
})();
