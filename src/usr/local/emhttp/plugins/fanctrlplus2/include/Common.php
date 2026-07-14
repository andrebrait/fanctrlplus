<?php
// =============================
// Migrate hwmonX configuration and label paths.
// =============================
function normalize_chip_name(string $chip): string {
    // Remove a trailing numeric suffix.
    $chip = preg_replace('/\.\d+$/', '', $chip);
    // Remove ISA address fragments such as -isa-XXXX.
    $chip = preg_replace('/-isa-[0-9a-fA-Fx]+$/', '', $chip);
    return $chip;
}

function build_pwm_map(): array {
    $map = [];
    foreach (glob("/sys/class/hwmon/hwmon*") as $dir) {
        $name_file = "$dir/name";
        if (!is_file($name_file)) continue;

        $chip = normalize_chip_name(trim(file_get_contents($name_file)));

        foreach (glob("$dir/pwm[0-9]") as $pwm_path) {
            $pwmN = basename($pwm_path);
            $real = realpath($pwm_path) ?: $pwm_path;
            $map["$chip:$pwmN"] = $real;
        }
    }
    return $map;
}

function extract_chip_and_pwm_from_path(string $old_path): ?array {
    $old_path = trim($old_path, " \t\n\r\0\x0B\"'");

    // Extract pwmN from the path first.
    preg_match_all('/pwm(\d+)/', $old_path, $pm);
    if (empty($pm[1])) return null;
    $pwmN = 'pwm' . end($pm[1]);

    // Prefer a platform node such as nct6775.672 when available.
    if (preg_match('#/platform/([^/]+)/#', $old_path, $m)) {
        $platform = $m[1];
        // Find the matching pwmN under platform/$platform/hwmon/*.
        foreach (glob("/sys/devices/platform/$platform/hwmon/hwmon*") as $dir) {
            if (is_file("$dir/$pwmN") && is_file("$dir/name")) {
                $chip = normalize_chip_name(trim(@file_get_contents("$dir/name")));
                if ($chip !== '') {
                    return [$chip, $pwmN];
                }
            }
        }
    }

    // Fall back to hwmonX under /sys/class/hwmon when no platform node exists.
    preg_match_all('/hwmon(\d+)/', $old_path, $hm);
    if (!empty($hm[1])) {
        $hwmon = 'hwmon' . end($hm[1]);
        $name_file = "/sys/class/hwmon/$hwmon/name";
        if (is_file($name_file)) {
            $chip = normalize_chip_name(trim(@file_get_contents($name_file)));
            if ($chip !== '') return [$chip, $pwmN];
        }
    }

    // Last resort: scan every hwmon* directory.
    foreach (glob("/sys/class/hwmon/hwmon*") as $dir) {
        if (is_file("$dir/$pwmN") && is_file("$dir/name")) {
            $chip = normalize_chip_name(trim(@file_get_contents("$dir/name")));
            if ($chip !== '') return [$chip, $pwmN];
        }
    }

    return null;
}

function log_migrate(string $msg): void {
    // Write the plugin log.
    @file_put_contents("/var/log/fanctrlplus2-migrate.log",
        date("c")." ".$msg."\n", FILE_APPEND);
    // Mirror the entry to syslog.
    @exec("logger -t fanctrlplus2 '$msg'");
}

function safe_rewrite(string $file, string $content): bool {
    $content = rtrim($content, "\n") . "\n";
    $old = @file_get_contents($file);
    if ($old !== false && rtrim($old, "\n") . "\n" === $content) return false;
    $tmp = $file . '.tmp';
    @file_put_contents($tmp, $content, LOCK_EX);
    @rename($tmp, $file);
    return true;
}

