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
$cfgpath = getenv('FCP_CONFIG_DIR') ?: "/boot/config/plugins/$plugin";
$rename_map = [];
$used_files = [];
$docroot = $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';

require_once "$docroot/plugins/$plugin/include/Common.php";
require_once __DIR__.'/CurveConfig.php';
require_once __DIR__.'/ConfigBackup.php';
// Order writes and renames below target the selected configuration directory, never the boot one.
require_once "$docroot/plugins/$plugin/include/OrderManager.php";
OrderManager::useOrderFile("$cfgpath/order.cfg");

if (!is_dir($cfgpath)) {
  mkdir($cfgpath, 0777, true);
}
try { $config_lock = fcp_config_lock($cfgpath); }
catch (Throwable $error) {
  http_response_code(500);
  ob_clean();
  echo json_encode(['status'=>'error','message'=>$error->getMessage()]);
  exit;
}
register_shutdown_function(function () use ($config_lock) {
  if (is_resource($config_lock)) { flock($config_lock, LOCK_UN); fclose($config_lock); }
});

header('Content-Type: application/json');

// Validate the submitted data structure.
if (!isset($_POST['#file']) || !is_array($_POST['#file'])) {
  ob_clean();
  echo json_encode(['status' => 'error', 'message' => 'No fan config received']);
  exit;
}

// Rejects the whole save. Nothing has been renamed or written when this runs.
$reject = function (string $message, ?int $block = null) {
  ob_clean();
  echo json_encode(['status' => 'error', 'message' => $message] + ($block === null ? [] : ['block' => $block]));
  exit;
};

