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
error_reporting(E_ALL);
ini_set('display_errors', 1);

$plugin  = 'fanctrlplus2';
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
$cfg_dir = "/boot/config/plugins/$plugin";
$order_file = "$cfg_dir/order.cfg";
$label_file = "$cfg_dir/pwm_labels.cfg";

require_once "$docroot/plugins/$plugin/include/Common.php";
require_once "/usr/local/emhttp/plugins/fanctrlplus2/include/OrderManager.php";
require_once __DIR__.'/ConfigBackup.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

$op = $_GET['op'] ?? $_POST['op'] ?? '';

// Serialize restore/export with every HTTP writer of these configuration files.
if (in_array($op, ['savelabel','newtemp','delete','setsyslog','saveorder','start','stop','fcp_airflow_toggle','fcp_history_toggle'], true)) {
  try { $config_lock = fcp_config_lock($cfg_dir); }
  catch (Throwable $error) { http_response_code(500); json_response(['status'=>'error','message'=>$error->getMessage()]); }
  register_shutdown_function(function () use ($config_lock) {
    if (is_resource($config_lock)) { flock($config_lock, LOCK_UN); fclose($config_lock); }
  });
}

if ($op === 'refresh_single' && !empty($_GET['custom'])) {
  $custom = escapeshellarg($_GET['custom']);
  shell_exec("/usr/local/emhttp/plugins/fanctrlplus2/scripts/fanctrlplus2_refresh_single.sh $custom > /dev/null 2>&1 &");
  exit('OK');
}