function migrate_cfg_and_labels(string $plugin): void {
    $cfgpath   = "/boot/config/plugins/$plugin";
    $labelFile = "$cfgpath/pwm_labels.cfg";
    $pwm_map   = build_pwm_map();

    // --- labels ---
    if (is_file($labelFile)) {
        $lines = file($labelFile, FILE_IGNORE_NEW_LINES) ?: [];
        $changed = false; $out = [];
        foreach ($lines as $line) {
            if (!preg_match('/^(.+?)=(.*)$/', $line, $m)) { $out[]=$line; continue; }
            $old_path = trim($m[1], " \t\n\r\0\x0B\"'");
            $label    = $m[2];

            if (preg_match('/^__FCP_[A-Z0-9_]+__$/', $old_path)) {
                $out[] = $line;
                continue;
            }

            $pair = extract_chip_and_pwm_from_path($old_path);
            if (!$pair) { log_migrate("migrate label: skip (unparsable) $old_path"); $out[]=$line; continue; }
            [$chip,$pwmN] = $pair;
            $key = "$chip:$pwmN";
            if (!isset($pwm_map[$key])) { log_migrate("migrate label: no match for $chip:$pwmN, keep $old_path"); $out[]=$line; continue; }

            $new_path = $pwm_map[$key];
            if ($new_path !== $old_path) {
                if (preg_match('#/(hwmon\d+)/#', $old_path, $o) && preg_match('#/(hwmon\d+)/#', $new_path, $n)) {
                    log_migrate("migrate label: $old_path → $new_path ({$o[1]} → {$n[1]})");
                } else {
                    log_migrate("migrate label: $old_path → $new_path");
                }
                $changed = true;
                $out[] = $new_path.'='.$label;
            } else {
                $out[] = $line;
            }
        }
        if ($changed) safe_rewrite($labelFile, implode("\n", $out));
    }

    // --- cfgs ---
    foreach (glob("$cfgpath/{$plugin}_*.cfg") ?: [] as $cfgfile) {
        $ini = @parse_ini_file($cfgfile);
        if (!$ini) continue;

        $old_path = trim((string)($ini['controller'] ?? ''), " \t\n\r\0\x0B\"'");

        if ($old_path === '' || !preg_match('#/hwmon\d+/pwm\d+$#', $old_path)) {
            continue;
        }

        $pair = extract_chip_and_pwm_from_path($old_path);
        if (!$pair) { 
            log_migrate("migrate cfg: skip (unparsable) $cfgfile controller=$old_path"); 
            continue; 
        }
        [$chip,$pwmN] = $pair;
        $key = "$chip:$pwmN";
        if (!isset($pwm_map[$key])) { 
            log_migrate("migrate cfg: no match for $cfgfile ($chip:$pwmN), keep $old_path"); 
            continue; 
        }

        $new_path = $pwm_map[$key];
        if ($new_path === $old_path) continue;

        if (preg_match('#/(hwmon\d+)/#', $old_path, $o) && preg_match('#/(hwmon\d+)/#', $new_path, $n)) {
            log_migrate("migrate cfg: $cfgfile controller: $old_path → $new_path ({$o[1]} → {$n[1]})");
        } else {
            log_migrate("migrate cfg: $cfgfile controller: $old_path → $new_path");
        }

        $ini['controller'] = $new_path;
        $buf=''; foreach ($ini as $k=>$v){ $v=str_replace('"','',(string)$v); $buf.=$k.'="'.$v."\"\n"; }
        safe_rewrite($cfgfile, $buf);
    }
}
// ================================
// END: Migrate hwmonX (cfg+labels)
// ================================

function list_pwm() {
  $out = [];
  exec("find /sys/devices -type f -iname 'pwm[0-9]' -exec dirname \"{}\" + | uniq", $chips);
  foreach ($chips as $chip) {
    $name = is_file("$chip/name") ? trim(file_get_contents("$chip/name")) : '';
    foreach (glob("$chip/pwm[0-9]") as $pwm) {
      $out[] = ['chip' => $name, 'name' => basename($pwm), 'sensor' => $pwm];
    }
  }

  usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
  return $out;
}

