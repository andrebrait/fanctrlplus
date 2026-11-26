<?php
function fcp_asset_version(string $relative_path): string {
    $relative_path = ltrim($relative_path, '/');
    if ($relative_path === '' || strpos($relative_path, '..') !== false) return 'missing';

    $hash = @hash_file('sha256', dirname(__DIR__) . '/' . $relative_path);
    return $hash === false ? 'missing' : substr($hash, 0, 16);
}

function fcp_asset_url(string $relative_path): string {
    $relative_path = ltrim($relative_path, '/');
    return '/plugins/fanctrlplus2/' . $relative_path . '?v=' . fcp_asset_version($relative_path);
}

function fcp_ui_asset_paths(): array {
    return [
        'fanctrlplus2.page',
        'fonts/style.css',
        'css/sweetalert2.min.css',
        'css/fcp.base.css',
        'css/curve-editor.css',
        'css/fanctrlplus2-swal2.css',
        'css/fanctrlplus2-swal2-dark.css',
        'js/sweetalert2.min.js',
        'js/chart.min.js',
        'include/Common.php',
        'include/FanBlockRender.php',
        'include/CurveConfig.php',
        'include/FanctrlLogic.php',
        'include/chart-handler.js',
        'include/curve-editor.js',
        'include/asset-version.js',
    ];
}

function fcp_ui_asset_version(): string {
    $context = hash_init('sha256');
    foreach (fcp_ui_asset_paths() as $relative_path) {
        hash_update($context, $relative_path . "\0" . fcp_asset_version($relative_path) . "\0");
    }
    return substr(hash_final($context), 0, 16);
}

// Parse one fan-history line ("epoch|src|label|temp|pwm", written by
// history_append in disk_group_control.sh). Returns null for a malformed
// line; temp is null while idling (no valid temperature source).
function fcp_parse_history_line(string $line): ?array {
    $parts = explode('|', $line);
    if (count($parts) !== 5) return null;

    [$epoch, $src, $label, $temp, $pwm] = $parts;
    if (!ctype_digit($epoch)) return null;
    if (!preg_match('/^(?:cpu|aux|idle|disk:\d+)$/', $src)) return null;
    if ($temp !== '' && !ctype_digit($temp)) return null;
    if (!ctype_digit($pwm) || intval($pwm) > 255) return null;

    return [
        't'     => intval($epoch),
        'src'   => $src,
        'label' => $label,
        'temp'  => $temp === '' ? null : intval($temp),
        'pwm'   => intval($pwm),
    ];
}

// =============================
// Migrate hwmonX configuration and label paths.
// =============================
// Find where a saved /sys/devices pwm (or temperature) path lives now. The hwmonN index follows
// probe order and a USB HID device gets a new instance suffix
// (0003:VID:PID.NNNN) each time it enumerates, so both are wildcarded; the
// rest of the path (bus topology, VID:PID, pwmN) still pins the same device.
const FCP_HID_INSTANCE = '#(/[0-9A-Fa-f]{4}:[0-9A-Fa-f]{4}:[0-9A-Fa-f]{4})\.[0-9A-Fa-f]+/#';

function find_moved_pwm_path(string $old_path): ?string {
    // A /sys/class path carries no device identity; glob characters would
    // turn the saved path into an arbitrary pattern.
    if (fcp_class_hwmon_path($old_path) || strpbrk($old_path, '*?[') !== false) return null;
    if (file_exists($old_path)) return $old_path;
    $pattern = preg_replace(
        // The last hwmonN directory, with or without a hwmon/ glue directory
        // above it (NVMe and thermal-zone hwmon sit directly under their class
        // device).
        ['#/hwmon\d+(?=/[^/]+$)#', FCP_HID_INSTANCE],
        ['/hwmon*', '$1.*/'],
        $old_path
    );
    $matches = glob($pattern) ?: [];
    return count($matches) === 1 ? $matches[0] : null;
}

// A legacy /sys/class/hwmon path names a number, whatever chip holds it, not
// a device: it is never driven or followed to another number. The first
// migration after the upgrade (FCP_CLASS_FLAG) pins controllers, labels and
// sensors saved that way to the chip holding that number then, the chip
// stable releases resolved them to; it runs once every plugin has installed
// (rc.fanctrlplus2 boot), so drivers have normally bound their chips by then.
// A number absent then stays saved, and a controller undriven until the user
// picks it again: it is never resolved later, when another chip may hold it
// (#41).
function fcp_class_hwmon_path(string $path): bool {
    return strpos($path, '/class/hwmon/') !== false;
}

