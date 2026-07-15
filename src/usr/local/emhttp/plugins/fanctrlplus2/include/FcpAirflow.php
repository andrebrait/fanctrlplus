<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

/**
 * Read /abs/.../pwmN=Name entries from pwm_labels.cfg.
 * Use realpath(dirname(...)) plus channel N as the key and map it to fanN_input.
 */
function load_labels(): array {
  $cfg = '/boot/config/plugins/fanctrlplus2/pwm_labels.cfg';
  $dirN_to_label = []; // key = realdir.'::'.N => label
  if (!is_file($cfg)) return $dirN_to_label;

  $lines = file($cfg, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;

    $eq = strpos($line, '=');
    if ($eq === false) continue;

    $pwm_path = trim(substr($line, 0, $eq));
    $label    = trim(substr($line, $eq+1));
    if ($label === '') continue;

    if (preg_match('~/pwm(\d+)$~i', $pwm_path, $m)) {
      $n    = (int)$m[1];
      $rdir = realpath(dirname($pwm_path)) ?: dirname($pwm_path);
      $dirN_to_label[$rdir.'::'.$n] = $label;
    }
  }
  return $dirN_to_label;
}

/**
 * Use sensors -A to list fan channel numbers that actually exist.
 * sensors normally reports only fanN entries with a connected tachometer.
 * Keep RPM=0 entries so stopped fans are not hidden by mistake.
 */
function detect_present_channels(): array {
  $present = [];

  // A) Read fanN from sensors in fanN:, FANN..., or Array Fan N: format.
  $lines = [];
  @exec('sensors -A 2>/dev/null', $lines);
  foreach ($lines as $ln) {
    $ln = trim($ln);
    if (preg_match('/^fan\s*([0-9]+)\s*:\s*([0-9]+)\s*RPM/i', $ln, $m))     { $present[(int)$m[1]] = true; continue; }
    if (preg_match('/^FAN\s*([0-9]+)\b.*?\b([0-9]+)\s*RPM/i', $ln, $m))      { $present[(int)$m[1]] = true; continue; }
    if (preg_match('/^Array\s+Fan\s*([0-9]+)\s*:\s*([0-9]+)\s*RPM/i', $ln, $m)) { $present[(int)$m[1]] = true; continue; }
  }

  // B) Fall back to sysfs and include every channel with RPM > 0.
  foreach (glob('/sys/class/hwmon/hwmon*/fan*_input') as $f) {
    if (!preg_match('/fan(\d+)_input$/', basename($f), $mm)) continue;
    $n   = (int)$mm[1];
    $rpm = @file_get_contents($f);
    if (is_numeric($rpm) && (int)$rpm > 0) $present[$n] = true;
  }

  // C) On rare platforms where neither works, list every fan*_input file.
  if (!$present) {
    foreach (glob('/sys/class/hwmon/hwmon*/fan*_input') as $f) {
      if (preg_match('/fan(\d+)_input$/', basename($f), $mm)) $present[(int)$mm[1]] = true;
    }
  }

  ksort($present, SORT_NUMERIC);
  return array_keys($present);  // For example: [1,2,4,5,7].
}

$dirN_to_label = load_labels();
$presentNs     = detect_present_channels();

$fans = [];
foreach ($presentNs as $n) {
  // Find the matching fanN_input across all hwmon directories.
  $match = null;
  foreach (glob('/sys/class/hwmon/hwmon*/fan'.$n.'_input') as $path) {
    if (is_file($path)) { $match = $path; break; }
  }
  if (!$match) continue;

  $rpm_raw = @file_get_contents($match);
  $rpm     = is_numeric($rpm_raw) ? (int)$rpm_raw : 0;

  $rdir = realpath(dirname($match)) ?: dirname($match);
  $key  = $rdir.'::'.$n;
  $name = $dirN_to_label[$key] ?? ('FAN '.$n);

  $fans[] = [
    'name'     => $name,
    'rpm'      => $rpm,
    'rpm_text' => $rpm.' RPM',
    'dom_id'   => 'fcp_fan_'.$n,
    'index'    => $n,
    'realdir'  => $rdir,
  ];
}

// Sort consistently by channel number N.
usort($fans, fn($a,$b) => $a['index'] <=> $b['index']);

// Keep the fan count consistent with the rendered list, including 0 RPM.
echo json_encode([
  'count' => count($fans),
  'fans'  => $fans,
], JSON_UNESCAPED_UNICODE);
