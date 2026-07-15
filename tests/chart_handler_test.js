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

  const sourceSpecificCurves = [
    { label: 'Disk: HDDs Temp → PWM (%)', sourceKey: 'disk:0', data: [{ x: 35, y: 25 }, { x: 45, y: 100 }] },
    { label: 'Disk: SSDs Temp → PWM (%)', sourceKey: 'disk:1', data: [{ x: 40, y: 25 }, { x: 60, y: 100 }] },
  ];
  const configuredMinimum = typeof context.configuredMinimumPoint === 'function'
    ? context.configuredMinimumPoint(sourceSpecificCurves)
    : null;
  if (configuredMinimum?.x !== 35 || configuredMinimum?.y !== 25) {
    failures.push(`Reference lines must use the global configured minima: ${JSON.stringify(configuredMinimum)}`);
  }

  const currentTooltipItem = { dataset: { currentReading: true } };
  const curveTooltipItem = { dataset: { currentReading: false } };
  if (typeof context.currentPointTooltipFilter !== 'function') {
    failures.push('Current-marker tooltip filtering is missing.');
  } else {
    const overlappingItems = [curveTooltipItem, currentTooltipItem];
    if (
      !context.currentPointTooltipFilter(currentTooltipItem, 1, overlappingItems)
      || context.currentPointTooltipFilter(curveTooltipItem, 0, overlappingItems)
      || !context.currentPointTooltipFilter(curveTooltipItem, 0, [curveTooltipItem])
    ) {
      failures.push('A live marker tooltip must replace its coincident curve tooltip row.');
    }
  }

  const fakeCanvas = { style: {} };
  const fakeOverlays = [{ style: {} }, { style: {} }];
  if (typeof context.stackCrosshairBehindTooltip !== 'function') {
    failures.push('Crosshair stacking is missing.');
  } else {
    context.stackCrosshairBehindTooltip(fakeCanvas, ...fakeOverlays);
    if (
      fakeCanvas.style.position !== 'relative'
      || fakeCanvas.style.zIndex !== '1'
      || fakeOverlays.some(element => element.style.zIndex !== '0')
    ) {
      failures.push(`Crosshair must render below the canvas tooltip: ${JSON.stringify({ fakeCanvas, fakeOverlays })}`);
    }
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

  const missingTemperatureMarkers = context.buildCurrentPointDatasets(datasets, [
    { source: 'disk:0', temp: 37, pwm: 114 },
  ]);
  const missingTemperatureMarker = missingTemperatureMarkers.find(marker => marker.sourceKey === 'disk:1');
  const missingTemperaturePoint = missingTemperatureMarker?.data[0];
  if (
    missingTemperaturePoint?.x !== 30
    || missingTemperaturePoint?.y !== 40
    || missingTemperaturePoint?.measuredTemp !== null
    || missingTemperaturePoint?.pwm !== 102
    || missingTemperaturePoint?.temperatureUnavailable !== true
  ) {
    failures.push(`A spun-down HDD group must retain a marker at its curve minimum: ${JSON.stringify(missingTemperatureMarker)}`);
  } else {
    const title = context.currentPointTooltipTitle({
      dataset: missingTemperatureMarker,
      raw: missingTemperaturePoint,
      parsed: missingTemperaturePoint,
    });
    if (title !== 'Temperature: -') {
      failures.push(`A spun-down HDD marker must show an unavailable temperature: ${title}`);
    }
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
  if (source.includes("className = 'chart-dot'")) {
    failures.push('The redundant crosshair dot must not be rendered.');
  }
  if (source.includes('activeSources') || source.includes('footerNote')) {
    failures.push('The graph must not render a redundant active-source footer.');
  }

  if (failures.length) {
    console.error(failures.join('\n'));
    process.exit(1);
  }
  console.log('chart handler tests passed');
})();