const FCP_CLASS_FLAG = '.hwmon_numbers_converted';

// The speed and raw PWM (0-255) a Monitor only fan (service="2") reads now,
// straight from its controller, or '-' for each it cannot read. Nothing drives
// such a fan, so there is no cached reading, and its PWM is the one the BIOS
// set. A /sys/class/hwmon path is not read: it names a number whatever chip
// holds it now (#41).
// A USB controller is read only as the saved device ($identity), since another
// device may hold its last path now, as Run Now and the loop require;
// $identities is fcp_pwm_identity_map(list_pwm()), built once per request.
function fcp_monitor_reading(string $controller, string $identity = '', ?array $identities = null): array {
    $none = ['rpm' => '-', 'pwm' => '-'];
    if (fcp_class_hwmon_path($controller) || !preg_match('#^(/.+)/pwm(\d+)$#D', $controller, $m)) return $none;
    if (str_starts_with($identity, 'usb:') && fcp_unique_pwm_identity($controller, $identities) !== $identity) return $none;
    $rpm = trim((string)@file_get_contents("{$m[1]}/fan{$m[2]}_input"));
    $pwm = trim((string)@file_get_contents($controller));
    return ['rpm' => ctype_digit($rpm) ? $rpm : '-', 'pwm' => ctype_digit($pwm) ? $pwm : '-'];
}

// A hwmon temperature sensor saved by its /sys/class/hwmon index is pinned to
// the device holding that index on the first migration only
// (fcp_class_hwmon_path). A device path follows its device when its hwmon
// number changes, as a controller does; any other source is kept as saved.
function fcp_pin_sensor(string $path, bool $by_number): string {
    if ($by_number && fcp_class_hwmon_path($path)) return realpath($path) ?: $path;
    return str_starts_with($path, '/') ? (find_moved_pwm_path($path) ?? $path) : $path;
}

// A fan is a USB controller plus its interface/hwmon channel, not a USB port.
// Hex-encoded JSON is safe as an unquoted label key and a shell/INI value.
function fcp_pwm_identity(string $path): ?string {
    $path = realpath($path);
    if ($path === false || !preg_match('#/pwm\d+$#', $path)) return null;
    for ($dir = dirname($path); $dir !== dirname($dir); $dir = dirname($dir)) {
        if (!is_file("$dir/idVendor") || !is_file("$dir/idProduct")) continue;
        $vid = strtolower(trim((string)@file_get_contents("$dir/idVendor")));
        $pid = strtolower(trim((string)@file_get_contents("$dir/idProduct")));
        $serial = trim((string)@file_get_contents("$dir/serial"));
        if (!preg_match('/^[0-9a-f]{4}$/', $vid) || !preg_match('/^[0-9a-f]{4}$/', $pid) || $serial === '') return null;
        $channel = substr($path, strlen($dir));
        $channel = preg_replace('#^/[^/]+:(\d+\.\d+)/#', '/interface:$1/', $channel);
        $channel = preg_replace([FCP_HID_INSTANCE, '#/hwmon/hwmon\d+/#'], ['$1.*/', '/hwmon/hwmon*/'], $channel);
        $encoded = json_encode([$vid, $pid, $serial, $channel]);
        return $encoded === false ? null : 'usb:'.bin2hex($encoded);
    }
    return null;
}

function fcp_pwm_identity_map(array $pwms): array {
    $map = [];
    foreach ($pwms as $pwm) {
        $key = fcp_pwm_identity($pwm['sensor']);
        if ($key === null) continue;
        // Duplicate serials/channels cannot safely identify either device.
        $map[$key] = array_key_exists($key, $map) ? null : $pwm['sensor'];
    }
    return $map;
}

// Capture a serial key only while it uniquely identifies this channel.
// Devices with duplicated/placeholder serials retain their port identity.
function fcp_unique_pwm_identity(string $path, ?array $identities = null): ?string {
    $key = fcp_pwm_identity($path);
    if ($key === null) return null;
    $identities = $identities ?? fcp_pwm_identity_map(list_pwm());
    return ($identities[$key] ?? null) === realpath($path) ? $key : null;
}

