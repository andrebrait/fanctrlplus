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
          { source: 'disk:0', temp: 20, pwm: 102 },
          { source: 'disk:1', temp: 55, pwm: 255 },
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
    const lowMarker = markers[0].data[0];
    if (lowMarker.x !== 35 || lowMarker.y !== 40 || lowMarker.measuredTemp !== 20 || lowMarker.pwm !== 102 || lowMarker.clamp !== 'min') {
      failures.push(`The below-range marker was not clamped to its curve minimum: ${JSON.stringify(markers[0])}`);
    }
    const highMarker = markers[1].data[0];
    if (highMarker.x !== 50 || highMarker.y !== 100 || highMarker.measuredTemp !== 55 || highMarker.pwm !== 255 || highMarker.clamp !== 'max') {
      failures.push(`The above-range marker was not clamped to its curve maximum: ${JSON.stringify(markers[1])}`);
    }
  }

  const inRangePoint = context.curvePointAtTemperature(datasets[0], 37);
  if (inRangePoint.x !== 37 || inRangePoint.y !== 52 || inRangePoint.clamp !== null) {
    failures.push(`The in-range point was not interpolated onto its curve: ${JSON.stringify(inRangePoint)}`);
  }

  const bounds = context.temperatureBounds(datasets, [
    { source: 'disk:1', temp: 55, pwm: 255 },
    { source: 'cpu', temp: 80, pwm: 255 },
  ]);
  if (bounds.min !== 29 || bounds.max !== 51) {
    failures.push(`Measured temperatures must not expand the configured curve bounds: ${JSON.stringify(bounds)}`);
  }

  if ((source.match(/pointRadius:\s*0/g) || []).length !== 3 || source.includes('makePointRadiusArray')) {
    failures.push('Curve lines must not render decorative data-point markers.');
  }

  if (failures.length) {
    console.error(failures.join('\n'));
    process.exit(1);
  }
  console.log('chart handler tests passed');
})();
