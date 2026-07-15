// chart-handler.js - Show temp→PWM chart for a fan block

async function fetchRealtimeData(custom) {
  const res = await fetch(`/plugins/fanctrlplus2/include/FanctrlLogic.php?op=read_temp_rpm&custom=${encodeURIComponent(custom)}`, {
    cache: 'no-store',
  });
  if (!res.ok) return { noCache: true };

  const raw = (await res.text()).trim();
  return parseRealtimeData(raw);
}

async function fetchCurvePoints(custom) {
  const res = await fetch(`/plugins/fanctrlplus2/include/FanctrlLogic.php?op=read_curve_points&custom=${encodeURIComponent(custom)}`, {
    cache: 'no-store',
  });
  if (!res.ok) return [];

  try {
    return normalizeCurvePoints(await res.json());
  } catch (_error) {
    return [];
  }
}

function normalizeCurvePoints(value) {
  if (!Array.isArray(value)) return [];

  const points = new Map();
  value.forEach(item => {
    const source = (item?.source ?? '').toString();
    const temp = Number(item?.temp);
    const pwm = Number(item?.pwm);
    if (!/^(?:cpu|aux|disk:\d+)$/.test(source)) return;
    if (!Number.isFinite(temp) || !Number.isFinite(pwm) || pwm < 0 || pwm > 255) return;
    points.set(source, { source, temp, pwm });
  });
  return [...points.values()];
}

function curvePointAtTemperature(curve, measuredTemp) {
  if (!curve || !Array.isArray(curve.data) || !Number.isFinite(measuredTemp)) return null;
  const points = curve.data.filter(point => Number.isFinite(point.x) && Number.isFinite(point.y));
  if (!points.length) return null;

  const low = points.reduce((current, point) => point.x < current.x ? point : current);
  const high = points.reduce((current, point) => point.x > current.x ? point : current);
  const x = Math.min(Math.max(measuredTemp, low.x), high.x);
  const ratio = high.x === low.x ? 0 : (x - low.x) / (high.x - low.x);
  const y = low.y + (high.y - low.y) * ratio;

  return {
    x,
    y,
    clamp: measuredTemp < low.x ? 'min' : measuredTemp > high.x ? 'max' : null,
  };
}

function buildCurrentPointDatasets(curveDatasets, readings) {
  const bySource = new Map(readings.map(reading => [reading.source, reading]));

  return curveDatasets.flatMap(curve => {
    const reading = bySource.get(curve.sourceKey);
    const temperatureUnavailable = !reading && /^disk:\d+$/.test(curve.sourceKey);
    if (!reading && !temperatureUnavailable) return [];

    const measuredTemp = temperatureUnavailable
      ? Math.min(...curve.data.map(point => point.x).filter(value => Number.isFinite(value)))
      : reading.temp;
    const position = curvePointAtTemperature(curve, measuredTemp);
    if (!position) return [];
    const currentLabel = curve.label.replace(/\s+Temp\s+→.*$/, '');
    return [{
      label: `${currentLabel} current`,
      currentLabel,
      currentReading: true,
      sourceKey: curve.sourceKey,
      data: [{
        x: position.x,
        y: position.y,
        pwm: temperatureUnavailable ? Math.round(position.y * 2.55) : reading.pwm,
        measuredTemp: temperatureUnavailable ? null : reading.temp,
        temperatureUnavailable,
        clamp: position.clamp,
      }],
      borderColor: curve.borderColor,
      backgroundColor: curve.borderColor,
      pointBackgroundColor: curve.borderColor,
      pointBorderColor: '#fff',
      pointBorderWidth: 2,
      pointRadius: 7,
      pointHoverRadius: 9,
      showLine: false,
      order: -1,
    }];
  });
}

