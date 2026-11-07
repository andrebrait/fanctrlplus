<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

$plugin = "fanctrlplus2";
$cfg_path = "/boot/config/plugins/$plugin";
$tmp_path = "/var/tmp/$plugin";

$daemon_running = count(glob("/var/run/{$plugin}_*.pid")) > 0;
$status_text = $daemon_running ? "Running" : "Stopped";

$fans = [];

if (isset($_GET['op']) && $_GET['op'] === 'refresh' && is_string($_GET['custom'] ?? null) && $_GET['custom'] !== '') {
    require_once __DIR__.'/include/ManualRun.php';
    $run = fcp_manual_run($_GET['custom']);
    if (!$run['ok']) http_response_code(500);
    echo json_encode($run['ok'] ? ['ok' => 1] : ['ok' => 0, 'message' => $run['message'] !== '' ? $run['message'] : 'Manual Run failed.']);
    exit;
}

foreach (glob("$cfg_path/{$plugin}_*.cfg") as $file) {
  $cfg = parse_ini_file($file);
  $custom = $cfg['custom'] ?? basename($file, '.cfg');
  $label = $custom;
  $enabled = ($cfg['service'] ?? '0') === '1';

  // Initialize display fields.
  $temp_val    = "*";
  $temp_origin = "";
  $rpm         = "-";
  $rpm_val     = "-";
  $pct_val     = "-";
  $status      = '<span class="red-text">Inactive</span>';

  if ($enabled && $daemon_running) {
      $temp_raw = trim(@file_get_contents("$tmp_path/temp_{$plugin}_$custom"));
      $rpm_raw  = trim(@file_get_contents("$tmp_path/rpm_{$plugin}_$custom"));
      $pwm_raw  = trim(@file_get_contents("$tmp_path/pwm_{$plugin}_$custom"));

      // Parse a number or *, plus a parenthesized source such as CPU, Disk, or Idle.
      if ($temp_raw !== '') {
        if (preg_match('/^(\*|\d+)\s+\(([^)]+)\)$/', $temp_raw, $m)) {
          $temp_val    = $m[1];
          $temp_origin = $m[2];
        } elseif (preg_match('/^\d+$/', $temp_raw)) {
          $temp_val = $temp_raw;
        }
      }

      $rpm = ($rpm_raw !== "" && is_numeric($rpm_raw)) ? $rpm_raw : "-";
      $status = '<span class="green-text">Active</span>';

      // Calculate the PWM percentage.
      if ($pwm_raw !== "" && is_numeric($pwm_raw)) {
        $pct_val = round($pwm_raw / 255 * 100);
      }
      $rpm_val = $rpm;
  }

  $fans[] = [
    'label'       => $label,
    'temp_raw'    => $temp_val,
    'temp_origin' => $temp_origin,
    'rpm_val'     => $rpm_val,
    'percent'     => $pct_val,
    'status'      => $status
  ];
}

echo json_encode([
  'status' => $status_text,
  'fans' => $fans
]);