function fcp_load_pwm_labels(string $file, string $hwmon_glob = '/sys/class/hwmon/hwmon*'): array {
    $identities = fcp_pwm_identity_map(list_pwm($hwmon_glob));
    $labels = [];
    foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
        if (!preg_match('/^(.+?)=(.+)$/', $line, $m)) continue;
        $path = strpos($m[1], 'usb:') === 0 ? ($identities[$m[1]] ?? null) : $m[1];
        if ($path !== null && preg_match('#/pwm\d+$#', $path)) $labels[$path] = $m[2];
    }
    return $labels;
}

function log_migrate(string $msg): void {
    // Write the plugin log.
    @file_put_contents("/var/log/fanctrlplus2-migrate.log",
        date("c")." ".$msg."\n", FILE_APPEND);
    // Mirror the entry to syslog.
    @exec('logger -t fanctrlplus2 ' . escapeshellarg($msg));
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

function migrate_cfg_and_labels(string $plugin, string $cfgpath = '', string $hwmon_glob = '/sys/class/hwmon/hwmon*'): void {
    $cfgpath   = $cfgpath ?: "/boot/config/plugins/$plugin";
    $labelFile = "$cfgpath/pwm_labels.cfg";
    $pwms = list_pwm($hwmon_glob);
    $identities = fcp_pwm_identity_map($pwms);
    $flag = "$cfgpath/".FCP_CLASS_FLAG;
    // The flag is written first: if it cannot be, nothing is converted by
    // number, rather than again at a later start when the numbers may differ.
    // Nothing to convert without a config directory: no flag, no log.
    $by_number = is_dir($cfgpath) && !file_exists($flag);
    if ($by_number && !@touch($flag)) {
        log_migrate("migrate: cannot write $flag; hwmon numbers are not converted");
        $by_number = false;
    }

    // --- cfgs ---
    // A fan assigned to the current path while an old one still pointed at
    // the vanished path keeps it: two loops must not drive the same PWM.
    $cfgfiles = glob("$cfgpath/{$plugin}_*.cfg") ?: [];
    $configs = []; $in_use = []; $pinned = [];
    foreach ($cfgfiles as $cfgfile) {
        $ini = @parse_ini_file($cfgfile);
        if (!$ini) continue;
        $configs[$cfgfile] = $ini;
        $path = (string)($ini['controller'] ?? '');
        $source = fcp_controller_source($ini);
        $resolved = strpos($source, 'usb:') === 0 ? ($identities[$source] ?? null) : (is_file($source) ? $source : null);
        // Already-current manual assignments win over stale migrated ones.
        if ($resolved === $path && $path !== '' && !isset($in_use[$path])) $in_use[$path] = $cfgfile;
    }
    // Then, on the first run, a device some class path names (a number) goes
    // to a fan saved on it: first one whose saved device moved there (it names
    // a device), then one saved on that class path, then one blanked but bound
    // to a device or serial; one blanked on a class path claims nothing, so
    // file order cannot hand it a fan's device. Devices no class path names
    // are left to the migration below, as on later runs.
    if ($by_number) {
        $target = []; $is_class = []; $named = [];
        foreach ($configs as $cfgfile => $ini) {
            $source = fcp_controller_source($ini);
            if (!preg_match('#^usb:|/pwm\d+$#', $source)) continue;
            $target[$cfgfile] = fcp_controller_target($source, $identities, true);
            $is_class[$cfgfile] = fcp_class_hwmon_path($source);
            if ($target[$cfgfile] !== null && $is_class[$cfgfile]) $named[$target[$cfgfile]] = true;
        }
        // Tiers: saved on a moved device, saved on a class path, then blanked
        // but bound to a device or serial. A blank class config never claims.
        foreach ([[false, false], [true, false], [false, true]] as [$by_class, $blank]) foreach ($target as $cfgfile => $device) {
            if ($device === null || !isset($named[$device]) || $is_class[$cfgfile] !== $by_class) continue;
            if (((string)($configs[$cfgfile]['controller'] ?? '') === '') === $blank) $in_use[$device] ??= $cfgfile;
        }
    }
    foreach ($configs as $cfgfile => $ini) {
        $original = $ini;
        foreach (['cpu_sensor', 'aux_sensor'] as $k) {
            if (($ini[$k] ?? '') === '') continue;
            $ini[$k] = implode(',', array_map(fn($s) => fcp_pin_sensor($s, $by_number), explode(',', (string)$ini[$k])));
            if ($ini[$k] !== $original[$k]) log_migrate("migrate cfg: $cfgfile $k: {$original[$k]} → {$ini[$k]}");
        }
        fcp_migrate_controller($ini, $cfgfile, $in_use, $identities, $by_number);
        $was = fcp_controller_source($original);
        $now = (string)($ini['controller'] ?? '');
        if (fcp_class_hwmon_path($was) && $now !== '' && !fcp_class_hwmon_path($now)) $pinned[$was] = $now;
        if ($ini === $original) continue;
        // Only these fields change. Re-serializing the entire INI would
        // re-escape free-text values that are also sourced by the shell.
        $buf = (string)file_get_contents($cfgfile);
        foreach (['controller', 'controller_identity', 'cpu_sensor', 'aux_sensor'] as $k) {
            if (($ini[$k] ?? null) === ($original[$k] ?? null)) continue;
            $v = str_replace(["\\", '"', '$', '`', "\r", "\n"], ["\\\\", '\\"', '\\$', '\\`', '', ''], (string)$ini[$k]);
            $line = "$k=\"$v\"";
            $buf = preg_replace_callback('/^[ \t]*'.$k.'[ \t]*=.*$/m', function () use ($line) { return $line; }, $buf, -1, $count);
            if ($count === 0) $buf = rtrim($buf, "\n")."\n".$line."\n";
        }
        safe_rewrite($cfgfile, $buf);
    }

    // --- labels ---
    if (is_file($labelFile)) {
        $lines = file($labelFile, FILE_IGNORE_NEW_LINES) ?: [];
        // Several lines can land on one path: a label re-entered for the
        // current path after the device moved, or stale ones from several
        // moves. Keep one line, as the readers see it (the last one), except
        // that a line saved for the current path beats any migrated one.
        $changed = false; $out = []; $pos = []; $current = [];
        foreach ($lines as $line) {
            if (!preg_match('/^(.+?)=(.*)$/', $line, $m)) { $out[]=$line; continue; }
            $old_path = trim($m[1], " \t\n\r\0\x0B\"'");
            $label    = $m[2];

            // Flags, and empty labels, which the readers ignore.
            if (preg_match('/^__FCP_[A-Z0-9_]+__$/', $old_path) || $label === '') {
                $out[] = $line;
                continue;
            }

            $stable = strpos($old_path, 'usb:') === 0;
            // A class label follows only its controller pinned above.
            $new_path = $stable ? ($identities[$old_path] ?? null) : ($pinned[$old_path] ?? find_moved_pwm_path($old_path));
            if ($new_path === null) { log_migrate("migrate label: no match, keep $old_path"); $out[]=$line; continue; }
            $is_current = $stable || $new_path === $old_path;
            $key = fcp_unique_pwm_identity($new_path, $identities) ?? $new_path;
            if ($key !== $old_path) $changed = true;
            if (!$is_current) {
                $changed = true;
                if (preg_match('#/(hwmon\d+)/#', $old_path, $o) && preg_match('#/(hwmon\d+)/#', $new_path, $n)) {
                    log_migrate("migrate label: $old_path → $new_path ({$o[1]} → {$n[1]})");
                } else {
                    log_migrate("migrate label: $old_path → $new_path");
                }
            }
            if (!isset($pos[$key])) {
                $pos[$key] = count($out);
                $out[] = $key.'='.$label;
            } else {
                $changed = true;
                if (!$is_current && isset($current[$key])) {
                    log_migrate("migrate label: drop $old_path, $new_path already labeled");
                    continue;
                }
                $out[$pos[$key]] = $key.'='.$label;
            }
            if ($is_current) $current[$key] = true;
        }
        if ($changed) safe_rewrite($labelFile, implode("\n", $out));
    }
}
// The saved binding migration resolves: the identity, else the path.
function fcp_controller_source(array $ini): string {
    $identity = (string)($ini['controller_identity'] ?? '');
    return $identity !== '' ? $identity : trim((string)($ini['controller'] ?? ''), " \t\n\r\0\x0B\"'");
}

// Where that binding is now: a USB controller by its serial, a class path by
// number on the first run only, a device path where its chip moved.
function fcp_controller_target(string $source, array $identities, bool $by_number): ?string {
    if (strpos($source, 'usb:') === 0) return $identities[$source] ?? null;
    if (fcp_class_hwmon_path($source)) return $by_number ? (realpath($source) ?: null) : null;
    return find_moved_pwm_path($source);
}

// Resolves one configuration's controller in $ini. A saved path that no longer
// exists, or a /sys/class one not pinned on the first run ($by_number,
// fcp_class_hwmon_path), is kept for the settings page to show, until the user
// picks another controller; nothing is moved to another device.
function fcp_migrate_controller(array &$ini, string $cfgfile, array &$in_use, array $identities, bool $by_number): void {
    $old_path = trim((string)($ini['controller'] ?? ''), " \t\n\r\0\x0B\"'");
    $identity = (string)($ini['controller_identity'] ?? '');
    if ($identity === '' && !preg_match('#/pwm\d+$#', $old_path)) return;
    $source = fcp_controller_source($ini);
    $new_path = fcp_controller_target($source, $identities, $by_number);
    if ($new_path !== null && isset($in_use[$new_path]) && $in_use[$new_path] !== $cfgfile) {
        log_migrate("migrate cfg: keep $old_path in $cfgfile, $new_path is already controlled");
        if ($identity === '' && !is_file($old_path)) return;
        // A live losing path must also stop. Retain its binding for
        // recovery when the winning assignment is removed.
        if ($identity === '') $identity = fcp_unique_pwm_identity($old_path, $identities) ?? $old_path;
        $ini['controller_identity'] = $identity;
        $new_path = null;
    }
    if ($new_path === null) {
        log_migrate("migrate cfg: no match for $cfgfile, identity=$identity");
        // Never drive a replacement at the old path. Keep the identity
        // so a disconnected/ambiguous controller can recover later.
        if ($identity === '') return;
        // A path that exists now belongs to another fan or device: blank it.
        if (file_exists($old_path) && !fcp_class_hwmon_path($old_path)) $ini['controller'] = '';
        return;
    }
    $in_use[$new_path] = $cfgfile;
    $ini['controller'] = $new_path;
    $ini['controller_identity'] = fcp_unique_pwm_identity($new_path, $identities) ?? $new_path;
    if ($new_path !== $old_path) log_migrate("migrate cfg: $cfgfile controller: $old_path → $new_path");
}

// ================================
// END: Migrate hwmonX (cfg+labels)
// ================================

// The sensor is the resolved /sys/devices path: saved labels and controller
// settings use it, and the /sys/class/hwmon/hwmonN link follows probe order.
// Older drivers keep their attributes on the parent device instead.
// "pwm[0-9]*" would also match pwm1_enable and friends, hence the regex.
function list_pwm(string $hwmon_glob = '/sys/class/hwmon/hwmon*') {
  $out = [];
  foreach (glob($hwmon_glob) ?: [] as $hwmon) {
    $name = '';
    foreach ([$hwmon, "$hwmon/device"] as $dir) {
      if (is_file("$dir/name")) $name = trim(file_get_contents("$dir/name"));
      foreach (glob("$dir/pwm*") ?: [] as $pwm) {
        if (!preg_match('/^pwm\d+$/', basename($pwm)) || !is_file($pwm)) continue;
        $sensor = realpath($pwm) ?: $pwm;
        $out[$sensor] = ['chip' => $name, 'name' => basename($pwm), 'sensor' => $sensor];
      }
    }
  }

  $out = array_values($out);
  usort($out, fn($a, $b) => strnatcmp($a['name'], $b['name']) ?: strcmp($a['sensor'], $b['sensor']));
  return $out;
}

// On MSI boards the nct6687 driver uses its msi_alt1 register layout, where
// writes to the system-fan channels (pwm3 and up) are accepted but ignored
// unless the msi_fan_brute_force module option is set; those fans then stay
// at about 60%. The option is not readable from sysfs, so its state is taken,
// in order, from the fan_control_watchdog attribute (created only in that
// mode, by newer builds), the driver's "MSI fan brute force mode" line in the
// kernel log (printed on auto-detected MSI boards; the latest load wins), and
// finally the modprobe configuration.
// https://github.com/Fred78290/nct6687d#loading-msi_fan_brute_force-parameter-fails
function fcp_nct6687_brute_force_missing(
  array $controllers,
  string $module_dir = '/sys/module/nct6687',
  string $modprobe_glob = '/etc/modprobe.d/*.conf',
  ?string $kernel_log = null
): bool {
  $fan_config = @file_get_contents("$module_dir/parameters/fan_config");
  if ($fan_config === false || trim($fan_config) !== 'msi_alt1') return false;

  $hwmon = null;
  foreach ($controllers as $controller) {
    if (!preg_match('/^pwm(\d+)$/', basename((string)$controller), $m) || (int)$m[1] < 3) continue;
    $dir = dirname($controller);
    // The driver names its hwmon device after the chip it found.
    if (preg_match('/^nct668[367]$/', trim((string)@file_get_contents("$dir/name")))) {
      $hwmon = $dir;
      break;
    }
  }
  if ($hwmon === null || file_exists("$hwmon/fan_control_watchdog")) return false;

  // syslog first, dmesg last: dmesg holds the most recent load, but its ring
  // buffer may have dropped the line on a long uptime. Only the last 4 MiB of
  // syslog are read, so a huge log cannot exhaust PHP's memory limit.
  $syslog = '/var/log/syslog';
  $kernel_log ??= (string)@file_get_contents($syslog, false, null, -min(4 << 20, (int)@filesize($syslog)))
    . "\n" . (string)@shell_exec('dmesg 2>/dev/null');
  if (preg_match_all('/nct668\d[^\n]*MSI fan brute force mode: (enabled|disabled)/', $kernel_log, $m)) {
    return end($m[1]) === 'disabled';
  }

  // modprobe reads the files in name order and passes every option along, and
  // the kernel applies them in turn, so the last setting wins. Kernel bools
  // accept 1/y/t/on (optionally quoted), or the bare name; '-' and '_' are
  // interchangeable.
  $files = glob($modprobe_glob) ?: [];
  usort($files, fn($a, $b) => strcmp(basename($a), basename($b)));
  $last = null;
  foreach ($files as $conf) {
    preg_match_all('/^\s*options\s+nct6687\s+(.*)$/m', (string)@file_get_contents($conf), $lines);
    foreach ($lines[1] as $args) {
      preg_match_all('/(?:^|\s)msi[-_]fan[-_]brute[-_]force(?:="?([^\s"]*)"?)?(?=\s|$)/', $args, $vals);
      foreach ($vals[1] as $val) $last = $val;
    }
  }
  return $last === null || !preg_match('/^(?:$|[1yYtT]|[oO][nN])/', $last);
}

// Shown on the settings page when no PWM controller is found at all. Most
// often the driver of the mainboard's Super I/O chip (nct6775, it87 and the
// like) is not loaded: Unraid loads only the drivers the Dynamix System
// Temperature plugin saved in its list, and a list holding only coretemp
// leaves the fan headers out. The plugin's Detect button finds the driver;
// nothing is loaded or probed from here.
const FCP_SYSTEM_TEMP_DIR = '/usr/local/emhttp/plugins/dynamix.system.temp';

function fcp_no_pwm_notice(array $pwms, string $system_temp_dir = FCP_SYSTEM_TEMP_DIR): string {
  if ($pwms) return '';
  $installed = is_dir($system_temp_dir);
  $page = $installed ? '<a href="/Settings/TempSettings">Settings → System Temp</a>' : 'Settings → System Temp';
  $steps = [
    '<strong>Detect</strong>: looks for the board\'s sensor chips once and fills <strong>Available drivers</strong>, ex. <code>coretemp nct6775</code> on many ASRock and ASUS boards, <code>it87</code> on many Gigabyte boards.',
    'Check <strong>Available drivers</strong>: a list with only <code>coretemp</code> (the CPU) means no fan chip was found; see below.',
    '<strong>Save</strong>: stores the list, so the drivers load at every boot. Saving alone does not load them.',
    '<strong>Load drivers</strong>: loads them now, without a reboot. Then reload this page.',
  ];
  return '<div class="fcp-asset-update-notice fcp-no-pwm-notice"><div>'
    . '<strong>No PWM controllers found.</strong> Fan headers are driven by the mainboard\'s fan and sensor chip, and its driver is probably not loaded, so there is nothing to offer here yet. '
    . ($installed ? '' : 'Install <strong>Dynamix System Temperature</strong> from Apps first, then ')
    . ($installed ? 'In ' : 'in ') . $page . ':'
    . '<ol class="fcp-no-pwm-steps"><li>' . implode('</li><li>', $steps) . '</li></ol>'
    . 'If Detect finds no fan chip, the built-in drivers may not support it, and a driver plugin from Apps is needed: ex. the Nuvoton NCT6687 Driver for many MSI boards.'
    . '</div></div>';
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

// ===== Temperature readings =====
// Every reading the plugin evaluates is pulled into the range the fan curve
// can act on rather than discarded: the curve saturates at its own high point,
// so the ceiling and a wild reading drive the fan identically. A source with
// nothing to report says so by producing no reading at all, never by a
// sentinel value.
// A fan curve has nothing below zero, so a sub-zero reading is floored there.
// There is no ceiling: a reading inside the plausible range is reported as
// measured rather than trimmed to a number the sensor never gave.
const FCP_TEMP_FLOOR = 0;

// Beyond this, a value is not a measurement at all. Tools report failure in
// band -- mget_temp has been seen printing 10000 or -10000 when it cannot read
// the card, exiting 0 as it does so -- and clamping such a value to the ceiling
// would drive the fan to maximum on a failed read. Anything outside leaves the
// source with nothing to report.
const FCP_TEMP_IMPLAUSIBLE_BELOW = -100;
const FCP_TEMP_IMPLAUSIBLE_ABOVE = 300;

function fcp_clamp_temp(int $temp): ?int {
  if ($temp < FCP_TEMP_IMPLAUSIBLE_BELOW || $temp > FCP_TEMP_IMPLAUSIBLE_ABOVE) return null;
  return max(FCP_TEMP_FLOOR, $temp);
}

// ===== User-supplied sensor scripts =====
// Any readable file dropped in the sensors.d directory is a sensor, executable
// or not: the directory sits on flash that cannot hold an execute bit. The
// contract is deliberately small: print the temperature in Celsius and nothing
// else, or print nothing and exit non-zero, which disables it for that round only.
const FCP_CUSTOM_SENSOR_DIR = '/boot/config/plugins/fanctrlplus2/sensors.d';
const FCP_CUSTOM_SENSOR_TIMEOUT = 5;

// Whole degrees Celsius from a script's output, or null when the script did
// not honour the contract. A fractional reading is truncated, and the result
// is clamped like every other reading.
function parse_custom_sensor_output(string $output): ?int {
  $reading = trim($output);
  if (!preg_match('/^-?\d+(?:\.\d+)?$/', $reading)) return null;

  return fcp_clamp_temp((int)$reading);
}

// Run every custom sensor script and offer the ones that reported a reading.
// Returns array of ['path' => 'custom:ambient', 'label' => 'ambient (24°C)', ...]
function detect_custom_temps(string $dir = FCP_CUSTOM_SENSOR_DIR): array {
  $result = [];
  $scripts = glob("$dir/*") ?: [];
  sort($scripts);
  $runner = dirname(__DIR__) . '/scripts/custom_sensor.sh';

  foreach ($scripts as $script) {
    $name = basename($script);
    // Same names the control loop can read back: no hidden files, no
    // characters that would not survive the sensor list.
    if (!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/', $name)) continue;
    if (!is_file($script) || !is_readable($script)) continue;

    // The flash drive holding the directory cannot carry an execute bit, so
    // the runner executes a private runtime copy and enforces the timeout.
    $output = [];
    $status = 0;
    exec(
      'bash ' . escapeshellarg($runner) . ' ' . escapeshellarg($script) . ' '
        . FCP_CUSTOM_SENSOR_TIMEOUT . ' 2>/dev/null',
      $output,
      $status
    );
    if ($status !== 0) continue;

    $temp = parse_custom_sensor_output(implode("\n", $output));
    if ($temp === null || $temp <= 0) continue;

    $result[] = [
      'path'  => "custom:{$name}",
      'label' => "{$name} ({$temp}°C)",
      'chip'  => 'Custom Sensors',
      'idx'   => count($result),
    ];
  }

  return $result;
}

// Find the Mellanox mget_temp binary (shipped with the Mellanox Firmware Tools)
function find_mget_temp(): ?string {
  $candidates = [
    '/usr/bin/mget_temp',
    '/usr/local/bin/mget_temp',
    '/usr/bin/mst/mget_temp',
  ];
  foreach ($candidates as $path) {
    if (is_executable($path)) return $path;
  }
  $which = trim(shell_exec("which mget_temp 2>/dev/null") ?? '');
  if ($which !== '' && is_executable($which)) return $which;
  return null;
}

// Parse one mget_temp reading. The tool prints the network chip temperature in
// whole degrees Celsius, bare on most builds and labelled on some, so the first
// number in the output is the reading. Returns null when the output holds no
// number at all (unreadable device, driver not loaded).
function parse_mget_temp(string $output): ?int {
  if (!preg_match('/-?\d+/', $output, $m)) return null;
  return fcp_clamp_temp((int)$m[0]);
}

// Mellanox (vendor 0x15b3) PCI functions, keyed by PCI address with the bound
// network interface name as the value (empty when the function has no netdev).
// Virtual functions are skipped: they are the same silicon as their physical
// function and would list one card many times over.
function list_mellanox_pci(string $sysfs_devices = '/sys/bus/pci/devices'): array {
  $result = [];

  foreach (glob("$sysfs_devices/*") as $device) {
    if (!is_readable("$device/vendor")) continue;
    if (strtolower(trim((string)@file_get_contents("$device/vendor"))) !== '0x15b3') continue;
    if (file_exists("$device/physfn")) continue;

    $iface = '';
    $nets = glob("$device/net/*");
    if ($nets) $iface = basename($nets[0]);

    $result[basename($device)] = $iface;
  }

  ksort($result);
  return $result;
}

// Detect Mellanox NIC temperatures via mget_temp. The chip temperature is not
// exposed through hwmon, so this is the only way to steer a fan by it.
// Returns array of ['path' => 'mlx:0000:77:00.0', 'label' => 'Mellanox eth2 (71°C)', ...]
function detect_mlx_temps(string $mget_temp): array {
  $result = [];
  $idx = 0;

  foreach (list_mellanox_pci() as $bdf => $iface) {
    $temp = parse_mget_temp((string)shell_exec("$mget_temp -d " . escapeshellarg($bdf) . " 2>/dev/null"));
    if ($temp === null || $temp <= 0) continue;

    $name = $iface !== '' ? $iface : $bdf;
    $result[] = [
      'path'  => "mlx:{$bdf}",
      'label' => "Mellanox {$name} ({$temp}°C)",
      'chip'  => 'Network Adapter',
      'idx'   => $idx++,
    ];
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
        'path'     => realpath($input) ?: $input,
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
            'path'     => realpath($input) ?: $input,
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
        'path'  => realpath($input) ?: $input,
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
          'path'  => realpath($input) ?: $input,
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

  // Append Mellanox NIC temperatures via mget_temp (if available)
  $mget_temp = find_mget_temp();
  if ($mget_temp !== null) {
    foreach (detect_mlx_temps($mget_temp) as $mlx) {
      $result[] = $mlx;
    }
  }

  // Append whatever the user's own sensor scripts report
  foreach (detect_custom_temps() as $cs) {
    $result[] = $cs;
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

// ===== Fan-control interval =====
// The interval is a number of seconds. Configs written before the switch from
// minutes are grandfathered by converting their "interval" value, so an
// existing fan keeps its cadence until it is next saved.
const FCP_INTERVAL_MIN_SECONDS = 5;
const FCP_INTERVAL_MAX_SECONDS = 3600;
const FCP_INTERVAL_DEFAULT_SECONDS = 120;

function cfg_interval_seconds(array $cfg): int {
  $seconds = (string)($cfg['interval_sec'] ?? '');

  if (!ctype_digit($seconds) || (int)$seconds <= 0) {
    $minutes = (string)($cfg['interval'] ?? '');
    $seconds = ctype_digit($minutes) && (int)$minutes > 0
      ? (string)((int)$minutes * 60)
      : (string)FCP_INTERVAL_DEFAULT_SECONDS;
  }

  return max(FCP_INTERVAL_MIN_SECONDS, min(FCP_INTERVAL_MAX_SECONDS, (int)$seconds));
}
