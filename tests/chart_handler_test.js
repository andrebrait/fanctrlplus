const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(
  `${__dirname}/../src/usr/local/emhttp/plugins/fanctrlplus2/include/chart-handler.js`,
  'utf8'
);
const context = {
  window: {},
  fetch: async url => url.includes('read_curve_points')
    ? {
        ok: true,
        json: async () => [
          { source: 'disk:0', temp: 37, pwm: 132 },
          { source: 'disk:1', temp: 45, pwm: 216 },
          { source: 'cpu', temp: 52, pwm: 180 },
        ],
      }
    : {
        ok: true,
        text: async () => '37 (Disk: Apps-pool)|938',
      },
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
    { label: 'Disk: Array Temp → PWM (%)', sourceKey: 'disk:0', borderColor: '#4285f4', data: [{ x: 35, y: 40 }, { x: 45, y: 100 }] },
    { label: 'Disk: Apps-pool Temp → PWM (%)', sourceKey: 'disk:1', borderColor: '#8e44ad', data: [{ x: 30, y: 40 }, { x: 50, y: 100 }] },
  ];
  const selected = typeof context.findDatasetForOrigin === 'function'
    ? context.findDatasetForOrigin(datasets, 'Disk: Apps-pool')
    : null;
  if (selected !== datasets[1]) {
    failures.push('The named disk source did not select its matching chart curve.');
  }

  const curvePoints = await context.fetchCurvePoints('Array');
  const markers = context.buildCurrentPointDatasets(datasets, curvePoints);
  if (markers.length !== 2) {
    failures.push(`Expected one current marker per disk curve, got ${markers.length}.`);
  } else {
    if (markers[0].data[0].x !== 37 || markers[0].data[0].pwm !== 132) {
      failures.push(`Array marker did not preserve its temperature/PWM: ${JSON.stringify(markers[0])}`);
    }
    if (Math.abs(markers[1].data[0].y - (216 * 100 / 255)) > 0.0001) {
      failures.push('The current marker did not convert raw PWM to chart percent.');
    }
  }

  const bounds = context.temperatureBounds(datasets, [
    { source: 'disk:1', temp: 55, pwm: 255 },
    { source: 'cpu', temp: 80, pwm: 255 },
  ]);
  if (bounds.min !== 29 || bounds.max !== 56) {
    failures.push(`Current temperatures were not included in chart bounds: ${JSON.stringify(bounds)}`);
  }

  if (failures.length) {
    console.error(failures.join('\n'));
    process.exit(1);
  }
  console.log('chart handler tests passed');
})();
