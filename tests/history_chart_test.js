const {
  historySourceColor,
  historyChartPoints,
  historyTooltipLabel,
  historyTooltipTitle,
  historyTickLabel,
  historyWindowMinutes,
  historyRefreshSeconds,
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
// A short window packs its ticks less than a minute apart, so HH:MM would
// print the same label several times over.
expectEqual('09:05:07', historyTickLabel(stamp, 5), 'A 5 minute window needs seconds on the axis.');
expectEqual('09:05:07', historyTickLabel(stamp, 10), 'A 10 minute window needs seconds on the axis.');
expectEqual('09:05', historyTickLabel(stamp, 15), 'From 15 minutes up the ticks are a minute or more apart.');
expectEqual('09:05', historyTickLabel(stamp, 240), 'A wide window keeps HH:MM.');

// ===== Window preference =====
const store = value => ({ getItem: () => value });
expectEqual(60, historyWindowMinutes(store(null)), 'Default window is 60 minutes.');
expectEqual(120, historyWindowMinutes(store('120')), 'A stored valid window is honored.');
// Sub-30-minute windows are where per-tick detail is visible at a short
// interval: 5 minutes at a 5 second interval is 60 points.
expectEqual(5, historyWindowMinutes(store('5')), 'The shortest offered window is honored.');
expectEqual(10, historyWindowMinutes(store('10')), 'A 10 minute window is honored.');
expectEqual(15, historyWindowMinutes(store('15')), 'A 15 minute window is honored.');
expectEqual(60, historyWindowMinutes(store('45')), 'A stored invalid window falls back to the default.');
expectEqual(60, historyWindowMinutes(undefined), 'Missing storage falls back to the default.');

// ===== Refresh preference =====
// The tile polls once a minute unless the user asks for a faster chart, which
// is what a sub-minute fan interval needs to be visible as it happens.
expectEqual(60, historyRefreshSeconds(store(null)), 'Default refresh stays at 60 seconds.');
expectEqual(5, historyRefreshSeconds(store('5')), 'The fastest offered refresh is honored.');
expectEqual(15, historyRefreshSeconds(store('15')), 'A stored valid refresh is honored.');
expectEqual(60, historyRefreshSeconds(store('1')), 'A refresh below the offered range falls back to the default.');
expectEqual(60, historyRefreshSeconds(store('3600')), 'A refresh outside the offered range falls back to the default.');
expectEqual(60, historyRefreshSeconds(store('abc')), 'A non-numeric refresh falls back to the default.');
expectEqual(60, historyRefreshSeconds(undefined), 'Missing storage falls back to the default refresh.');

// ===== The tile offers the refresh choices and re-arms its timer =====
const fs = require('fs');
const sourceRoot = process.env.FCP_SOURCE_ROOT
  || `${__dirname}/../src/usr/local/emhttp/plugins/fanctrlplus2`;
const historyPage = fs.readFileSync(`${sourceRoot}/FanctrlPlusHistory2.Dashboard.page`, 'utf8');
const historyJs = fs.readFileSync(`${sourceRoot}/include/history-chart.js`, 'utf8');

expectEqual(true, /id="fcp-history-refresh"/.test(historyPage),
  'The tile header must carry the refresh selector.');
[5, 10, 15, 30, 60, 120, 240].forEach(minutes => {
  expectEqual(true, new RegExp(`<option value="${minutes}">`).test(historyPage),
    `The window selector must offer ${minutes} minutes.`);
});
// Chart.js hands its tick callback (value, index, ticks), so the window has to
// be closed over rather than left to arrive as the second argument.
expectEqual(false, /callback: historyTickLabel\b/.test(historyJs),
  'The tick callback must not take Chart.js\' index as the window.');
expectEqual(true, /historyTickLabel\(\w+, windowMinutes\)/.test(historyJs),
  'The tick callback must pass the selected window.');
[5, 10, 15, 30, 60].forEach(seconds => {
  expectEqual(true, new RegExp(`<option value="${seconds}"`).test(historyPage),
    `The refresh selector must offer ${seconds}s.`);
});
expectEqual(true, /clearInterval/.test(historyJs),
  'Changing the refresh rate must replace the running timer, not stack another one.');
expectEqual(false, /setInterval\([^)]*, 60000\)/.test(historyJs),
  'The poll rate must come from the preference, not a hardcoded minute.');

if (failures.length) {
  console.error(failures.join('\n'));
  process.exit(1);
}
console.log('history chart tests passed');
