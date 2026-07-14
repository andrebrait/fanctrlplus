const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(
  `${__dirname}/../src/usr/local/emhttp/plugins/fanctrlplus2/include/chart-handler.js`,
  'utf8'
);
const context = {
  window: {},
  fetch: async () => ({
    ok: true,
    text: async () => '37 (Disk: Apps-pool)|938',
  }),
};
vm.createContext(context);
vm.runInContext(source, context);

(async () => {
  const failures = [];
  const reading = await context.fetchRealtimeData('Array');
  if (reading.temp !== 37 || reading.origin !== 'Disk: Apps-pool' || reading.rpm !== 938) {
    failures.push(`Named disk-group reading was not parsed: ${JSON.stringify(reading)}`);
  }

  const datasets = [
    { label: 'Disk: Array Temp → PWM (%)' },
    { label: 'Disk: Apps-pool Temp → PWM (%)' },
  ];
  const selected = typeof context.findDatasetForOrigin === 'function'
    ? context.findDatasetForOrigin(datasets, 'Disk: Apps-pool')
    : null;
  if (selected !== datasets[1]) {
    failures.push('The named disk source did not select its matching chart curve.');
  }

  if (failures.length) {
    console.error(failures.join('\n'));
    process.exit(1);
  }
  console.log('chart handler tests passed');
})();