// Preflight: every block is validated and resolved before the first file is renamed or written,
// so one bad block cannot leave earlier blocks half-applied.
$plans = [];
$percent_pwm = static function (int $percent, string $key, array $previous): int {
  $old = $previous[$key] ?? '';
  if (is_string($old) && ctype_digit($old) && (int)$old <= 255 && (int)round((int)$old * 100 / 255) === $percent) return (int)$old;
  return (int)round($percent * 255 / 100);
};
// Lowercased destination names claimed by earlier blocks in this save; the boot drive ignores case.
$claimed = [];
// Two paths name one file when they share an inode: a case-only rename, or the fan's own file.
$same_file = static fn(string $a, string $b): bool => is_file($a) && is_file($b) && fileinode($a) === fileinode($b) && filedev($a) === filedev($b);
foreach ($_POST['#file'] as $i => $file) {
  $old_file = basename($file);
  $old_path = "$cfgpath/$old_file";
  $previous = is_file($old_path) ? (@parse_ini_file($old_path) ?: []) : [];
  $custom = trim($_POST['custom'][$i] ?? '');

  // Custom Name is required.
  if ($custom === '') $reject('Custom Name is required.');

  // Allow only letters, numbers, and underscores in Custom Name.
  if (!preg_match('/^[A-Za-z0-9_]+$/', $custom)) $reject('Custom Name can only contain letters, numbers, and underscores.');

  if (stripos($custom, 'temp_') !== false) $reject('Custom Name cannot contain "temp_".');

  // Require Interval to be a positive number of seconds.
  $interval = $_POST['interval'][$i] ?? '';
  if (!ctype_digit($interval) || intval($interval) <= 0) $reject("Interval cannot be empty or 0 (recommended: 60–300 sec).");
  $interval_seconds = cfg_interval_seconds(['interval_sec' => $interval]);

  // Read the raw values first.
  $max_percent_raw = $_POST['max_percent'][$i] ?? '';
  if (!is_string($max_percent_raw)) $reject('Normal Cap must be a percentage from 0 to 100.', $i);
  $max_percent_raw = trim($max_percent_raw);
  if (!preg_match('/^(?:0|[1-9]\d{0,2})%?$/D', $max_percent_raw)) $reject('Normal Cap must be a percentage from 0 to 100.', $i);

  $max_percent = (int)rtrim($max_percent_raw, '%');
  if ($max_percent > 100) $reject('Normal Cap must be a percentage from 0 to 100.', $i);
  $max_pwm = $percent_pwm($max_percent, 'max', $previous);

  // Disk groups are keyed by their DOM index, possibly non-contiguous (a middle group removed
  // client-side). Each one's curve rule is keyed by that same index.
  $group_names_raw = is_array($_POST['disk_group_name'][$i] ?? null) ? $_POST['disk_group_name'][$i] : [];
  if (count($group_names_raw) > FCP_CURVE_MAX_GROUPS) $reject('A fan can have at most '.FCP_CURVE_MAX_GROUPS.' disk groups.', $i);
  try {
    $json = is_array($_POST['curve_config'] ?? null) ? ($_POST['curve_config'][$i] ?? null) : null;
    $rules = fcp_curve_post_rules($json, array_keys($group_names_raw));
  } catch (InvalidArgumentException $error) {
    $reject($error->getMessage()." (Block #".($i+1).")", $i);
  }

  // Renumber the disk groups 0..N-1 in posted order; the loop scripts iterate them that way.
  $saved = ['cpu' => $rules['cpu'], 'aux' => $rules['aux']];
  foreach (array_keys($group_names_raw) as $gi => $g) $saved["disk:$gi"] = $rules["disk:$g"];

  // The legacy keys follow the curves: pwm is the lowest first point, low/high the endpoints.
  $legacy = fcp_curve_legacy_fields($saved, ($_POST['cpu_enable'][$i] ?? '0') === '1', ($_POST['aux_enable'][$i] ?? '0') === '1');
  $pwm = (int)$legacy['pwm'];

  // Fan Speed on Idle (%) → cfg: idle(0..255). Idle is refused, never clamped, when it exceeds
  // the lowest curve point or the normal max, so the saved speed is the one the user set.
  $idle_percent_raw = $_POST['idle_percent'][$i] ?? '0';
  $idle_percent_val = preg_replace('/[^0-9]/', '', $idle_percent_raw);
  $idle_percent     = ($idle_percent_val !== '' && is_numeric($idle_percent_val)) ? intval($idle_percent_val) : 0;
  $idle_percent     = max(0, min(100, $idle_percent));
  $idle_abs = $percent_pwm($idle_percent, 'idle', $previous);
  if ($idle_abs > $pwm) {
    $reject("Idle Speed must not exceed a disk curve or an enabled CPU/Aux rule’s first point. (Block #".($i+1).")", $i);
  }
  if ($idle_abs > $max_pwm) {
    $reject("Idle Speed must not exceed the normal cap. (Block #".($i+1).")", $i);
  }

  // Preflight owns every name collision, so a refused save never leaves an earlier block written.
  $expected_file = $plugin.'_'.$custom.'.cfg';
  if (isset($claimed[strtolower($expected_file)])) $reject("Custom Name \"$custom\" is used by more than one fan.", $i);
  $claimed[strtolower($expected_file)] = true;
  foreach (glob("$cfgpath/{$plugin}_*.cfg") as $existing) {
    // The fan's own file is saved or renamed in place, so it never collides with itself.
    if (basename($existing) === $old_file || $same_file($existing, $old_path)) continue;
    if (strtolower(basename($existing)) === strtolower($expected_file)) $reject("Custom Name \"$custom\" would overwrite another saved configuration.", $i);
    $info = parse_ini_file($existing);
    if (isset($info['custom']) && strcasecmp(trim($info['custom']), $custom) === 0) $reject("Custom Name \"$custom\" is already used.", $i);
  }

  $plans[$i] = [
    'custom' => $custom,
    'interval_seconds' => $interval_seconds,
    'max' => $max_pwm,
    'idle' => $idle_abs,
    'saved' => $saved,
    'legacy' => $legacy,
    'previous' => $previous,
  ];
}

