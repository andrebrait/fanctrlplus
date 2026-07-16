// history-chart.js - Dashboard fan-speed history charts (one Chart.js line
// chart per fan; the line is color-coded by the source driving the PWM).

// Same source colors as the fan-curve chart (chart-handler.js): disk groups
// by group index, CPU red, Aux green; Idle (no valid source) is neutral grey.
const FCP_HISTORY_DISK_PALETTE = ['#4285f4', '#8e44ad', '#16a085', '#e67e22', '#c0392b', '#2c3e50'];
const FCP_HISTORY_SOURCE_COLORS = { cpu: '#db4437', aux: '#0f9d58', idle: '#9e9e9e' };
const FCP_HISTORY_WINDOW_KEY = 'fcp2_history_window_minutes';
const FCP_HISTORY_WINDOW_DEFAULT = 60;

function historySourceColor(src) {
  const key = (src ?? '').toString();
  if (FCP_HISTORY_SOURCE_COLORS[key]) return FCP_HISTORY_SOURCE_COLORS[key];
  const m = /^disk:(\d+)$/.exec(key);
  if (m) return FCP_HISTORY_DISK_PALETTE[Number(m[1]) % FCP_HISTORY_DISK_PALETTE.length];
  return FCP_HISTORY_SOURCE_COLORS.idle;
}

// read_history points -> chart points inside the window, oldest first.
function historyChartPoints(points, windowMinutes, nowMs) {
  const cutoff = nowMs - windowMinutes * 60000;
  return (Array.isArray(points) ? points : [])
    .filter(p => p && Number.isFinite(Number(p.t)) && Number.isFinite(Number(p.pwm)))
    .map(p => ({
      x: Number(p.t) * 1000,
      y: Number(p.pwm) / 255 * 100,
      pwm: Number(p.pwm),
      temp: p.temp == null || p.temp === '' ? null : Number(p.temp),
      src: (p.src ?? '').toString(),
      label: (p.label ?? '').toString(),
    }))
    .filter(p => p.x >= cutoff && p.x <= nowMs)
    .sort((a, b) => a.x - b.x);
}

// Same style as the fan-curve chart tooltips:
// "Disk: SSDs at 43°C → Fan Speed = 50% (PWM 127)".
function historyTooltipLabel(point) {
  const speed = `Fan Speed = ${Math.round(point.y)}% (PWM ${point.pwm})`;
  if (point.src === 'idle' || point.temp === null) {
    return `${point.label || 'Idle'} → ${speed}`;
  }
  return `${point.label} at ${point.temp}°C → ${speed}`;
}

function historyTooltipTitle(pointMs) {
  const d = new Date(pointMs);
  const pad = n => String(n).padStart(2, '0');
  return `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

function historyTickLabel(valueMs) {
  const d = new Date(valueMs);
  const pad = n => String(n).padStart(2, '0');
  return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function historyWindowMinutes(storage) {
  const raw = Number(storage?.getItem?.(FCP_HISTORY_WINDOW_KEY));
  return [30, 60, 120, 240].includes(raw) ? raw : FCP_HISTORY_WINDOW_DEFAULT;
}

/* exported by the Dashboard page */
function fcpInitHistoryWidget(fans) {
  const windowSelect = document.getElementById('fcp-history-window');
  let windowMinutes = historyWindowMinutes(window.localStorage);
  if (windowSelect) {
    windowSelect.value = String(windowMinutes);
    windowSelect.addEventListener('change', () => {
      windowMinutes = Number(windowSelect.value) || FCP_HISTORY_WINDOW_DEFAULT;
      try { window.localStorage.setItem(FCP_HISTORY_WINDOW_KEY, String(windowMinutes)); } catch (_e) {}
      charts.forEach(c => c.refresh());
    });
  }

  const tickColor = getComputedStyle(document.body).color || '#999';
  const gridColor = 'rgba(128,128,128,.25)';

  const charts = fans.map(fan => {
    const canvas = document.getElementById(fan.canvasId);
    if (!canvas || typeof Chart === 'undefined') return null;

    // Mutable holder keeps the segment/point color callbacks valid across
    // refreshes without relying on undocumented context fields.
    const holder = { points: [] };
    const chart = new Chart(canvas.getContext('2d'), {
      type: 'line',
      data: {
        datasets: [{
          label: fan.custom,
          data: holder.points,
          borderWidth: 2,
          pointRadius: 2,
          pointHitRadius: 6,
          pointHoverRadius: 5,
          fill: false,
          tension: 0,
          pointBackgroundColor: ctx => historySourceColor(ctx.raw?.src),
          pointBorderColor: ctx => historySourceColor(ctx.raw?.src),
          // The fan held the PWM set at p0 until the next measurement, so a
          // segment wears the color of the source that was driving at p0.
          segment: {
            borderColor: seg => historySourceColor(holder.points[seg.p0DataIndex]?.src),
          },
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        scales: {
          x: {
            type: 'linear',
            min: Date.now() - windowMinutes * 60000,
            max: Date.now(),
            ticks: { maxTicksLimit: 7, color: tickColor, callback: historyTickLabel },
            grid: { color: gridColor },
          },
          y: {
            min: 0,
            max: 100,
            ticks: { stepSize: 25, color: tickColor, callback: v => `${v}%` },
            grid: { color: gridColor },
          },
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            mode: 'nearest',
            intersect: false,
            displayColors: false,
            callbacks: {
              title: items => historyTooltipTitle(items[0].parsed.x),
              label: ctx => historyTooltipLabel(ctx.raw),
            },
          },
        },
      },
    });

    async function refresh() {
      let raw = [];
      try {
        const res = await fetch(
          `/plugins/fanctrlplus2/include/FanctrlLogic.php?op=read_history&custom=${encodeURIComponent(fan.custom)}`,
          { cache: 'no-store' }
        );
        if (res.ok) raw = await res.json();
      } catch (_e) { /* keep the previous points on a failed poll */ }

      const nowMs = Date.now();
      const pts = historyChartPoints(raw, windowMinutes, nowMs);
      holder.points = pts;
      chart.data.datasets[0].data = pts;
      chart.options.scales.x.min = nowMs - windowMinutes * 60000;
      chart.options.scales.x.max = nowMs;
      chart.update('none');
    }

    refresh();
    return { refresh };
  }).filter(Boolean);

  // The loop measures once per interval (minutes); refreshing every minute
  // picks each new point up as it lands and scrolls the window along.
  setInterval(() => charts.forEach(c => c.refresh()), 60000);
}

// Node test hook (browser pages call fcpInitHistoryWidget directly).
if (typeof module !== 'undefined' && module.exports) {
  module.exports = {
    historySourceColor,
    historyChartPoints,
    historyTooltipLabel,
    historyTooltipTitle,
    historyTickLabel,
    historyWindowMinutes,
  };
}
