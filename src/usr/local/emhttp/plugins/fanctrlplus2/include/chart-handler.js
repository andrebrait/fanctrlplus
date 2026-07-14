// chart-handler.js - Show temp→PWM chart for a fan block

async function fetchRealtimeData(custom) {
  const res = await fetch(`/plugins/fanctrlplus2/include/FanctrlLogic.php?op=read_temp_rpm&custom=${encodeURIComponent(custom)}`);
  if (!res.ok) return { noCache: true };

  const raw = (await res.text()).trim();

  // Missing, unwritten, or placeholder cache file.
  if (!raw || raw === '-' || raw.toUpperCase() === 'N/A') {
    return { noCache: true };
  }

  // Use one normalized raw value for every branch.
  const [tempPart, rpmStr = ''] = raw.split('|');

  // An asterisk means disks are spun down or idle.
  const starMatch = /^\*\s*\((CPU|Disk|Aux|Idle)\)/i.exec(tempPart);
  if (starMatch) {
    const origin = starMatch[1]; // CPU / Disk / Idle
    const rpm = /^\d+$/.test(rpmStr) ? parseInt(rpmStr, 10) : null;
    if (rpm === null) return { noCache: true };
    return { temp: null, origin, rpm, spunDown: true };
  }

  // Normal numeric temperature.
  const numMatch = /(\d+)\s*\((CPU|Disk|Aux)\)/i.exec(tempPart);
  if (!numMatch) return { noCache: true };

  const temp   = parseInt(numMatch[1], 10);
  const origin = numMatch[2];
  const rpm    = /^\d+$/.test(rpmStr) ? parseInt(rpmStr, 10) : null;

  return { temp, origin, rpm, spunDown: false };
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
    const lowStr = (row.querySelector('.low-temp-input')?.value ?? '').replace(/[^\d.]/g, '');
    const highStr = (row.querySelector('.high-temp-input')?.value ?? '').replace(/[^\d.]/g, '');
    return {
      name: row.querySelector('.disk-group-name-input')?.value || `Group ${idx + 1}`,
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

  const makePointRadiusArray = (length) => {
    return Array.from({ length }, (_, i) => (i === 0 || i === length - 1) ? 4 : 0);
  };

  const datasets = [];

  diskGroups.forEach(g => {
    if (!g.selected || g.low === null || g.high === null || g.low >= g.high) return;
    const points = makeLinePoints(g.low, pwmMin, g.high, pwmMax);
    const ds = {
      label: `Disk: ${g.name} Temp → PWM (%)`,
      data: points,
      borderColor: g.color,
      backgroundColor: g.color + '1a',
      borderWidth: 2,
      pointRadius: makePointRadiusArray(points.length),
      pointHoverRadius: 6,
      fill: false,
      tension: 0.4,
    };
    datasets.push(ds);
  });

  if (cpuEnabled && cpuLow !== null && cpuHigh !== null) {
    const cpuPoints = makeLinePoints(cpuLow, pwmMin, cpuHigh, pwmMax);
    const cpuRadius = makePointRadiusArray(cpuPoints.length);

    datasets.push({
    label: 'CPU Temp → PWM (%)',
    data: cpuPoints,
    borderColor: '#db4437',
    backgroundColor: 'rgba(219,68,55,0.1)',
    borderWidth: 2,
    pointRadius: cpuRadius,
    pointHoverRadius: 6,
    fill: false,
    tension: 0.4
    });
  }

  if (auxEnabled && auxLow !== null && auxHigh !== null) {
    const auxPoints = makeLinePoints(auxLow, pwmMin, auxHigh, pwmMax);
    const auxRadius = makePointRadiusArray(auxPoints.length);

    datasets.push({
    label: 'Aux Temp → PWM (%)',
    data: auxPoints,
    borderColor: '#0f9d58',
    backgroundColor: 'rgba(15,157,88,0.1)',
    borderWidth: 2,
    pointRadius: auxRadius,
    pointHoverRadius: 6,
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
    const dsCPU  = datasets.find(d => d.label && d.label.includes('CPU'));
    const dsAux  = datasets.find(d => d.label && d.label.includes('Aux'));
    // ponytail: the loop/refresh_single scripts report the live temp's origin
    // only as "(Disk)", not which group -- with 2+ groups the live crosshair
    // can't know which curve actually produced the current reading, so it
    // falls back to the first configured group's curve. Upgrade path: have
    // the backend also report the winning group's name if this needs to be
    // precise per-group.
    const dsDisk = datasets.find(d => d.label && d.label.includes('Disk:'));

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
    // Return the dataset minimum for spun-down disks.
    function pickPercentAtMin(ds) {
      if (!ds || !ds.data || !ds.data.length) return null;
      let minPoint = ds.data[0];
      for (const p of ds.data) if (p.x < minPoint.x) minPoint = p;
      return typeof minPoint.y === 'number' ? minPoint.y : null;
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

      // Aggregate temperatures, with a fallback range for empty datasets.
      const allTemps = datasets
        .flatMap(ds => (ds.data || []).map(p => p.x))
        .filter(x => typeof x === 'number');

      let minTemp, maxTemp;
      if (allTemps.length) {
        minTemp = Math.min(...allTemps);
        maxTemp = Math.max(...allTemps);
      } else {
        minTemp = 0; maxTemp = 100;
      }
      const range = Math.max(1, maxTemp - minTemp);
      const stepSize = range <= 10 ? 1 : range <= 20 ? 2 : 5;

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
              min: minTemp - 1,
              max: maxTemp + 1,
              ticks: { stepSize, autoSkip: false, color: tickColor },
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
              labels: { usePointStyle: false, pointStyle: 'line', boxWidth: 30, boxHeight: 0 }
            },
            tooltip: {
              usePointStyle: false,
              pointStyle: 'line',
              boxWidth: 10,
              boxHeight: 0,
              mode: 'nearest',
              intersect: false,
              callbacks: {
                title(items) { return `${items[0].parsed.x}°C`; },
                label(ctx) {
                  const label = ctx.dataset.label.includes('Disk') ? 'Disk Temp' : ctx.dataset.label.includes('Aux') ? 'Aux Temp' : 'CPU Temp';
                  const percent = ctx.parsed.y;
                  const pwm = Math.round(percent * 2.55);
                  return `${label} → Fan Speed = ${percent.toFixed(0)}% (PWM ${pwm})`;
                }
              }
            }
          }
        }
      });

      // Crosshair elements: vertical line, horizontal line, and point.
      const vLine = document.createElement('div');
      const hLine = document.createElement('div');
      const dot   = document.createElement('div');
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
      Object.assign(dot.style, {
        position: 'absolute', width: '8px', height: '8px', marginLeft: '-4px', marginTop: '-4px',
        borderRadius: '50%', display: 'none', pointerEvents: 'none'
      });
      dot.className = 'chart-dot';
      wrapper.appendChild(vLine);
      wrapper.appendChild(hLine);
      wrapper.appendChild(dot);

      // Refresh the Current value and crosshair every five seconds.
      async function updateTopNote() {
        const data = await fetchRealtimeData(customName);
        if (!liveNote) return;

        // A new fan block may not have a cache entry yet.
        if (!data || data.noCache) {
          liveNote.innerHTML = `Current: --<br><span style="color:#999;">
            No runtime data yet. If this is a new fan, click <b>Apply</b> to start the loop, 
            or wait a few seconds after saving.
          </span>`;
          // Hide the crosshair.
          vLine.style.display = hLine.style.display = dot.style.display = 'none';
          return;
        }  

        const { temp, origin, rpm, spunDown } = data;
        const ori = (origin ?? '').toString();
        const isCPU = /^cpu$/i.test(ori);


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
          vLine.style.display = hLine.style.display = dot.style.display = 'none';
        } else {
          const ds = origin === 'CPU' ? dsCPU : origin === 'Aux' ? dsAux : dsDisk;
          percent = pickPercentNearest(ds, temp);
          if (percent != null) {
            const pwm = Math.round(percent * 2.55);
            html = `Current: ${temp}°C (${origin}) → Fan Speed ${percent.toFixed(0)}% (PWM ${pwm}) → RPM ${rpm}`;

            // Position the crosshair within the chart area.
            const xScale = chart.scales.x;
            const yScale = chart.scales.y;
            const ca = chart.chartArea; // {left, top, right, bottom}

            // Convert chart coordinates to pixels.
            let x = xScale.getPixelForValue(temp);
            let y = yScale.getPixelForValue(percent);

            // Calculate wrapper-relative offsets, including padding.
            const wb = wrapper.getBoundingClientRect();
            const cb = canvas.getBoundingClientRect();
            const offsetLeft = cb.left - wb.left;
            const offsetTop  = cb.top  - wb.top;

            // Clamp the point to the chart area.
            x = Math.min(Math.max(x, ca.left),  ca.right);
            y = Math.min(Math.max(y, ca.top),   ca.bottom);

            // Vertical line at x, spanning the chart height.
            vLine.style.left   = (offsetLeft + x) + 'px';
            vLine.style.top    = (offsetTop  + ca.top) + 'px';
            vLine.style.height = (ca.bottom - ca.top) + 'px';
            vLine.style.display = 'block';

            // Horizontal line at y, spanning the chart width.
            hLine.style.left   = (offsetLeft + ca.left) + 'px';
            hLine.style.top    = (offsetTop  + y) + 'px';
            hLine.style.width  = (ca.right - ca.left) + 'px';
            hLine.style.display = 'block';

            // Center point.
            dot.style.left = (offsetLeft + x) + 'px';
            dot.style.top  = (offsetTop  + y) + 'px';
            dot.style.display = 'block';
          } else {
            // Without a matching curve, hide the crosshair and report RPM only.
            html = `Current: ${temp ?? '*'}°C (${origin}) → RPM ${rpm}<br><span style="color:#999;">(${origin} data not shown in chart)</span>`;
            vLine.style.display = hLine.style.display = dot.style.display = 'none';
          }
        }

        // Determine the synchronization note from the snapshot.
        if (origin === 'CPU' && !snapCpuEnabled) {
          html += '<br><span style="color:#999;">(CPU was disabled, still active until Apply)</span>';
        } else if (origin === 'Disk' && !snapDiskSelected) {
          html += '<br><span style="color:#999;">(Disk was deselected, still active until Apply)</span>';
        } else if (origin === 'Aux' && !snapAuxEnabled) {
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