// Find storcli binary (storcli64, storcli2, storcli) in common paths
function find_storcli(): ?string {
  $candidates = [
    '/opt/MegaRAID/storcli/storcli64',
    '/opt/MegaRAID/storcli/storcli',
    '/usr/local/sbin/storcli64',
    '/usr/local/sbin/storcli',
    '/usr/local/bin/storcli64',
    '/usr/local/bin/storcli',
    '/usr/sbin/storcli64',
    '/usr/sbin/storcli',
    '/usr/bin/storcli64',
    '/usr/bin/storcli',
  ];
  foreach ($candidates as $path) {
    if (is_executable($path)) return $path;
  }
  // Fall back to PATH lookup
  $which = trim(shell_exec("which storcli64 2>/dev/null") ?? '');
  if ($which !== '' && is_executable($which)) return $which;
  $which = trim(shell_exec("which storcli 2>/dev/null") ?? '');
  if ($which !== '' && is_executable($which)) return $which;
  return null;
}

// Detect LSI/MegaRAID controller temperatures via storcli
// Returns array of ['path' => 'storcli:cX', 'label' => 'LSI RAID c0 - ROC (68°C)', 'chip' => ..., 'idx' => ...]
function detect_storcli_temps(string $storcli_bin): array {
  $result = [];

  $output = shell_exec("$storcli_bin /call show temperature 2>/dev/null");
  if (!$output) return $result;

  $controller = 0;
  $model = 'LSI RAID';

  foreach (explode("\n", $output) as $line) {
    $line = trim($line);

    if (preg_match('/^Controller\s*=\s*(\d+)/i', $line, $m)) {
      $controller = (int)$m[1];
      continue;
    }

    // Product Name = SAS9341-8i
    if (preg_match('/^Product Name\s*=\s*(.+)/i', $line, $m)) {
      $model = trim($m[1]);
      continue;
    }

    // ROC temperature(Degree Celsius) 68
    if (preg_match('/ROC temperature.*\s(\d+)\s*$/i', $line, $m)) {
      $temp = (int)$m[1];
      if ($temp > 0) {
        $result[] = [
          'path'  => "storcli:c{$controller}:roc",
          'label' => "{$model} c{$controller} - ROC ({$temp}°C)",
          'chip'  => 'RAID Controller',
          'idx'   => $controller * 10,
        ];
      }
      continue;
    }
  }

  return $result;
}

// Find nvidia-smi binary
function find_nvidia_smi(): ?string {
  $candidates = [
    '/usr/bin/nvidia-smi',
    '/usr/local/bin/nvidia-smi',
    '/usr/lib/nvidia/bin/nvidia-smi',
  ];
  foreach ($candidates as $path) {
    if (is_executable($path)) return $path;
  }
  $which = trim(shell_exec("which nvidia-smi 2>/dev/null") ?? '');
  if ($which !== '' && is_executable($which)) return $which;
  return null;
}

// Detect NVIDIA GPU temperatures via nvidia-smi
// Returns array of ['path' => 'nvidia:gpu0', 'label' => 'NVIDIA GeForce RTX 3080 - GPU 0 (55°C)', ...]
function detect_nvidia_temps(string $nvidia_smi): array {
  $result = [];

  // Query all GPUs: index, name, temperature
  $output = shell_exec("$nvidia_smi --query-gpu=index,name,temperature.gpu --format=csv,noheader,nounits 2>/dev/null");
  if (!$output) return $result;

  foreach (explode("\n", trim($output)) as $line) {
    $line = trim($line);
    if ($line === '') continue;

    $parts = array_map('trim', explode(',', $line));
    if (count($parts) < 3) continue;

    $idx  = (int)$parts[0];
    $name = $parts[1];
    $temp = (int)$parts[2];

    if ($temp <= 0) continue;

    $result[] = [
      'path'  => "nvidia:gpu{$idx}",
      'label' => "{$name} - GPU {$idx} ({$temp}°C)",
      'chip'  => 'GPU',
      'idx'   => $idx,
    ];
  }

  return $result;
}

require_once __DIR__ . '/DiskInventory.php';

