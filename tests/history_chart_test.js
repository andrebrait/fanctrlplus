const {
  historySourceColor,
  historyChartPoints,
  historyTooltipLabel,
  historyTooltipTitle,
  historyTickLabel,
  historyWindowMinutes,
} = require(`${__dirname}/../src/usr/local/emhttp/plugins/fanctrlplus2/include/history-chart.js`);

const failures = [];
function expectEqual(expected, actual, message) {
  const e = JSON.stringify(expected);
  const a = JSON.stringify(actual);
  if (e !== a) failures.push(`${message}\nExpected: ${e}\nActual: ${a}`);
}

// ===== Source colors match the fan-curve chart (chart-handler.js) =====
expectEqual('#db4437', historySourceColor('cpu'), 'CPU keeps the curve-chart red.');
expectEqual('#0f9d58', historySourceColor('aux'), 'Aux keeps the curve-chart green.');
expectEqual('#9e9e9e', historySourceColor('idle'), 'Idle is neutral grey.');
expectEqual('#4285f4', historySourceColor('disk:0'), 'Group 0 keeps the curve-chart palette.');
expectEqual('#2c3e50', historySourceColor('disk:5'), 'Group 5 keeps the curve-chart palette.');
expectEqual('#4285f4', historySourceColor('disk:6'), 'Group indexes wrap around the palette.');
expectEqual('#9e9e9e', historySourceColor('bogus'), 'Unknown sources fall back to grey.');
expectEqual('#9e9e9e', historySourceColor(undefined), 'Missing sources fall back to grey.');

// ===== Window filtering / mapping =====
const nowMs = 1730000000 * 1000;
const raw = [
  { t: 1730000000 - 3700, src: 'cpu', label: 'CPU', temp: 50, pwm: 140 },      // outside 60 min
  { t: 1730000000 - 120, src: 'disk:1', label: 'Disk: SSDs', temp: 43, pwm: 127 },
  { t: 1730000000 - 60, src: 'idle', label: 'Idle', temp: null, pwm: 51 },
  { t: 1730000000 - 600, src: 'disk:0', label: 'Disk: HDDs', temp: 31, pwm: 138 }, // out of order
  { t: 'garbage', src: 'cpu', label: 'CPU', temp: 1, pwm: 1 },                 // malformed
];
const pts = historyChartPoints(raw, 60, nowMs);
expectEqual(3, pts.length, 'Points outside the window or malformed are dropped.');
expectEqual(['disk:0', 'disk:1', 'idle'], pts.map(p => p.src), 'Points are sorted oldest first.');
expectEqual((1730000000 - 120) * 1000, pts[1].x, 'x is the epoch in milliseconds.');
expectEqual(127, pts[1].pwm, 'The raw PWM rides along for the tooltip.');
expectEqual(Math.round(127 / 255 * 1000) / 10, Math.round(pts[1].y * 10) / 10, 'y is the PWM as a percentage.');
expectEqual(null, pts[2].temp, 'Idle points keep a null temperature.');

const all = historyChartPoints(raw, 240, nowMs);
expectEqual(4, all.length, 'A wider window keeps older points.');

// ===== Tooltip text (same style as the fan-curve chart) =====
expectEqual(
  'Disk: SSDs at 43°C → Fan Speed = 50% (PWM 127)',
  historyTooltipLabel(pts[1]),
  'Driving source, temperature, percent, and PWM appear in the tooltip.'
);
expectEqual(
  'Idle → Fan Speed = 20% (PWM 51)',
  historyTooltipLabel(pts[2]),
  'Idle points show no temperature.'
);

// ===== Time labels =====
const stamp = new Date(2026, 6, 16, 9, 5, 7).getTime();
expectEqual('09:05:07', historyTooltipTitle(stamp), 'Tooltip titles are HH:MM:SS.');
expectEqual('09:05', historyTickLabel(stamp), 'Axis ticks are HH:MM.');

// ===== Window preference =====
const store = value => ({ getItem: () => value });
expectEqual(60, historyWindowMinutes(store(null)), 'Default window is 60 minutes.');
expectEqual(120, historyWindowMinutes(store('120')), 'A stored valid window is honored.');
expectEqual(60, historyWindowMinutes(store('45')), 'A stored invalid window falls back to the default.');
expectEqual(60, historyWindowMinutes(undefined), 'Missing storage falls back to the default.');

if (failures.length) {
  console.error(failures.join('\n'));
  process.exit(1);
}
console.log('history chart tests passed');
