// Curve points use raw PWM so opening or saving an existing curve never rounds it.
(function () {
  'use strict';
  const clone = value => JSON.parse(JSON.stringify(value));
  const percent = pwm => Math.round(pwm * 1000 / 255) / 10;
  const raw = value => Math.round(Number(value) * 255 / 100);
  const numeric = element => Number((element?.value ?? '').replace(/[^\d.]/g, ''));
  function inputPwm(input) {
    const displayed = numeric(input), original = Number(input?.dataset.pwm);
    return Number.isInteger(original) && original >= 0 && original <= 255 && Math.round(original * 100 / 255) === displayed ? original : raw(displayed);
  }

  function validate(rule) {
    if (!rule || !Array.isArray(rule.points) || rule.points.length < 2 || rule.points.length > 8) return 'Use 2–8 curve points.';
    let previous = [-1, -1];
    for (const point of rule.points) {
      if (!Array.isArray(point) || point.length !== 2 || !point.every(Number.isInteger)
          || point[0] < 0 || point[0] > 300 || point[1] < 0 || point[1] > 255) return 'Points need whole temperatures from 0–300°C and speeds from 0–100%.';
      if (point[0] <= previous[0]) return 'Each point needs a higher temperature than the previous point.';
      if (point[1] < previous[1]) return 'Fan speed must not decrease as temperature rises.';
      previous = point;
    }
    if (!['linear', 'stepped'].includes(rule.mode)) return 'Choose linear or stepped control.';
    if (!Number.isInteger(rule.hysteresis) || rule.hysteresis < 0 || rule.hysteresis > 20) return 'Cooling hysteresis must be a whole number from 0–20°C.';
    if (!Number.isInteger(rule.emergency_pwm) || rule.emergency_pwm < 0 || rule.emergency_pwm > 255) return 'Emergency speed must be from 0–100%.';
    if (rule.emergency_temp !== null) {
      if (!Number.isInteger(rule.emergency_temp) || rule.emergency_temp < 0 || rule.emergency_temp > 300) return 'Emergency temperature must be a whole number from 0–300°C.';
      if (rule.emergency_pwm < previous[1]) return 'Emergency speed must be at least the last curve point’s speed.';
    }
    return null;
  }

  function evaluate(rule, temp, cap) {
    if (rule.emergency_temp !== null && temp >= rule.emergency_temp) return rule.emergency_pwm;
    const points = rule.points;
    let pwm = points[0][1];
    for (let i = 1; i < points.length; i++) {
      const [t, p] = points[i], [low, base] = points[i - 1];
      if (temp < t) {
        if (temp > low && rule.mode === 'linear') pwm = base + Math.floor((temp - low) * (p - base) / (t - low));
        break;
      }
      pwm = p;
    }
    return Math.min(cap, pwm);
  }

  function bounds(rule, padding = 5) {
    const first = rule.points[0][0], last = rule.points.at(-1)[0];
    const emergency = Number.isFinite(rule.emergency_temp) ? rule.emergency_temp : first;
    return { min: Math.max(0, Math.min(first, emergency) - padding), max: Math.min(300, Math.max(last, emergency) + padding) };
  }

  function floor(block, value) {
    let minimum = 255;
    for (const [source, rule] of Object.entries(value)) {
      if ((source === 'cpu' || source === 'aux') && block.querySelector(`[name^="${source}_enable["]`)?.value !== '1') continue;
      minimum = Math.min(minimum, rule.points[0][1]);
    }
    return minimum;
  }

  function rules(block) {
    const field = block.querySelector('.fcp-curve-config');
    const stored = JSON.parse(field.value);
    if (!stored || typeof stored !== 'object') throw new Error('Restore a valid configuration backup before applying settings.');
    const result = { cpu: stored.cpu, aux: stored.aux };
    const min = floor(block, stored);
    const max = inputPwm(block.querySelector('[name^="max_percent["]'));
    block.querySelectorAll('.disk-group-row').forEach(row => {
      const key = `disk:${row.dataset.group}`;
      result[key] = stored[key] || {
        points: [[40, min], [60, Math.max(min, max)]],
        mode: 'linear', hysteresis: 0, emergency_temp: null, emergency_pwm: 255,
      };
    });
    return result;
  }

  function sync(block, value) {
    block.querySelector('.fcp-curve-config').value = JSON.stringify(value);
  }

  function refresh(block) {
    let value;
    try { value = rules(block); }
    catch (_) { return; } // The renderer shows the saved-configuration error.
    block.querySelectorAll('.fcp-curve-summary').forEach(summary => {
      const rule = value[summary.dataset.source];
      if (rule) summary.textContent = `${rule.points.length} points · ${rule.mode === 'linear' ? 'Linear' : 'Stepped'}`;
    });
  }

  window.FcpCurves = { validate, evaluate, rules, sync, refresh, inputPwm, floor, bounds };
  window.openFanCurveEditor = async function (button, selected = 'disk:0') {
    const block = button.closest('.fan-block');
    let draft;
    try { draft = clone(rules(block)); }
    catch (_) { await Swal.fire('Cannot edit curves', 'The saved curve settings could not be read. Restore a valid configuration backup.', 'error'); return; }
    let key = draft[selected] ? selected : Object.keys(draft)[0];
    let chart, dragIndex = null, moved = false;
    const capInput = block.querySelector('[name^="max_percent["]');
    const capAtOpen = numeric(capInput);
    const html = `<div class="fcp-curve-editor">
      <p>Each temperature rule has its own curve. The highest requested speed wins.</p>
      <div class="fcp-curve-settings">
        <label>Temperature rule<select data-ce="source"></select></label>
        <label>Control mode<select data-ce="mode"><option value="linear">Linear</option><option value="stepped">Stepped</option></select></label>
        <label>Cooling hysteresis (°C)<input data-ce="hysteresis" type="number" min="0" max="20" step="1"></label>
      </div>
      <div class="fcp-curve-workspace">
        <div class="fcp-curve-chart"><canvas data-ce="chart" aria-label="Fan curve. Edit exact values in the point table." role="img"></canvas>
          <p class="fcp-curve-help">Drag a point or click empty chart space to add one. The table provides exact values and keyboard editing. Outside the first and last point, speed stays flat.</p>
        </div>
        <div><h3>Curve points <span data-ce="count"></span></h3>
          <table class="fcp-curve-points"><thead><tr><th scope="col">°C</th><th scope="col">PWM %</th><th scope="col">Remove</th></tr></thead><tbody data-ce="points"></tbody></table>
          <button type="button" data-ce="add">+ Add point</button><p class="fcp-curve-help">2–8 points. Speed can rise or stay flat.</p>
        </div>
      </div>
      <div class="fcp-curve-limits">
        <div><h3>Normal operation · all rules</h3><label>Speed cap (%)<input data-ce="cap" type="number" min="0" max="100" step="1"></label><p class="fcp-curve-help">Clips normal output without changing stored points.</p></div>
        <div><h3>Emergency override · selected rule</h3>
          <label class="fcp-curve-toggle"><input data-ce="emergency" type="checkbox"> Enable emergency override</label>
          <div class="fcp-curve-settings"><label>At temperature (°C)<input data-ce="emergency_temp" type="number" min="0" max="300" step="1"></label><label>Fan speed (%)<input data-ce="emergency_pwm" type="number" min="0" max="100" step="0.1"></label></div>
          <p class="fcp-curve-help">Bypasses the normal cap on the next sensor check. Hysteresis applies on cooling, never delays a speed increase, and releases emergency below its threshold by the configured number of degrees.</p>
        </div>
      </div><p data-ce="error" role="status" aria-live="polite"></p>
      <p class="fcp-curve-help">Use changes returns edits to the fan block. Apply All saves them and updates fan control. Cancel discards this editor’s changes.</p>
    </div>`;
    const result = await Swal.fire({
      titleText: `Fan curves · ${block.querySelector('.custom-name-input').value || 'New fan'}`,
      html, width: 'min(1100px, 96vw)', customClass: { popup: 'fcp-curve-modal' },
      showCancelButton: true, confirmButtonText: 'Use changes', cancelButtonText: 'Cancel',
      allowOutsideClick: false, focusConfirm: false,
      didOpen: popup => {
        const get = name => popup.querySelector(`[data-ce="${name}"]`);
        const error = message => { get('error').textContent = message || ''; };
        const current = () => draft[key];
        const source = get('source');
        for (const sourceKey of Object.keys(draft)) {
          const option = document.createElement('option'); option.value = sourceKey;
          if (sourceKey.startsWith('disk:')) {
            const row = block.querySelector(`.disk-group-row[data-group="${sourceKey.split(':')[1]}"]`);
            option.textContent = `Disk: ${row?.querySelector('.disk-group-name-input').value || 'Group ' + (Number(sourceKey.split(':')[1]) + 1)}`;
          } else option.textContent = sourceKey === 'cpu' ? 'CPU' : 'Aux';
          source.append(option);
        }
        source.value = key; get('cap').value = capAtOpen;
        chart = new Chart(get('chart'), {
          type: 'line', data: { datasets: [] }, options: {
            responsive: true, maintainAspectRatio: false, animation: false,
            scales: { x: { type: 'linear', min: 0, max: 100, title: { display: true, text: 'Temperature (°C)' } }, y: { min: 0, max: 100, title: { display: true, text: 'PWM (%)' } } },
            plugins: { tooltip: { callbacks: { label: item => `${item.dataset.label}: ${item.parsed.y.toFixed(1)}%` } } },
          },
        });
        function draw() {
          const rule = current(), pts = rule.points;
          const { min: low, max: high } = bounds(rule);
          chart.options.scales.x.min = low; chart.options.scales.x.max = high;
          const color = getComputedStyle(popup).getPropertyValue('--fan-tick').trim() || getComputedStyle(popup).color;
          for (const axis of Object.values(chart.options.scales)) {
            axis.ticks.color = color; axis.title.color = color; axis.grid.color = 'rgba(127,127,127,.3)';
          }
          chart.options.plugins.legend.labels.color = color;
          const data = [[low, pts[0][1]], ...pts, [high, pts.at(-1)[1]]].map(([x, pwm]) => ({ x, y: percent(pwm) }));
          chart.data.datasets = [
            { label: 'Stored curve', data, borderColor: '#4285f4', backgroundColor: '#4285f4', pointRadius: data.map((_, i) => i > 0 && i < data.length - 1 ? 6 : 0), tension: 0, stepped: rule.mode === 'stepped' ? 'before' : false },
            // The hardware cap may lie between whole displayed percentages.
            { label: 'Normal cap', data: [low, high].map(x => ({ x, y: percent(inputPwm({ value: get('cap').value, dataset: capInput.dataset })) })), borderColor: color, borderDash: [6, 5], pointRadius: 0 },
          ];
          if (rule.emergency_temp !== null) chart.data.datasets.push({ label: 'Emergency threshold', data: [{ x: rule.emergency_temp, y: 0 }, { x: rule.emergency_temp, y: 100 }], borderColor: '#e99b36', borderDash: [4, 4], pointRadius: 0 });
          chart.update('none'); get('count').textContent = `${pts.length} / 8`; get('add').disabled = pts.length >= 8;
        }
        function table() {
          get('points').replaceChildren();
          current().points.forEach((point, i) => {
            const row = document.createElement('tr');
            point.forEach((value, axis) => {
              const cell = document.createElement('td'), input = document.createElement('input');
              input.type = 'number'; input.min = 0; input.max = axis ? 100 : 300; input.step = axis ? '0.1' : '1'; input.value = axis ? percent(value) : value;
              input.setAttribute('aria-label', `Point ${i + 1} ${axis ? 'PWM percent' : 'temperature Celsius'}`);
              input.onchange = () => {
                const previous = point[axis], n = input.value === '' ? NaN : Number(input.value);
                point[axis] = axis ? raw(n) : n;
                const message = n < 0 || n > (axis ? 100 : 300) ? 'Enter a value within the field’s range.' : validate(current());
                if (message) { point[axis] = previous; input.value = axis ? percent(previous) : previous; error(message); }
                else { error(); draw(); }
              };
              cell.append(input); row.append(cell);
            });
            const cell = document.createElement('td'), remove = document.createElement('button');
            remove.type = 'button'; remove.textContent = '×'; remove.setAttribute('aria-label', `Remove point ${i + 1}`); remove.disabled = current().points.length <= 2;
            remove.onclick = () => { current().points.splice(i, 1); table(); draw(); error(); get('add').focus(); };
            cell.append(remove); row.append(cell); get('points').append(row);
          });
        }
        function showRule() {
          const rule = current();
          get('mode').value = rule.mode; get('hysteresis').value = rule.hysteresis;
          get('emergency').checked = rule.emergency_temp !== null;
          get('emergency_temp').value = rule.emergency_temp ?? rule.points.at(-1)[0];
          get('emergency_pwm').value = percent(rule.emergency_pwm);
          get('emergency_temp').disabled = get('emergency_pwm').disabled = rule.emergency_temp === null;
          table(); draw(); error();
        }
        source.onchange = () => { const message = validate(current()); if (message) { source.value = key; error(message); return; } key = source.value; showRule(); };
        get('mode').onchange = () => { current().mode = get('mode').value; draw(); };
        get('hysteresis').oninput = () => { current().hysteresis = get('hysteresis').value === '' ? NaN : Number(get('hysteresis').value); error(validate(current())); };
        const emergencyChanged = () => {
          const rule = current(), enabled = get('emergency').checked;
          get('emergency_temp').disabled = get('emergency_pwm').disabled = !enabled;
          rule.emergency_temp = enabled ? (get('emergency_temp').value === '' ? NaN : Number(get('emergency_temp').value)) : null;
          rule.emergency_pwm = get('emergency_pwm').value === '' ? NaN : raw(get('emergency_pwm').value);
          error(validate(rule)); draw();
        };
        get('emergency').onchange = emergencyChanged; get('emergency_temp').oninput = emergencyChanged; get('emergency_pwm').oninput = emergencyChanged;
        get('cap').oninput = draw;
        function insert(t, pwm) {
          const points = current().points;
          if (points.length >= 8) { error('Eight-point limit reached. Remove a point first.'); return; }
          if (points.some(point => point[0] === t)) { error('Each point needs a different temperature.'); return; }
          let index = points.findIndex(point => point[0] > t); if (index < 0) index = points.length;
          pwm = Math.max(points[index - 1]?.[1] ?? 0, Math.min(points[index]?.[1] ?? 255, pwm));
          points.splice(index, 0, [t, pwm]); table(); draw(); error();
          get('points').rows[index].querySelector('input').focus();
        }
        get('add').onclick = () => {
          const points = current().points;
          for (let i = 1; i < points.length; i++) if (points[i][0] - points[i - 1][0] > 1) {
            insert(Math.floor((points[i][0] + points[i - 1][0]) / 2), Math.floor((points[i][1] + points[i - 1][1]) / 2)); return;
          }
          if (points.at(-1)[0] < 300) insert(points.at(-1)[0] + 1, points.at(-1)[1]);
          else if (points[0][0] > 0) insert(points[0][0] - 1, points[0][1]);
        };
        const canvas = get('chart');
        function coordinates(event) {
          const rect = canvas.getBoundingClientRect();
          return { x: (event.clientX - rect.left) * chart.width / rect.width, y: (event.clientY - rect.top) * chart.height / rect.height };
        }
        canvas.onpointerdown = event => {
          const pos = coordinates(event), points = current().points;
          dragIndex = points.findIndex(([t, pwm]) => Math.hypot(chart.scales.x.getPixelForValue(t) - pos.x, chart.scales.y.getPixelForValue(percent(pwm)) - pos.y) < 16);
          moved = false; canvas.setPointerCapture(event.pointerId);
        };
        canvas.onpointermove = event => {
          if (dragIndex === null || dragIndex < 0) return;
          moved = true;
          const pos = coordinates(event), points = current().points, i = dragIndex;
          const t = Math.round(chart.scales.x.getValueForPixel(pos.x)), pwm = raw(chart.scales.y.getValueForPixel(pos.y));
          points[i] = [
            Math.max((points[i - 1]?.[0] ?? -1) + 1, Math.min((points[i + 1]?.[0] ?? 301) - 1, t)),
            Math.max(points[i - 1]?.[1] ?? 0, Math.min(points[i + 1]?.[1] ?? 255, pwm)),
          ];
          table(); draw(); error();
        };
        canvas.onpointerup = event => {
          const wasPoint = dragIndex !== null && dragIndex >= 0; dragIndex = null;
          if (moved || wasPoint) return;
          const pos = coordinates(event), t = Math.round(chart.scales.x.getValueForPixel(pos.x)), pwm = raw(chart.scales.y.getValueForPixel(pos.y));
          if (pos.x >= chart.chartArea.left && pos.x <= chart.chartArea.right && pos.y >= chart.chartArea.top && pos.y <= chart.chartArea.bottom && t >= 0 && t <= 300) insert(t, pwm);
        };
        canvas.onpointercancel = () => { dragIndex = null; };
        showRule(); source.focus();
      },
      preConfirm: () => {
        const popup = Swal.getPopup();
        if (document.activeElement?.matches('.fcp-curve-points input')) document.activeElement.dispatchEvent(new Event('change'));
        const cap = Number(popup.querySelector('[data-ce="cap"]').value);
        let message = Object.values(draft).map(validate).find(Boolean);
        if ([...popup.querySelectorAll('input:not(:disabled)')].some(input => !input.checkValidity())) message = 'Correct the highlighted field before using these changes.';
        if (!Number.isInteger(cap) || cap < 0 || cap > 100 || popup.querySelector('[data-ce="cap"]').value === '') message = 'Normal cap must be a whole percentage from 0–100.';
        const idle = inputPwm(block.querySelector('[name^="idle_percent["]'));
        const capPwm = inputPwm({ value: String(cap), dataset: capInput.dataset });
        if (idle > Math.min(capPwm, floor(block, draft))) message = 'Idle speed must not exceed the normal cap or the first point of a disk curve or enabled CPU/Aux rule.';
        if (message) { Swal.showValidationMessage(message); return false; }
        return { rules: draft, cap };
      },
      willClose: () => chart?.destroy(),
    });
    if (result.isConfirmed) {
      sync(block, result.value.rules); capInput.value = `${result.value.cap}%`;
      refresh(block); block.querySelector('.fcp-curve-config').dispatchEvent(new Event('change', { bubbles: true }));
    }
  };
})();