function currentPointTooltipTitle(item) {
  if (item.dataset.currentReading && item.raw.temperatureUnavailable) return 'Temperature: -';
  if (item.dataset.currentReading && item.raw.clamp === 'min') {
    return `Curve minimum ${item.parsed.x}°C (measured ${item.raw.measuredTemp}°C)`;
  }
  if (item.dataset.currentReading && item.raw.clamp === 'max') {
    return `Curve maximum ${item.parsed.x}°C (measured ${item.raw.measuredTemp}°C)`;
  }
  return `${item.parsed.x}°C`;
}

function currentPointTooltipFilter(item, _index, items) {
  return !items.some(candidate => candidate.dataset.currentReading) || item.dataset.currentReading;
}

function configuredMinimumPoint(curveDatasets) {
  const points = curveDatasets
    .flatMap(curve => Array.isArray(curve.data) ? curve.data : [])
    .filter(point => Number.isFinite(point.x) && Number.isFinite(point.y));
  if (!points.length) return null;

  return {
    x: Math.min(...points.map(point => point.x)),
    y: Math.min(...points.map(point => point.y)),
  };
}

function stackCrosshairBehindTooltip(canvas, ...overlays) {
  Object.assign(canvas.style, { position: 'relative', zIndex: '1' });
  overlays.forEach(element => { element.style.zIndex = '0'; });
}

function temperatureBounds(curveDatasets) {
  const temperatures = curveDatasets
    .flatMap(ds => (ds.data || []).map(point => point.x))
    .filter(value => Number.isFinite(value));
  const minTemp = temperatures.length ? Math.min(...temperatures) : 0;
  const maxTemp = temperatures.length ? Math.max(...temperatures) : 100;
  const range = Math.max(1, maxTemp - minTemp);

  return {
    min: minTemp - 1,
    max: maxTemp + 1,
    stepSize: range <= 10 ? 1 : range <= 20 ? 2 : 5,
  };
}

function syncCurrentPointDatasets(chart, curveDatasets, readings) {
  const markers = buildCurrentPointDatasets(curveDatasets, readings);
  const bounds = temperatureBounds(curveDatasets);
  chart.data.datasets = [...curveDatasets, ...markers];
  chart.options.scales.x.min = bounds.min;
  chart.options.scales.x.max = bounds.max;
  chart.options.scales.x.ticks.stepSize = bounds.stepSize;
  chart.update('none');
  return markers;
}

function parseRealtimeData(raw) {
  // Missing, unwritten, or placeholder cache file.
  if (!raw || raw === '-' || raw.toUpperCase() === 'N/A') {
    return { noCache: true };
  }

  // Use one normalized raw value for every branch.
  const [tempPart, rpmStr = ''] = raw.split('|');

  // An asterisk means disks are spun down or idle.
  const starMatch = /^\*\s*\((CPU|Disk(?:: [^)]+)?|Aux|Idle)\)/i.exec(tempPart);
  if (starMatch) {
    const origin = starMatch[1]; // CPU / Disk / Idle
    const rpm = /^\d+$/.test(rpmStr) ? parseInt(rpmStr, 10) : null;
    if (rpm === null) return { noCache: true };
    return { temp: null, origin, rpm, spunDown: true };
  }

  // Normal numeric temperature.
  const numMatch = /(\d+)\s*\((CPU|Disk(?:: [^)]+)?|Aux)\)/i.exec(tempPart);
  if (!numMatch) return { noCache: true };

  const temp   = parseInt(numMatch[1], 10);
  const origin = numMatch[2];
  const rpm    = /^\d+$/.test(rpmStr) ? parseInt(rpmStr, 10) : null;

  return { temp, origin, rpm, spunDown: false };
}

function realtimeOriginType(origin) {
  return (origin ?? '').toString().split(':', 1)[0];
}