// Find likely CPU temperature sensors and attach current values and priorities.
function detect_cpu_sensors(): array {
  $result = [];

  $priority_order = [
    'Package id', 'Tctl', 'Tdie', 'CPU Temp',
    'PECI Agent', 'CPUTIN', 'Core 0'
  ];

  $cpu_chips_exact = ['k10temp','coretemp','zenpower'];
  $superio_prefixes = ['it8','it86','it87','nct6','nct67','nct68','nuvoton'];
  $deny_chips = ['amdgpu','nvme','gpu'];

  foreach (glob('/sys/class/hwmon/hwmon*') as $hwmonPath) {
    $nameFile = "$hwmonPath/name";
    if (!is_readable($nameFile)) continue;
    $chipName = trim(@file_get_contents($nameFile));
    $chipLower = strtolower($chipName);

    // Excluded labels.
    foreach ($deny_chips as $deny) {
      if (strpos($chipLower, $deny) !== false) continue 2;
    }

    $isCpuChip  = in_array($chipLower, $cpu_chips_exact, true);
    $isSuperIO  = false;
    foreach ($superio_prefixes as $p) {
      if (strpos($chipLower, $p) === 0) { $isSuperIO = true; break; }
    }

    // Prefer sensors with labels.
    foreach (glob("$hwmonPath/temp*_label") as $labelFile) {
      $label = trim(@file_get_contents($labelFile));
      $input = str_replace('_label', '_input', $labelFile);
      if (!is_readable($input)) continue;

      $raw = trim(@file_get_contents($input));
      $c   = is_numeric($raw) ? intval($raw) / 1000 : null;
      if ($c === null || $c <= 0) continue;

      // Hide per-core coretemp entries to keep the list compact.
      if ($chipLower === 'coretemp' && preg_match('/^Core\s+\d+$/i', $label)) {
        continue;
      }

      // Require a known Super I/O label; include CPU chips at lower priority.
      $prio = 999; $hit = false;
      foreach ($priority_order as $idx=>$k) {
        if (stripos($label, $k) !== false) { $hit = true; $prio = $idx; break; }
      }
      if (!$isCpuChip && $isSuperIO && !$hit) continue;
      if (!$isCpuChip && !$isSuperIO) continue;

      // Extract the sensor index from the filename.
      $idxNum = 999;
      if (preg_match('#/temp(\d+)_input$#', $input, $m)) $idxNum = (int)$m[1];

      $tempC = round($c, 1) . '°C';
      $result[] = [
        'path'     => $input,
        'label'    => "$chipName - $label ($tempC)",
        'priority' => $hit ? $prio : 998,
        'chip'     => $chipName,
        'idx'      => $idxNum,
      ];
    }

    // Use the Tctl fallback only when the k10temp directory has no label files.
    if ($isCpuChip && $chipLower === 'k10temp' && count(glob("$hwmonPath/temp*_label")) === 0) {
      $input = "$hwmonPath/temp1_input";
      if (is_readable($input)) {
        $raw = trim(@file_get_contents($input));
        $c   = is_numeric($raw) ? intval($raw) / 1000 : null;
        if ($c !== null && $c > 0) {
          $tempC = round($c, 1) . '°C';
          $result[] = [
            'path'     => $input,
            'label'    => "$chipName - Tctl ($tempC)",
            'priority' => array_search('Tctl', $priority_order, true) !== false
                          ? array_search('Tctl', $priority_order, true)
                          : 0,
            'chip'     => $chipName,
            'idx'      => 1
          ];
        }
      }
    }
  }

  // Sort by priority, chip name, then sensor index.
  usort($result, function($a, $b){
    return $a['priority'] <=> $b['priority']
        ?: strnatcasecmp($a['chip'],$b['chip'])
        ?: ($a['idx'] <=> $b['idx']);
  });

  // Key by path so duplicate paths collapse naturally.
  $final = [];
  foreach ($result as $e) {
    $final[$e['path']] = $e['label'];
  }
  return $final;
}