// Save every validated block. Preflight has checked every name, so nothing here can refuse a block.
foreach ($_POST['#file'] as $i => $file) {
  $plan = $plans[$i];
  $custom = $plan['custom'];
  $legacy = $plan['legacy'];
  $interval_seconds = $plan['interval_seconds'];
  $old_file = basename($file);
  $controller = $_POST['controller'][$i] ?? '';
  $expected_file = $plugin . '_' . $custom . '.cfg';
  $old_path = "$cfgpath/$old_file";
  $new_path = "$cfgpath/$expected_file";
  // Read before rename/overwrite: an empty runtime controller means the
  // saved binding is unresolved, not that the user selected another device.
  $previous = $plan['previous'];
  $controller_identity = $controller === ''
    ? (string)($previous['controller_identity'] ?? $previous['controller'] ?? '')
    : (fcp_unique_pwm_identity($controller) ?? $controller);

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
      OrderManager::replaceFileName($old_file, $expected_file);
      $rename_map[$old_file] = $expected_file;
      $old_file = $expected_file;
      $old_path = $new_path;
  }

  // === Rename temporary files to the final custom-name filename ===
  if (strpos($old_file, 'temp_') !== false && !empty($controller)) {
    $new_file = $plugin . "_$custom.cfg";
    $rename_map[$old_file] = $new_file;
  } else {
    $new_file = $old_file;
  }

  $used_files[] = $new_file;
  $filepath = "$cfgpath/$new_file";

  // ===== Disk Groups =====
  // Renumbered 0..N-1 on write. The legacy flat disks are the union of all groups' disks.
  $disk_groups_out = [];
  $legacy_disks_union = [];
  $gi = 0;
  $group_names_raw = is_array($_POST['disk_group_name'][$i] ?? null) ? $_POST['disk_group_name'][$i] : [];
  foreach ($group_names_raw as $g => $name_raw) {
    $g_disks = isset($_POST['disk_group_disks'][$i][$g]) ? implode(',', $_POST['disk_group_disks'][$i][$g]) : '';

    $disk_groups_out["disk_group_{$gi}_name"] = trim((string)$name_raw);
    $disk_groups_out["disk_group_{$gi}_disks"] = $g_disks;
    $disk_groups_out["disk_group_{$gi}_low"] = $legacy["disk_group_{$gi}_low"];
    $disk_groups_out["disk_group_{$gi}_high"] = $legacy["disk_group_{$gi}_high"];

    if ($g_disks !== '') {
      $legacy_disks_union = array_merge($legacy_disks_union, explode(',', $g_disks));
    }
    $gi++;
  }
  $disk_group_count = $gi;
  $legacy_disks = implode(',', array_unique(array_filter($legacy_disks_union)));

  // Every source's curve is written, including an inactive CPU or Aux one, so re-enabling it keeps its curve.
  $curve_fields = fcp_curve_fields($plan['saved']['cpu'], 'cpu') + fcp_curve_fields($plan['saved']['aux'], 'aux');
  for ($gi = 0; $gi < $disk_group_count; $gi++) {
    $curve_fields += fcp_curve_fields($plan['saved']["disk:$gi"], "disk_group_$gi");
  }

  // CPU fallback
  $cpu_enable = $_POST['cpu_enable'][$i] ?? '0';
  $cpu_sensor = $_POST['cpu_sensor'][$i] ?? '';

  // Aux sensor fallback
  $aux_enable = $_POST['aux_enable'][$i] ?? '0';
  $aux_sensor = isset($_POST['aux_sensor'][$i]) ? implode(',', $_POST['aux_sensor'][$i]) : '';

  // Build the configuration contents.
  $cfg = [
    'custom'     => $custom,
    'label'      => $custom,
    'service'    => $_POST['service'][$i] ?? '0',
    'controller' => $controller,
    'controller_identity' => $controller_identity,
    'pwm'        => $legacy['pwm'],
    'max'        => $plan['max'],
    'idle'       => (string)$plan['idle'],
    'low'        => $legacy['low'],
    'high'       => $legacy['high'],
    // The interval field posts a bare number of seconds; writing it under the
    // seconds key also migrates a config that still held minutes.
    'interval_sec' => (string)$interval_seconds,
    // Kept only so that rolling the plugin back to a version that reads
    // minutes finds a sane value instead of sleeping zero seconds per tick.
    'interval'   => (string)max(1, (int)ceil($interval_seconds / 60)),
    'disks'      => $legacy_disks,
    'disk_group_count' => (string)$disk_group_count,
    // Per-fan switches set outside this form: keep them, both default on.
    'syslog'     => (string)($previous['syslog'] ?? '1'),
    'notify'     => (string)($previous['notify'] ?? '1'),
    'cpu_enable'    => $cpu_enable,
    'cpu_sensor'    => $cpu_sensor,
    'cpu_min_temp'  => $legacy['cpu_min_temp'],
    'cpu_max_temp'  => $legacy['cpu_max_temp'],
    'aux_enable'    => $aux_enable,
    'aux_sensor'    => $aux_sensor,
    'aux_min_temp'  => $legacy['aux_min_temp'],
    'aux_max_temp'  => $legacy['aux_max_temp'],
  ] + $disk_groups_out + $curve_fields;

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

// The posted order is one list, whose entries may have been renamed in this
// same save.
OrderManager::writeOrder(array_values(array_map(
  fn($f) => $rename_map[$f] ?? $f,
  $_POST['order'] ?? []
)));

// Restart the daemon only from the directory it runs from. FCP_RC_SCRIPT lets tests observe a restart without touching the host.
$script = getenv('FCP_RC_SCRIPT') ?: "/usr/local/emhttp/plugins/$plugin/scripts/rc.fanctrlplus2";
if (fcp_daemon_owns_config($cfgpath) && is_file($script)) {
  exec('bash '.escapeshellarg($script).' stop > /dev/null 2>&1');
  sleep(1);
  exec('bash '.escapeshellarg($script).' start > /dev/null 2>&1');
}

ob_clean();
echo json_encode(['status' => 'ok']);
exit;
