<?php
/* Copyright 2012-2023, Bergware International.
 * Copyright 2025, ck9393.
 * Copyright 2026, Andre Brait.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * Dynamix System AutoFan plugin development contribution by gfjardim.
 * Modified for FanCtrl Plus in 2025 by ck9393.
 * Modified for FanCtrl Plus 2 in 2026 by Andre Brait.
 *
 * SPDX-License-Identifier: GPL-2.0-only
 */
ob_start(); // Buffer output so unexpected text cannot corrupt JSON.

$plugin = 'fanctrlplus2';
$cfgpath = "/boot/config/plugins/$plugin";
$rename_map = [];
$used_files = [];
$docroot = $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';

require_once "$docroot/plugins/$plugin/include/Common.php";

if (!is_dir($cfgpath)) {
  mkdir($cfgpath, 0777, true);
}

header('Content-Type: application/json');

// Validate the submitted data structure.
if (!isset($_POST['#file']) || !is_array($_POST['#file'])) {
  ob_clean();
  echo json_encode(['status' => 'error', 'message' => 'No fan config received']);
  exit;
}

foreach ($_POST['#file'] as $i => $file) {
  $old_file = basename($file);
  $controller = $_POST['controller'][$i] ?? '';
  $custom = trim($_POST['custom'][$i] ?? '');
  $interval = $_POST['interval'][$i] ?? '';
  $expected_file = $plugin . '_' . $custom . '.cfg';
  $old_path = "$cfgpath/$old_file";
  $new_path = "$cfgpath/$expected_file";
  
  // Read the raw values first.
  $pwm_percent_raw = $_POST['pwm_percent'][$i] ?? '';
  $max_percent_raw = $_POST['max_percent'][$i] ?? '';

  // Remove non-digits and default empty values to 40% and 100%.
  $pwm_percent = is_numeric($p = preg_replace('/[^0-9]/', '', $pwm_percent_raw)) ? intval($p) : 40;
  $max_percent = is_numeric($m = preg_replace('/[^0-9]/', '', $max_percent_raw)) ? intval($m) : 100;

  $pwm = round($pwm_percent * 255 / 100);
  $max_pwm = round($max_percent * 255 / 100);

  // ===== Disk Groups =====
  // disk_group_{name,disks,low,high}[$i] arrive keyed by the group's DOM index,
  // possibly non-contiguous (a middle group removed client-side). Renumber
  // contiguously 0..N-1 on write -- the loop scripts iterate disk_group_count
  // that way. Also derive the legacy flat disks/low/high (union of all groups'
  // disks; low/high from group 0) so an older plugin version, or anything still
  // reading those keys, sees a sane single-range fallback instead of nothing.
  $group_names_raw = $_POST['disk_group_name'][$i] ?? [];
  $disk_groups_out = [];
  $legacy_disks_union = [];
  $gi = 0;
  foreach ($group_names_raw as $g => $name_raw) {
    $g_disks = isset($_POST['disk_group_disks'][$i][$g]) ? implode(',', $_POST['disk_group_disks'][$i][$g]) : '';
    $g_low_raw = $_POST['disk_group_low'][$i][$g] ?? '';
    $g_high_raw = $_POST['disk_group_high'][$i][$g] ?? '';
    $g_low = is_numeric($l = preg_replace('/[^0-9]/', '', $g_low_raw)) ? intval($l) : 40;
    $g_high = is_numeric($h = preg_replace('/[^0-9]/', '', $g_high_raw)) ? intval($h) : 60;

    $disk_groups_out["disk_group_{$gi}_name"] = trim((string)$name_raw);
    $disk_groups_out["disk_group_{$gi}_disks"] = $g_disks;
    $disk_groups_out["disk_group_{$gi}_low"] = $g_low;
    $disk_groups_out["disk_group_{$gi}_high"] = $g_high;

    if ($g_disks !== '') {
      $legacy_disks_union = array_merge($legacy_disks_union, explode(',', $g_disks));
    }
    $gi++;
  }
  $disk_group_count = $gi;

  $low_temp = $disk_group_count > 0 ? intval($disk_groups_out['disk_group_0_low']) : 40;
  $high_temp = $disk_group_count > 0 ? intval($disk_groups_out['disk_group_0_high']) : 60;
  $legacy_disks = implode(',', array_unique(array_filter($legacy_disks_union)));

  // ===== Fan Speed on Idle (%) → cfg: idle(0..255) =====
  // 1) Read Idle Speed as a percentage, defaulting to 0.
  $idle_percent_raw = $_POST['idle_percent'][$i] ?? '0';
  $idle_percent_val = preg_replace('/[^0-9]/', '', $idle_percent_raw);
  $idle_percent     = ($idle_percent_val !== '' && is_numeric($idle_percent_val)) ? intval($idle_percent_val) : 0;
  $idle_percent     = max(0, min(100, $idle_percent));

  // 2) pwm_percent is the existing Min Speed value.
  if ($idle_percent > $pwm_percent) {
    ob_clean();
    echo json_encode([
      'status'  => 'error',
      'message' => "Idle Speed (%) must be ≤ Min Speed (%). (Block #".($i+1).")",
      'block'   => $i
    ]);
    exit;
  }

  // 3) Convert the percentage to an absolute PWM value based on 255.
  $idle_abs = (int) round($idle_percent * 255 / 100);

  // 4) Clamp to [0, $pwm] as a second validation layer.
  if ($idle_abs > $pwm) $idle_abs = $pwm;
  if ($idle_abs < 0)    $idle_abs = 0;

  // CPU fallback
  $cpu_enable = $_POST['cpu_enable'][$i] ?? '0';
  $cpu_sensor = $_POST['cpu_sensor'][$i] ?? '';

  $cpu_min_raw = $_POST['cpu_min_temp'][$i] ?? '';
  $cpu_max_raw = $_POST['cpu_max_temp'][$i] ?? '';

  if ($cpu_enable === '1') {
    $cpu_min_temp = is_numeric($cmin = preg_replace('/[^0-9]/', '', $cpu_min_raw)) ? intval($cmin) : 40;
    $cpu_max_temp = is_numeric($cmax = preg_replace('/[^0-9]/', '', $cpu_max_raw)) ? intval($cmax) : 70;
  } else {
    $cpu_min_temp = '';
    $cpu_max_temp = '';
  }

  // Aux sensor fallback
  $aux_enable = $_POST['aux_enable'][$i] ?? '0';
  $aux_sensor = isset($_POST['aux_sensor'][$i]) ? implode(',', $_POST['aux_sensor'][$i]) : '';

  $aux_min_raw = $_POST['aux_min_temp'][$i] ?? '';
  $aux_max_raw = $_POST['aux_max_temp'][$i] ?? '';

  if ($aux_enable === '1') {
    $aux_min_temp = is_numeric($amin = preg_replace('/[^0-9]/', '', $aux_min_raw)) ? intval($amin) : 40;
    $aux_max_temp = is_numeric($amax = preg_replace('/[^0-9]/', '', $aux_max_raw)) ? intval($amax) : 70;
  } else {
    $aux_min_temp = '';
    $aux_max_temp = '';
  }

  // Custom Name is required.
  if ($custom === '') {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => "Custom Name is required."]);
    exit;
  }

  // Allow only letters, numbers, and underscores in Custom Name.
  if (!preg_match('/^[A-Za-z0-9_]+$/', $custom)) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => "Custom Name can only contain letters, numbers, and underscores."]);
    exit;
  }

  if (stripos($custom, 'temp_') !== false) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Custom Name cannot contain "temp_".']);
    exit;
  }

  $syslog_val = '1'; // Enabled by default.
  if (file_exists($old_path)) {
    $lines = file($old_path, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $line) {
      if (strpos($line, 'syslog=') === 0) {
        $syslog_val = trim(explode('=', $line, 2)[1], "\"' \t\r\n");
        break;
      }
    }
  }

  // Check whether another configuration uses the same custom name.
  foreach (glob("$cfgpath/{$plugin}_*.cfg") as $existing) {
    $info = parse_ini_file($existing);
    if (isset($info['custom']) && trim($info['custom']) === $custom) {
      // Ignore the current file when renaming a temporary configuration.
      if (basename($existing) !== $old_file) {
        ob_clean();
        echo json_encode(['status' => 'error', 'message' => "Custom Name \"$custom\" is already used."]);
        exit;
      }
    }
  }
  
  // Rename the cfg file when Custom Name changes.
  if ($old_file !== $expected_file) {
      if (file_exists($old_path)) {
          // detect case-only rename
          if (strtolower($old_file) === strtolower($expected_file)) {
              $tmp = $old_path . '.tmp';
              rename($old_path, $tmp);
              rename($tmp, $new_path);
          } else {
              rename($old_path, $new_path);
          }
      }
      require_once "$docroot/plugins/$plugin/include/OrderManager.php";
      OrderManager::replaceFileName($old_file, $expected_file);
      $rename_map[$old_file] = $expected_file;
      $old_file = $expected_file;
      $old_path = $new_path;
  }

  file_put_contents($old_path, "custom=\"$custom\"\n...");

  // Require Interval to be a positive number of seconds.
  if (!ctype_digit($interval) || intval($interval) <= 0) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => "Interval cannot be empty or 0 (recommended: 60–300 sec)."]);
    exit;
  } 

  $interval_seconds = cfg_interval_seconds(['interval_sec' => $interval]);

  // === Rename temporary files to the final custom-name filename ===
  if (strpos($old_file, 'temp_') !== false && !empty($controller)) {
    $new_file = $plugin . "_$custom.cfg";
    $rename_map[$old_file] = $new_file;
  } else {
    $new_file = $old_file;
  }  

  // Avoid filename collisions.
  $basefile = pathinfo($new_file, PATHINFO_FILENAME);
  $suffix = 1;
  while (in_array($new_file, $used_files)) {
    $new_file = $basefile . "_$suffix.cfg";
    $suffix++;
  }

  $used_files[] = $new_file;
  $filepath = "$cfgpath/$new_file";

  // Build the configuration contents.
  $cfg = [
    'custom'     => $custom,
    'label'      => $custom,
    'service'    => $_POST['service'][$i] ?? '0',
    'controller' => $controller,
    'pwm'        => $pwm,
    'max'        => $max_pwm,
    'idle'       => (string)$idle_abs,
    'low'        => $low_temp,
    'high'       => $high_temp,
    // The interval field posts a bare number of seconds; writing it under the
    // seconds key also migrates a config that still held minutes.
    'interval_sec' => (string)$interval_seconds,
    // Kept only so that rolling the plugin back to a version that reads
    // minutes finds a sane value instead of sleeping zero seconds per tick.
    'interval'   => (string)max(1, (int)ceil($interval_seconds / 60)),
    'disks'      => $legacy_disks,
    'disk_group_count' => (string)$disk_group_count,
    'syslog'     => $syslog_val,
    'cpu_enable'    => $cpu_enable,
    'cpu_sensor'    => $cpu_sensor,
    'cpu_min_temp'  => $cpu_min_temp,
    'cpu_max_temp'  => $cpu_max_temp,
    'aux_enable'    => $aux_enable,
    'aux_sensor'    => $aux_sensor,
    'aux_min_temp'  => $aux_min_temp,
    'aux_max_temp'  => $aux_max_temp,
  ] + $disk_groups_out;

  $content = '';
  foreach ($cfg as $k => $v) {
    $v = str_replace(
      ["\\", '"', '$', '`', "\r", "\n"],
      ["\\\\", '\\"', '\\$', '\\`', '', ''],
      (string)$v
    );
    $content .= "$k=\"$v\"\n";
  }

  file_put_contents($filepath, $content, LOCK_EX);

  // Remove the old temporary file.
  if ($old_file !== $new_file && is_file("$cfgpath/$old_file")) {
    @unlink("$cfgpath/$old_file");
  }
}

// Remove cfg files that are no longer used.
foreach (glob("$cfgpath/{$plugin}_*.cfg") as $cfgfile) {
  $base = basename($cfgfile);
  if (!in_array($base, $used_files)) {
    @unlink($cfgfile);
  }
}

// === Write order.cfg through OrderManager.php ===
require_once "$docroot/plugins/fanctrlplus2/include/OrderManager.php";

$order_left = array_map(function($f) use ($rename_map) {
  return $rename_map[$f] ?? $f;
}, $_POST['order_left'] ?? []);

$order_right = array_map(function($f) use ($rename_map) {
  return $rename_map[$f] ?? $f;
}, $_POST['order_right'] ?? []);

OrderManager::writeOrder(array_values($order_left), array_values($order_right));

// Restart the fanctrlplus2 daemon.
$script = "/usr/local/emhttp/plugins/$plugin/scripts/rc.fanctrlplus2";
if (is_file($script)) {
  exec("bash $script stop > /dev/null 2>&1");
  sleep(1);
  exec("bash $script start > /dev/null 2>&1");
}

ob_clean();
echo json_encode(['status' => 'ok']);
exit;