// Scan all hwmon sensors and return non-CPU, non-NVMe temperature sensors
// (e.g. ethernet cards, chipset/PCH, VRM, GPU, board temps)
function detect_aux_sensors(): array {
  $result = [];

  $cpu_chips_exact = ['k10temp','coretemp','zenpower'];
  $superio_prefixes = ['it8','it86','it87','nct6','nct67','nct68','nuvoton'];
  $nvme_deny = ['nvme'];

  // CPU-related label keywords to exclude from SuperIO chips
  $cpu_labels = ['Package id', 'Tctl', 'Tdie', 'CPU Temp', 'PECI Agent', 'CPUTIN', 'Core'];

  foreach (glob('/sys/class/hwmon/hwmon*') as $hwmonPath) {
    $nameFile = "$hwmonPath/name";
    if (!is_readable($nameFile)) continue;
    $chipName = trim(@file_get_contents($nameFile));
    $chipLower = strtolower($chipName);

    // Skip dedicated CPU chips entirely
    if (in_array($chipLower, $cpu_chips_exact, true)) continue;

    // Skip NVMe chips (handled by disk/smartctl)
    foreach ($nvme_deny as $deny) {
      if (strpos($chipLower, $deny) !== false) continue 2;
    }

    $isSuperIO = false;
    foreach ($superio_prefixes as $p) {
      if (strpos($chipLower, $p) === 0) { $isSuperIO = true; break; }
    }

    // Collect sensors with labels
    foreach (glob("$hwmonPath/temp*_label") as $labelFile) {
      $label = trim(@file_get_contents($labelFile));
      $input = str_replace('_label', '_input', $labelFile);
      if (!is_readable($input)) continue;

      $raw = trim(@file_get_contents($input));
      $c   = is_numeric($raw) ? intval($raw) / 1000 : null;
      if ($c === null || $c <= 0) continue;

      // For SuperIO chips, skip CPU-related labels (those belong to detect_cpu_sensors)
      if ($isSuperIO) {
        $isCpuLabel = false;
        foreach ($cpu_labels as $cpuKey) {
          if (stripos($label, $cpuKey) !== false) { $isCpuLabel = true; break; }
        }
        if ($isCpuLabel) continue;
      }

      $idxNum = 999;
      if (preg_match('#/temp(\d+)_input$#', $input, $m)) $idxNum = (int)$m[1];

      $tempC = round($c, 1) . '°C';
      $result[] = [
        'path'  => $input,
        'label' => "$chipName - $label ($tempC)",
        'chip'  => $chipName,
        'idx'   => $idxNum,
      ];
    }

    // For chips without labels, include raw temp*_input files
    if (count(glob("$hwmonPath/temp*_label")) === 0) {
      foreach (glob("$hwmonPath/temp*_input") as $input) {
        if (!is_readable($input)) continue;
        $raw = trim(@file_get_contents($input));
        $c   = is_numeric($raw) ? intval($raw) / 1000 : null;
        if ($c === null || $c <= 0) continue;

        $idxNum = 999;
        if (preg_match('#/temp(\d+)_input$#', $input, $m)) $idxNum = (int)$m[1];

        $tempC = round($c, 1) . '°C';
        $result[] = [
          'path'  => $input,
          'label' => "$chipName - temp" . $idxNum . " ($tempC)",
          'chip'  => $chipName,
          'idx'   => $idxNum,
        ];
      }
    }
  }

  // Append LSI/MegaRAID controller temperatures via storcli (if available)
  $storcli_bin = find_storcli();
  if ($storcli_bin !== null) {
    foreach (detect_storcli_temps($storcli_bin) as $st) {
      $result[] = $st;
    }
  }

  // Append NVIDIA GPU temperatures via nvidia-smi (if available)
  $nvidia_smi = find_nvidia_smi();
  if ($nvidia_smi !== null) {
    foreach (detect_nvidia_temps($nvidia_smi) as $nv) {
      $result[] = $nv;
    }
  }

  // Sort by chip name, then sensor index
  usort($result, function($a, $b){
    return strnatcasecmp($a['chip'], $b['chip'])
        ?: ($a['idx'] <=> $b['idx']);
  });

  // Group by chip name for optgroup display
  $grouped = [];
  foreach ($result as $e) {
    $grouped[$e['chip']][] = [
      'path'  => $e['path'],
      'label' => $e['label'],
    ];
  }
  return $grouped;
}