function findDatasetForOrigin(datasets, origin) {
  const type = realtimeOriginType(origin);
  if (type === 'CPU') return datasets.find(d => d.label && d.label.includes('CPU'));
  if (type === 'Aux') return datasets.find(d => d.label && d.label.includes('Aux'));
  if (type !== 'Disk') return undefined;

  const group = origin.includes(':') ? origin.slice(origin.indexOf(':') + 1).trim() : '';
  if (group) {
    const prefix = `Disk: ${group} Temp`;
    const match = datasets.find(d => d.label && d.label.startsWith(prefix));
    if (match) return match;
  }
  return datasets.find(d => d.label && d.label.includes('Disk:'));
}

window.showFanChart = function (btn) {
  const block = btn.closest('.fan-block');
  if (!block) return;

  const getNum = (selector) => {
    const el = block.querySelector(selector);
    if (!el) return null;
    const val = el.value.replace(/[^\d.]/g, '');
    return val ? parseFloat(val) : null;
  };

  const getSelectVal = (selector) => {
    const el = block.querySelector(selector);
    return el ? el.value : '';
  };

  const custom = block.querySelector('.custom-name-input')?.value || 'Unknown';
  const name = getSelectVal('[name^="custom["]') || '(Unnamed)';
  const pwmMin = getNum('[name^="pwm_percent["]');
  const pwmMax = getNum('[name^="max_percent["]');

  // Each disk group gets its own Temp -> PWM curve (own Low/High range, driven
  // by that group's own hottest selected disk -- see fanctrlplus2_loop.sh).
  const diskGroupPalette = ['#4285f4', '#8e44ad', '#16a085', '#e67e22', '#c0392b', '#2c3e50'];
  const diskGroups = [...block.querySelectorAll('.disk-group-row')].map((row, idx) => {
    const selectEl = row.querySelector('.disk-select');
    const groupIndex = Number(row.dataset.group);
    const lowStr = (row.querySelector('.low-temp-input')?.value ?? '').replace(/[^\d.]/g, '');
    const highStr = (row.querySelector('.high-temp-input')?.value ?? '').replace(/[^\d.]/g, '');
    return {
      name: row.querySelector('.disk-group-name-input')?.value || `Group ${idx + 1}`,
      sourceKey: `disk:${Number.isInteger(groupIndex) ? groupIndex : idx}`,
      selected: !!(selectEl && [...selectEl.selectedOptions].some(opt => opt.value)),
      low: lowStr ? parseFloat(lowStr) : null,
      high: highStr ? parseFloat(highStr) : null,
      color: diskGroupPalette[idx % diskGroupPalette.length],
    };
  });
  const diskSelected = diskGroups.some(g => g.selected);

  const cpuEnabled = getSelectVal('[name^="cpu_enable["]') === '1';
  const cpuLow = getNum('[name^="cpu_min_temp["]');
  const cpuHigh = getNum('[name^="cpu_max_temp["]');
  const auxEnabled = getSelectVal('[name^="aux_enable["]') === '1';
  const auxLow = getNum('[name^="aux_min_temp["]');
  const auxHigh = getNum('[name^="aux_max_temp["]');

  if ([pwmMin, pwmMax].some(v => v === null)) {
    Swal.fire('⚠️ Missing input', 'Please fill in the Fan Speed Range (Min/Max %).', 'warning');
    return;
  }

  // Interpolate curve data points.
  const makeLinePoints = (x1, y1, x2, y2, segments = x2 - x1) => {
  const data = [];
  for (let i = 0; i <= segments; i++) {
      const ratio = i / segments;
      const x = x1 + (x2 - x1) * ratio;
      const y = y1 + (y2 - y1) * ratio;
      data.push({ x, y });
  }
  return data;
  };

  const datasets = [];

  diskGroups.forEach(g => {
    if (!g.selected || g.low === null || g.high === null || g.low >= g.high) return;
    const points = makeLinePoints(g.low, pwmMin, g.high, pwmMax);
    const ds = {
      label: `Disk: ${g.name.replace(/\)/g, ']')} Temp → PWM (%)`,
      sourceKey: g.sourceKey,
      data: points,
      borderColor: g.color,
      backgroundColor: g.color + '1a',
      borderWidth: 2,
      pointRadius: 0,
      pointHoverRadius: 0,
      fill: false,
      tension: 0.4,
    };
    datasets.push(ds);
  });

  if (cpuEnabled && cpuLow !== null && cpuHigh !== null) {
    const cpuPoints = makeLinePoints(cpuLow, pwmMin, cpuHigh, pwmMax);

    datasets.push({
    label: 'CPU Temp → PWM (%)',
    sourceKey: 'cpu',
    data: cpuPoints,
    borderColor: '#db4437',
    backgroundColor: 'rgba(219,68,55,0.1)',
    borderWidth: 2,
    pointRadius: 0,
    pointHoverRadius: 0,
    fill: false,
    tension: 0.4
    });
  }

  if (auxEnabled && auxLow !== null && auxHigh !== null) {
    const auxPoints = makeLinePoints(auxLow, pwmMin, auxHigh, pwmMax);

    datasets.push({
    label: 'Aux Temp → PWM (%)',
    sourceKey: 'aux',
    data: auxPoints,
    borderColor: '#0f9d58',
    backgroundColor: 'rgba(15,157,88,0.1)',
    borderWidth: 2,
    pointRadius: 0,
    pointHoverRadius: 0,
    fill: false,
    tension: 0.4
    });
  }

  // Controller ownership note.
  const activeSources = [];
  if (diskSelected) activeSources.push('Disk');
  if (cpuEnabled) activeSources.push('CPU');
  if (auxEnabled) activeSources.push('Aux');
  let footerNote = '';

  if (activeSources.length === 0) {
    footerNote = '⚠️ No rules defined — fan will not be controlled';
  } else if (activeSources.length === 1) {
    footerNote = `💡 Only ${activeSources[0]} rule applies`;
  } else {
    footerNote = `💡 ${activeSources.join(' and ')} rules are active — Fan PWM = max(${activeSources.join(', ')})`;
  }
    
  Swal.fire({
    title: `📈 ${name}`,
    html: `
      <div id="fan-chart-top" style="margin-top:-12px; margin-bottom:10px; font-size:13px; color:#666; text-align:center;">
        <div id="fan-chart-live-note" style="margin-top:12px; color: #000;"></div>
      </div>
      <div id="fan-chart-wrapper" style="padding:0; position:relative;">
        <canvas id="fan-chart" style="width: 100%; height: auto;"></canvas>
        <div style="margin-top: 8px; font-size: 13px; color: #666; text-align: center;">${footerNote}</div>
      </div>`,

  customClass: 'chart-swal',
  didOpen: () => {
    // Snapshot once so five-second refreshes cannot change the DOM state.
    const customName = custom; // Backend key used under /var.
    const snapCpuEnabled = getSelectVal('[name^="cpu_enable["]') === '1';
    const snapAuxEnabled = getSelectVal('[name^="aux_enable["]') === '1';
    const snapDiskSelected = [...block.querySelectorAll('.disk-group-row .disk-select')]
      .some(sel => [...sel.selectedOptions].some(opt => opt.value));

    // Find the matching dataset, if any.
    // Current value header node.
    const liveNote = document.getElementById('fan-chart-live-note');
    if (liveNote) {
      liveNote.classList.add('chart-current');
    }

    // Return the latest dataset temperature as a percentage.
    function pickPercentNearest(ds, t) {
      if (!ds || !ds.data || !ds.data.length || typeof t !== 'number') return null;
      let best = ds.data[0];
      for (const p of ds.data) if (Math.abs(p.x - t) < Math.abs(best.x - t)) best = p;
      return typeof best.y === 'number' ? best.y : null;
    }
    // Draw the chart with a safe empty-data range and create its crosshair.
    setTimeout(() => {
      const canvas  = document.getElementById('fan-chart');
      const wrapper = document.getElementById('fan-chart-wrapper');
      if (!canvas || !wrapper) return;

      // Use the wrapper as the positioning container.
      if (getComputedStyle(wrapper).position === 'static') {
        wrapper.style.position = 'relative';
      }

      // Use integer pixels to avoid blurring.
      canvas.width  = wrapper.offsetWidth;
      canvas.height = 400;

      const ctx = canvas.getContext('2d');

      const initialBounds = temperatureBounds(datasets);

      // Read theme variables from the modal, with fallbacks.
      const popupEl   = document.querySelector('.swal2-popup.chart-swal');
      const styles    = getComputedStyle(popupEl);
      const gridColor = (styles.getPropertyValue('--fan-grid') || 'rgba(255,255,255,.18)').trim();
      const tickColor = (styles.getPropertyValue('--fan-tick') || 'rgba(255,255,255,.82)').trim();

      // Create the chart.
      const chart = new Chart(ctx, {
        type: 'line',
        data: { datasets },
        options: {
          responsive: false,
          scales: {
            x: {
              type: 'linear',
              title: { display: true, text: 'Temperature (°C)', color: tickColor },
              min: initialBounds.min,
              max: initialBounds.max,
              ticks: { stepSize: initialBounds.stepSize, autoSkip: false, color: tickColor },
              grid:  { color: gridColor }
            },
            y: {
              min: 0,
              max: 100,
              title: { display: true, text: 'Fan Speed (%)', color: tickColor },
              ticks: { stepSize: 10, color: tickColor },
              grid:  { color: gridColor }
            }
          },
          plugins: {
            legend: {
              position: 'bottom',
              labels: {
                usePointStyle: false,
                pointStyle: 'line',
                boxWidth: 30,
                boxHeight: 0,
                filter(item, data) {
                  return !data.datasets[item.datasetIndex].currentReading;
                }
              }
            },
            tooltip: {
              usePointStyle: false,
              pointStyle: 'line',
              boxWidth: 10,
              boxHeight: 0,
              mode: 'nearest',
              intersect: false,
              filter: currentPointTooltipFilter,
              callbacks: {
                title(items) {
                  return currentPointTooltipTitle(items[0]);
                },
                label(ctx) {
                  const percent = ctx.parsed.y;
                  if (ctx.dataset.currentReading) {
                    if (ctx.raw.temperatureUnavailable) {
                      return `${ctx.dataset.currentLabel} curve minimum → Fan Speed = ${percent.toFixed(0)}% (PWM ${ctx.raw.pwm})`;
                    }
                    return `${ctx.dataset.currentLabel} current → Fan Speed = ${percent.toFixed(0)}% (PWM ${ctx.raw.pwm})`;
                  }
                  const label = ctx.dataset.label.includes('Disk') ? 'Disk Temp' : ctx.dataset.label.includes('Aux') ? 'Aux Temp' : 'CPU Temp';
                  const pwm = Math.round(percent * 2.55);
                  return `${label} → Fan Speed = ${percent.toFixed(0)}% (PWM ${pwm})`;
                }
              }
            }
          }
        }
      });

      // Configured-minimum reference lines.
      const vLine = document.createElement('div');
      const hLine = document.createElement('div');
      Object.assign(vLine.style, {
        position: 'absolute', width: '1.2px',
        display: 'none', pointerEvents: 'none'
      });
      vLine.className = 'chart-vline';
      Object.assign(hLine.style, {
        position: 'absolute', height: '1.2px',
        display: 'none', pointerEvents: 'none'
      });
      hLine.className = 'chart-hline';
      stackCrosshairBehindTooltip(canvas, vLine, hLine);
      wrapper.appendChild(vLine);
      wrapper.appendChild(hLine);

      const configuredMinimum = configuredMinimumPoint(datasets);
      if (configuredMinimum) {
        const ca = chart.chartArea;
        let x = chart.scales.x.getPixelForValue(configuredMinimum.x);
        let y = chart.scales.y.getPixelForValue(configuredMinimum.y);
        const wb = wrapper.getBoundingClientRect();
        const cb = canvas.getBoundingClientRect();
        const offsetLeft = cb.left - wb.left;
        const offsetTop  = cb.top  - wb.top;

        x = Math.min(Math.max(x, ca.left), ca.right);
        y = Math.min(Math.max(y, ca.top), ca.bottom);

        Object.assign(vLine.style, {
          left: (offsetLeft + x) + 'px',
          top: (offsetTop + ca.top) + 'px',
          height: (ca.bottom - ca.top) + 'px',
          display: 'block',
        });
        Object.assign(hLine.style, {
          left: (offsetLeft + ca.left) + 'px',
          top: (offsetTop + y) + 'px',
          width: (ca.right - ca.left) + 'px',
          display: 'block',
        });
      }

      // Refresh current values and per-curve points every five seconds.
      async function updateTopNote() {
        const [data, currentReadings] = await Promise.all([
          fetchRealtimeData(customName),
          fetchCurvePoints(customName),
        ]);
        syncCurrentPointDatasets(chart, datasets, currentReadings);
        if (!liveNote) return;

        // A new fan block may not have a cache entry yet.
        if (!data || data.noCache) {
          liveNote.innerHTML = `Current: --<br><span style="color:#999;">
            No runtime data yet. If this is a new fan, click <b>Apply</b> to start the loop, 
            or wait a few seconds after saving.
          </span>`;
          return;
        }  

        const { temp, origin, rpm, spunDown } = data;
        const originType = realtimeOriginType(origin);

        // Calculate the current percentage.
        let percent = null, html = '';
        if (spunDown) {
          if (origin === 'Idle') {
            // For HDD-only control without CPU input, idle means the disks are spun down.
            const suffix = (snapDiskSelected && !snapCpuEnabled)
              ? '(All selected HDDs are spun down — using Idle Speed)'
              : '(No temperature source — using Idle Speed)';

            html = `Current: *°C (Idle) → RPM ${rpm}<br><span style="color:#999;">${suffix}</span>`;
          } else {
            html = `Current: *°C (${origin}) → RPM ${rpm}<br>
                    <span style="color:#999;">(${origin} is spun down — using rule's minimum temperature)</span>`;
          }
        } else {
          const ds = findDatasetForOrigin(datasets, origin);
          const curvePosition = curvePointAtTemperature(ds, temp);
          const currentReading = ds
            ? currentReadings.find(reading => reading.source === ds.sourceKey)
            : null;
          percent = curvePosition?.y ?? pickPercentNearest(ds, temp);
          if (percent != null) {
            const pwm = currentReading?.pwm ?? Math.round(percent * 2.55);
            html = `Current: ${temp}°C (${origin}) → Fan Speed ${percent.toFixed(0)}% (PWM ${pwm}) → RPM ${rpm}`;
          } else {
            html = `Current: ${temp ?? '*'}°C (${origin}) → RPM ${rpm}<br><span style="color:#999;">(${origin} data not shown in chart)</span>`;
          }
        }

        // Determine the synchronization note from the snapshot.
        if (originType === 'CPU' && !snapCpuEnabled) {
          html += '<br><span style="color:#999;">(CPU was disabled, still active until Apply)</span>';
        } else if (originType === 'Disk' && !snapDiskSelected) {
          html += '<br><span style="color:#999;">(Disk was deselected, still active until Apply)</span>';
        } else if (originType === 'Aux' && !snapAuxEnabled) {
          html += '<br><span style="color:#999;">(Aux was disabled, still active until Apply)</span>';
        }

        liveNote.innerHTML = html;
      }

      // Refresh immediately, then every five seconds.
      updateTopNote();
      if (window.__fanChartTimer) clearInterval(window.__fanChartTimer);
      window.__fanChartTimer = setInterval(updateTopNote, 5000);
    }, 10);
  },
  willClose: () => {
    if (window.__fanChartTimer) clearInterval(window.__fanChartTimer);
  }
  });
};
