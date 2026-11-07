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
vm.runInContext(fs.readFileSync(`${__dirname}/../src/usr/local/emhttp/plugins/fanctrlplus2/include/curve-editor.js`, 'utf8'), context);
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

  const shaped = { data: [{ x: 30, y: 30 }, { x: 40, y: 35 }, { x: 50, y: 100 }] };
  const shapedPoint = context.curvePointAtTemperature(shaped, 35);
  if (shapedPoint.y !== 32.5) failures.push('A multi-point preview must interpolate adjacent points, not its overall endpoints.');
  shaped.stepped = 'before';
  if (context.curvePointAtTemperature(shaped, 39).y !== 30 || context.curvePointAtTemperature(shaped, 40).y !== 35) {
    failures.push('A stepped preview must hold the previous speed until the exact next boundary.');
  }
  const held = context.buildCurrentPointDatasets([datasets[0]], [{ source: 'disk:0', temp: 37, pwm: 180 }])[0].data[0];
  if (held.y !== 180 * 100 / 255) failures.push('A live marker must show actual runtime PWM, including hysteresis, rather than a projected ramp value.');
  const earlyEmergency = { points: [[40, 76], [60, 200]], mode: 'linear', hysteresis: 0, emergency_temp: 30, emergency_pwm: 255 };
  if (typeof context.curvePreviewData !== 'function') {
    failures.push('The preview must include normal output below an early emergency threshold.');
  } else {
    const preview = { data: context.curvePreviewData(earlyEmergency, 128) };
    if (preview.data[0].x >= 30 || context.curvePointAtTemperature(preview, 29.5).y !== 76 * 100 / 255
        || context.curvePointAtTemperature(preview, 30).y !== 100) {
      failures.push('An early emergency transition must be fully visible and occur at the exact threshold.');
    }
    const zero = { data: context.curvePreviewData({ ...earlyEmergency, emergency_temp: 0 }, 128) };
    if (context.curvePointAtTemperature(zero, 0).y !== 100) failures.push('An emergency threshold at zero must not show a fictitious normal region.');
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

  const previewReadings = typeof context.selectedSensorReadings === 'function'
    ? context.selectedSensorReadings(
        true,
        { textContent: 'coretemp - Package id 0 (34°C)' },
        true,
        [
          { textContent: 'nct6798 - System 1 (38.5°C)' },
          { textContent: 'nct6798 - System 2 (72°C)' },
        ]
      )
    : [];
  const mergedReadings = typeof context.mergeCurveReadings === 'function'
    ? context.mergeCurveReadings([{ source: 'disk:0', temp: 37, pwm: 114 }], previewReadings)
    : [];
  const previewCurves = [
    { label: 'CPU Temp → PWM (%)', sourceKey: 'cpu', borderColor: '#db4437', data: [{ x: 40, y: 40 }, { x: 70, y: 100 }] },
    { label: 'Aux Temp → PWM (%)', sourceKey: 'aux', borderColor: '#0f9d58', data: [{ x: 30, y: 40 }, { x: 60, y: 100 }] },
  ];
  const previewMarkers = context.buildCurrentPointDatasets(previewCurves, mergedReadings);
  const cpuPreviewPoint = previewMarkers.find(marker => marker.sourceKey === 'cpu')?.data[0];
  const auxPreviewPoint = previewMarkers.find(marker => marker.sourceKey === 'aux')?.data[0];
  if (
    cpuPreviewPoint?.x !== 40
    || cpuPreviewPoint?.y !== 40
    || cpuPreviewPoint?.measuredTemp !== 34
    || cpuPreviewPoint?.pwm !== 102
    || cpuPreviewPoint?.clamp !== 'min'
  ) {
    failures.push(`The CPU preview marker must use and clamp the selected sensor temperature: ${JSON.stringify(cpuPreviewPoint)}`);
  }
  if (
    auxPreviewPoint?.x !== 60
    || auxPreviewPoint?.y !== 100
    || auxPreviewPoint?.measuredTemp !== 72
    || auxPreviewPoint?.pwm !== 255
    || auxPreviewPoint?.clamp !== 'max'
  ) {
    failures.push(`The Aux preview marker must use the hottest selected sensor and clamp it: ${JSON.stringify(auxPreviewPoint)}`);
  }

  const bounds = context.temperatureBounds(datasets, [
    { source: 'disk:1', temp: 55, pwm: 255 },
    { source: 'cpu', temp: 80, pwm: 255 },
  ]);
  if (bounds.min !== 29 || bounds.max !== 51) {
    failures.push(`Measured temperatures must not expand the configured curve bounds: ${JSON.stringify(bounds)}`);
  }


  if (failures.length) {
    console.error(failures.join('\n'));
    process.exit(1);
  }
  console.log('chart handler tests passed');
})();
