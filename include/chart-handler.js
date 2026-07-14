// chart-handler.js - Show temp→PWM chart for a fan block

async function fetchRealtimeData(custom) {
  const res = await fetch(`/plugins/fanctrlplus/include/FanctrlLogic.php?op=read_temp_rpm&custom=${encodeURIComponent(custom)}`);
  if (!res.ok) return { noCache: true };

  const raw = (await res.text()).trim();

  // 文件不存在 / 还没写 / 占位符
  if (!raw || raw === '-' || raw.toUpperCase() === 'N/A') {
    return { noCache: true };
  }

  // 统一用同一份 raw
  const [tempPart, rpmStr = ''] = raw.split('|');

  // 1) 星号：磁盘休眠 / Idle
  const starMatch = /^\*\s*\((CPU|Disk|Aux|Idle)\)/i.exec(tempPart);
  if (starMatch) {
    const origin = starMatch[1]; // CPU / Disk / Idle
    const rpm = /^\d+$/.test(rpmStr) ? parseInt(rpmStr, 10) : null;
    if (rpm === null) return { noCache: true };
    return { temp: null, origin, rpm, spunDown: true };
  }

  // 2) 正常数字温度
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
  // by that group's own hottest selected disk -- see fanctrlplus_loop.sh).
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

  // 插值生成曲线数据点
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

  // 控制权注解说明文字
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
    // 1) 只取一次的快照（避免 5s 刷新时 DOM 状态抖动）
    const customName = custom; // 供后端取 /var 的 key
    const snapCpuEnabled = getSelectVal('[name^="cpu_enable["]') === '1';
    const snapAuxEnabled = getSelectVal('[name^="aux_enable["]') === '1';
    const snapDiskSelected = [...block.querySelectorAll('.disk-group-row .disk-select')]
      .some(sel => [...sel.selectedOptions].some(opt => opt.value));

    // 找到对应的 dataset（有可能没有）
    const dsCPU  = datasets.find(d => d.label && d.label.includes('CPU'));
    const dsAux  = datasets.find(d => d.label && d.label.includes('Aux'));
    // ponytail: the loop/refresh_single scripts report the live temp's origin
    // only as "(Disk)", not which group -- with 2+ groups the live crosshair
    // can't know which curve actually produced the current reading, so it
    // falls back to the first configured group's curve. Upgrade path: have
    // the backend also report the winning group's name if this needs to be
    // precise per-group.
    const dsDisk = datasets.find(d => d.label && d.label.includes('Disk:'));

    // 顶部 Current 文本节点
    const liveNote = document.getElementById('fan-chart-live-note');
    if (liveNote) {
      liveNote.classList.add('chart-current'); // 给 current 文本加类名
    }

    // 工具：取 dataset 最近温度的百分比
    function pickPercentNearest(ds, t) {
      if (!ds || !ds.data || !ds.data.length || typeof t !== 'number') return null;
      let best = ds.data[0];
      for (const p of ds.data) if (Math.abs(p.x - t) < Math.abs(best.x - t)) best = p;
      return typeof best.y === 'number' ? best.y : null;
    }
    // 工具：取 dataset 最低温度点（给 spun down 用）
    function pickPercentAtMin(ds) {
      if (!ds || !ds.data || !ds.data.length) return null;
      let minPoint = ds.data[0];
      for (const p of ds.data) if (p.x < minPoint.x) minPoint = p;
      return typeof minPoint.y === 'number' ? minPoint.y : null;
    }

    // 2) 画图（含空数据时的安全范围）+ 创建十字线元素
    setTimeout(() => {
      const canvas  = document.getElementById('fan-chart');
      const wrapper = document.getElementById('fan-chart-wrapper');
      if (!canvas || !wrapper) return;

      // 让 wrapper 成为定位容器
      if (getComputedStyle(wrapper).position === 'static') {
        wrapper.style.position = 'relative';
      }

      // 固定像素，避免模糊
      canvas.width  = wrapper.offsetWidth;
      canvas.height = 400;

      const ctx = canvas.getContext('2d');

      // 汇总所有温度点；如 datasets 为空，用一个保底范围
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

      // 从弹窗读取主题变量（没有就用兜底值）
      const popupEl   = document.querySelector('.swal2-popup.chart-swal');
      const styles    = getComputedStyle(popupEl);
      const gridColor = (styles.getPropertyValue('--fan-grid') || 'rgba(255,255,255,.18)').trim();
      const tickColor = (styles.getPropertyValue('--fan-tick') || 'rgba(255,255,255,.82)').trim();

      // 创建图表
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

      // 十字线元素（竖线、横线、点）
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

      // 3) 顶部 Current + 十字线（每 5 秒）
      async function updateTopNote() {
        const data = await fetchRealtimeData(customName);
        if (!liveNote) return;

        // ✅ 新增：完全读不到缓存 → 新 fan block 的大概率场景
        if (!data || data.noCache) {
          liveNote.innerHTML = `Current: --<br><span style="color:#999;">
            No runtime data yet. If this is a new fan, click <b>Apply</b> to start the loop, 
            or wait a few seconds after saving.
          </span>`;
          // 隐藏十字线
          vLine.style.display = hLine.style.display = dot.style.display = 'none';
          return;
        }  

        const { temp, origin, rpm, spunDown } = data;
        const ori = (origin ?? '').toString();
        const isCPU = /^cpu$/i.test(ori);


        // 算当前百分比
        let percent = null, html = '';
        if (spunDown) {
          if (origin === 'Idle') {
            // Idle：无温度源；若本 block 只选 HDD 且 CPU 未启用，补充“磁盘已休眠”的语义
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

            // 定位十字线（限制在图表绘图区）
            const xScale = chart.scales.x;
            const yScale = chart.scales.y;
            const ca = chart.chartArea; // {left, top, right, bottom}

            // 转成像素
            let x = xScale.getPixelForValue(temp);
            let y = yScale.getPixelForValue(percent);

            // 计算相对 wrapper 的偏移（更稳，包含 padding）
            const wb = wrapper.getBoundingClientRect();
            const cb = canvas.getBoundingClientRect();
            const offsetLeft = cb.left - wb.left;
            const offsetTop  = cb.top  - wb.top;

            // 夹到绘图区内，防止越界
            x = Math.min(Math.max(x, ca.left),  ca.right);
            y = Math.min(Math.max(y, ca.top),   ca.bottom);

            // 竖线：贴在 x，长度 = 绘图区高度
            vLine.style.left   = (offsetLeft + x) + 'px';
            vLine.style.top    = (offsetTop  + ca.top) + 'px';
            vLine.style.height = (ca.bottom - ca.top) + 'px';
            vLine.style.display = 'block';

            // 横线：贴在 y，长度 = 绘图区宽度
            hLine.style.left   = (offsetLeft + ca.left) + 'px';
            hLine.style.top    = (offsetTop  + y) + 'px';
            hLine.style.width  = (ca.right - ca.left) + 'px';
            hLine.style.display = 'block';

            // 中点
            dot.style.left = (offsetLeft + x) + 'px';
            dot.style.top  = (offsetTop  + y) + 'px';
            dot.style.display = 'block';
          } else {
            // 没对应曲线：隐藏十字线，只报 RPM
            html = `Current: ${temp ?? '*'}°C (${origin}) → RPM ${rpm}<br><span style="color:#999;">(${origin} data not shown in chart)</span>`;
            vLine.style.display = hLine.style.display = dot.style.display = 'none';
          }
        }

        // 同步/未同步的小提示（用快照判断）
        if (origin === 'CPU' && !snapCpuEnabled) {
          html += '<br><span style="color:#999;">(CPU was disabled, still active until Apply)</span>';
        } else if (origin === 'Disk' && !snapDiskSelected) {
          html += '<br><span style="color:#999;">(Disk was deselected, still active until Apply)</span>';
        } else if (origin === 'Aux' && !snapAuxEnabled) {
          html += '<br><span style="color:#999;">(Aux was disabled, still active until Apply)</span>';
        }

        liveNote.innerHTML = html;
      }

      // 第一次立即刷新 + 每 5 秒刷新
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