function json_response($data) {
  while (ob_get_level()) {
    ob_end_clean(); // Clear all output buffers so notices cannot corrupt the response.
  }
  header('Content-Type: application/json');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function scan_dir($dir) {
  $out = [];
  foreach (array_diff(scandir($dir), ['.','..']) as $f) {
    $out[] = realpath($dir) . '/' . $f;
  }
  return $out;
}

$op = $_GET['op'] ?? $_POST['op'] ?? '';

switch ($op) {

  case 'exportconfig':
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
      header('Allow: GET'); http_response_code(405);
      json_response(['status'=>'error','message'=>'Use GET to export a configuration.']);
    }
    try {
      $backup = fcp_export_config($cfg_dir);
      header('Content-Disposition: attachment; filename="fanctrlplus2-config-'.date('Ymd-His').'.json"');
      echo $backup;
      exit;
    } catch (Throwable $error) {
      http_response_code(500); json_response(['status'=>'error','message'=>$error->getMessage()]);
    }
    break;

  case 'importconfig':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      header('Allow: POST'); http_response_code(405);
      json_response(['status'=>'error','message'=>'Use POST to restore a configuration.']);
    }
    if (!isset($_POST['backup']) || !is_string($_POST['backup'])) {
      http_response_code(400); json_response(['status'=>'error','message'=>'A backup JSON document is required.']);
    }
    $service = function (string $action): string {
      $output = []; $status = 0;
      exec('bash /etc/rc.d/rc.fanctrlplus2 '.escapeshellarg($action).' 2>/dev/null', $output, $status);
      if ($status !== 0) throw new RuntimeException('Fan control service is unavailable.');
      return trim(implode("\n", $output));
    };
    $was_running = false; $had_stop_marker = false;
    try {
      $files = fcp_restore_config($cfg_dir, $_POST['backup'],
        function () use ($service, &$was_running, &$had_stop_marker) {
          $had_stop_marker = is_file('/var/run/fanctrlplus2.user_stopped');
          $state = $service('status');
          if (!in_array($state, ['running','stopped'], true)) throw new RuntimeException('Could not determine fan control status.');
          $was_running = $state === 'running';
          if ($was_running) $service('stop');
          // Pause automatic starts only for the duration of the replacement.
          elseif (@file_put_contents('/var/run/fanctrlplus2.user_stopped', '') === false) throw new RuntimeException('Could not retain the stopped state.');
        },
        function () use ($service, &$was_running, &$had_stop_marker) {
          if ($was_running) $service('start');
          elseif (!$had_stop_marker && is_file('/var/run/fanctrlplus2.user_stopped') && !@unlink('/var/run/fanctrlplus2.user_stopped')) throw new RuntimeException('Could not restore automatic startup state.');
        }
      );
      json_response(['status'=>'ok','message'=>'Configuration restored','files'=>$files]);
    } catch (InvalidArgumentException $error) {
      http_response_code(400); json_response(['status'=>'error','message'=>$error->getMessage()]);
    } catch (Throwable $error) {
      http_response_code(500); json_response(['status'=>'error','message'=>$error->getMessage()]);
    }
    break;
    
  case 'identify':
    $pwm  = $_GET['pwm']  ?? '';
    $mode = $_GET['mode'] ?? 'pause';  // Pause is the default mode.
    if (is_file($pwm)) {
      $original_pwm  = trim(@file_get_contents($pwm));
      $pwm_enable    = $pwm . "_enable";
      $original_mode = is_file($pwm_enable) ? trim(@file_get_contents($pwm_enable)) : '2';

      // Force manual control mode.
      @file_put_contents($pwm_enable, "1");

      if ($mode === 'pause') {
        // Stop immediately.
        @file_put_contents($pwm, "0");
        $restore_cmd = "sleep 30 && echo " . escapeshellarg($original_mode) . " > " . escapeshellarg($pwm_enable) .
                      " && echo " . escapeshellarg($original_pwm) . " > " . escapeshellarg($pwm);

      } elseif ($mode === 'max') {
        // Run at full speed.
        @file_put_contents($pwm, "255");
        $restore_cmd = "sleep 30 && echo " . escapeshellarg($original_mode) . " > " . escapeshellarg($pwm_enable) .
                      " && echo " . escapeshellarg($original_pwm) . " > " . escapeshellarg($pwm);

      } elseif ($mode === 'pulse') {
        // Stop for 10s, run at full speed for 10s, then repeat once.
        $restore_cmd = 
          "echo 0   > " . escapeshellarg($pwm) . " && " .
          "sleep 10 && echo 255 > " . escapeshellarg($pwm) . " && " .
          "sleep 10 && echo 0   > " . escapeshellarg($pwm) . " && " .
          "sleep 10 && echo 255 > " . escapeshellarg($pwm) . " && " .
          "sleep 10 && echo " . escapeshellarg($original_mode) . " > " . escapeshellarg($pwm_enable) .
                      " && echo " . escapeshellarg($original_pwm) . " > " . escapeshellarg($pwm);

      } else {
        json_response(['status' => 'error', 'message' => 'Unknown identify mode']);
        break;
      }

      exec("nohup bash -c \"$restore_cmd\" >/dev/null 2>&1 &");
      json_response(['status' => 'ok', 'message' => "Fan identify ($mode) started"]);

    } else {
      json_response(['status' => 'error', 'message' => 'Invalid PWM path']);
    }
    break;

  case 'savelabel':
    $pwm = $_POST['pwm'] ?? '';
    $label = $_POST['label'] ?? '';

    if (!$pwm) {
      json_response(['status' => 'error', 'message' => 'Missing pwm']);
      break;
    }
    $identity = fcp_pwm_identity($pwm);
    $key = fcp_unique_pwm_identity($pwm) ?? $pwm;
    $lines = is_file($label_file) ? file($label_file, FILE_IGNORE_NEW_LINES) : [];
    // Remove both the legacy path and stable key before saving one entry.
    $lines = array_values(array_filter($lines, function ($line) use ($key, $pwm, $identity, $label) {
      return strpos($line, "$key=") !== 0 && strpos($line, "$pwm=") !== 0
        && ($label !== '' || $identity === null || strpos($line, "$identity=") !== 0);
    }));
    if ($label !== '') $lines[] = "$key=$label";
    file_put_contents($label_file, implode("\n", $lines) . "\n");
    json_response(['status' => 'ok', 'message' => $label === '' ? 'Label removed' : 'Label saved']);
    break;
  
  case 'newtemp':
    $cfg_dir = "/boot/config/plugins/$plugin";

    // Find an unused temp_X.cfg filename.
    $index_cfg = 0;
    while (file_exists("$cfg_dir/{$plugin}_temp_$index_cfg.cfg")) {
      $index_cfg++;
    }

    $temp_file = "$cfg_dir/{$plugin}_temp_$index_cfg.cfg";
    file_put_contents($temp_file, <<<INI
    custom=""
    service="1"
    controller=""
    pwm="102"
    max="255"
    idle="0"
    low="40"
    high="60"
    interval_sec="120"
    disks=""
    syslog="1"
    cpu_enable="0"
    cpu_sensor=""
    cpu_min_temp=""
    cpu_max_temp=""
    aux_enable="0"
    aux_sensor=""
    aux_min_temp=""
    aux_max_temp=""
    INI
    );

    require_once "$docroot/plugins/$plugin/include/FanBlockRender.php";
    $cfg = parse_ini_file($temp_file);
    $cfg['file'] = basename($temp_file);

    // The page index determines the <input name="x[INDEX]"> value.
    $page_index = intval($_REQUEST['index'] ?? 99);
    $pwms = list_pwm();
    $disks = list_valid_disks_by_id();
    $cpu_sensors = detect_cpu_sensors();
    $aux_sensors = detect_aux_sensors();

    header('Content-Type: text/html; charset=utf-8');
    echo render_fan_block($cfg, $page_index, $pwms, $disks, $pwm_labels, $cpu_sensors, $aux_sensors);
    exit;

  case 'newdiskgroup':
    require_once "$docroot/plugins/$plugin/include/FanBlockRender.php";
    $fan_index = intval($_REQUEST['fan_index'] ?? 0);
    $group_index = intval($_REQUEST['group_index'] ?? 0);
    $disks = list_valid_disks_by_id();
    $empty_group = ['name' => '', 'disks' => [], 'low' => 40, 'high' => 60];

    header('Content-Type: text/html; charset=utf-8');
    echo render_disk_group_row($fan_index, $group_index, $empty_group, $disks);
    exit;

  case 'setsyslog':
      $cfg_file = basename($_POST['cfg']);
      $enabled = isset($_POST['enabled']) && $_POST['enabled'] == 1 ? 1 : 0;

      $cfg_dir = "/boot/config/plugins/fanctrlplus2";
      $cfg_path = "$cfg_dir/$cfg_file";

      if (file_exists($cfg_path)) {
          $lines = file($cfg_path, FILE_IGNORE_NEW_LINES);
          $found = false;
          foreach ($lines as &$line) {
              if (strpos($line, 'syslog=') === 0) {
                  $line = 'syslog="' . $enabled . '"';
                  $found = true;
              }
          }
          if (!$found) {
              $lines[] = 'syslog="' . $enabled . '"';
          }
          file_put_contents($cfg_path, implode("\n", $lines) . "\n");
          echo json_encode(['status' => 'ok']);
      } else {
          echo json_encode(['status' => 'error', 'msg' => 'Config file not found']);
      }
      exit;

  case 'delete':
    $file = basename($_POST['file'] ?? '');
    $cfgpath = "/boot/config/plugins/$plugin/$file";

    if (is_file($cfgpath)) {
      unlink($cfgpath);
    }

    OrderManager::remove($file);

    json_response(['status' => 'ok', 'message' => "Deleted $file"]);
    break;

  case 'status':
    $pid_files = glob("/var/run/fanctrlplus2_*.pid");
    $running = false;
    foreach ($pid_files as $pidfile) {
      $pid = trim(@file_get_contents($pidfile));
      if (is_numeric($pid) && posix_kill((int)$pid, 0)) {
        $running = true;
        break;
      }
    }
  
    json_response(['status' => $running ? 'running' : 'stopped']);
    break;

  case 'status_all':
    $cfg_dir = "/boot/config/plugins/$plugin";
    $result = [];

    foreach (glob("$cfg_dir/{$plugin}_*.cfg") as $file) {
      $cfg = parse_ini_file($file);
      $name = trim($cfg['custom'] ?? '');
      $enabled = trim($cfg['service'] ?? '0') === '1';

      // Match rc.fanctrlplus2 when converting custom names to PID filenames.
      $name_trimmed = trim($name);
      $custom_safe = preg_replace('/\W+/', '_', $name_trimmed);
      $pid_file = "/var/run/{$plugin}_{$custom_safe}.pid";
      $running = false;

      if ($enabled && file_exists($pid_file)) {
        $pid = trim(@file_get_contents($pid_file));
        if (is_numeric($pid) && posix_kill((int)$pid, 0)) {
          $running = true;
        }
      }

      if ($name !== '') {
        $result[basename($file)] = $running ? 'running' : 'stopped';
      }
    }
  
    json_response($result);
    break;

  case 'saveorder':
    error_log("[fanctrlplus2] 🔥 saveorder triggered");

    $order_raw = $_POST['order'] ?? [];

    if (!is_array($order_raw)) {
      error_log("[fanctrlplus2] ⚠️ order is not array: " . print_r($order_raw, true));
      json_response(['status' => 'error', 'message' => 'Order not array']);
    }

    // One list, in the order the blocks appear on screen.
    $order = array_values(array_filter($order_raw, function ($f) use ($cfg_dir) {
      return is_string($f) && trim($f) !== '' && is_file("$cfg_dir/$f");
    }));

    if ($order) {
      require_once "$docroot/plugins/$plugin/include/OrderManager.php";
      OrderManager::writeOrder($order);
      json_response(['status' => 'ok']);
    } else {
      error_log("[fanctrlplus2] ❌ Blocked invalid saveorder: " . print_r($order_raw, true));
      json_response(['status' => 'error', 'message' => 'Invalid order']);
    }
    break;
    
  case 'start':
    shell_exec("/etc/rc.d/rc.fanctrlplus2 start");
    json_response(['status' => 'started']);
    break;
  
  case 'stop':
    shell_exec("/etc/rc.d/rc.fanctrlplus2 stop");
    json_response(['status' => 'stopped']);
    break;

  case 'getpwm':
    $pwms = list_pwm();
    $label_file = "/boot/config/plugins/fanctrlplus2/pwm_labels.cfg";
    $labels = fcp_load_pwm_labels($label_file);
    foreach ($pwms as &$pwm) {
      $pwm['label'] = $labels[$pwm['sensor']] ?? '';
    }
    json_response($pwms);
    break;

  case 'asset_version':
    json_response(['version' => fcp_ui_asset_version()]);
    break;

  case 'read_temp_rpm':
    $custom = $_GET['custom'] ?? '';
    $custom = basename($custom); // Strip unsafe path components.

    $plugin = 'fanctrlplus2';
    $temp_file = "/var/tmp/{$plugin}/temp_{$plugin}_{$custom}";
    $rpm_file  = "/var/tmp/{$plugin}/rpm_{$plugin}_{$custom}";

    $temp = is_file($temp_file) ? trim(file_get_contents($temp_file)) : '*';
    $rpm  = is_file($rpm_file)  ? trim(file_get_contents($rpm_file))  : '?';

    echo "$temp|$rpm";  // Example: "48 (CPU)|1150"
    exit;

  case 'read_history':
    $custom = basename($_GET['custom'] ?? ''); // Strip unsafe path components.
    $history_file = "/var/tmp/fanctrlplus2/history_fanctrlplus2_{$custom}";
    $points = [];

    if (is_file($history_file)) {
      foreach (file($history_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $point = fcp_parse_history_line($line);
        if ($point !== null) $points[] = $point;
      }
    }

    json_response($points);
    break;

  case 'read_curve_points':
    $custom = basename($_GET['custom'] ?? ''); // Strip unsafe path components.
    $curve_file = "/var/tmp/fanctrlplus2/curves_fanctrlplus2_{$custom}";
    $points = [];

    if (is_file($curve_file)) {
      foreach (file($curve_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $parts = explode('|', $line, 3);
        if (count($parts) !== 3) continue;

        [$source, $temp, $pwm] = $parts;
        if (!preg_match('/^(?:cpu|aux|disk:\d+)$/', $source)) continue;
        if (!ctype_digit($temp) || !ctype_digit($pwm)) continue;

        $points[] = [
          'source' => $source,
          'temp' => intval($temp),
          'pwm' => intval($pwm),
        ];
      }
    }

    json_response($points);
    break;

  case 'fcp_airflow_toggle':
  case 'fcp_history_toggle':

      $cfg_dir     = "/boot/config/plugins/fanctrlplus2";
      $labels_file = $cfg_dir.'/pwm_labels.cfg';
      $flag        = $op === 'fcp_history_toggle' ? '__FCP_HISTORY__' : '__FCP_AIRFLOW__';

      $enabled = (($_POST['enabled'] ?? '0') === '1');
      $lines   = is_file($labels_file) ? file($labels_file, FILE_IGNORE_NEW_LINES) : [];
      $found   = false;

      foreach ($lines as &$ln) {
          $t = trim($ln);
          if ($t === '' || $t[0] === '#') continue;
          if (preg_match('/^'.preg_quote($flag, '/').'\s*=/', $t)) {
              $ln = $flag . "=" . ($enabled ? '1' : '0');
              $found = true;
              break;
          }
      }
      unset($ln);

      if (!$found) $lines[] = $flag . "=" . ($enabled ? '1' : '0');

      @mkdir($cfg_dir, 0777, true);
      file_put_contents($labels_file, implode("\n", $lines) . "\n");

      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok'=>1, 'enabled'=>$enabled ? 1 : 0]);
      exit;
}
?>
