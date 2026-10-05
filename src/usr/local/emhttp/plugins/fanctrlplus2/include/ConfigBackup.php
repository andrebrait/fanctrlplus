<?php
// Configuration files are sourced by Bash. A restore is data, never shell code.
const FCP_BACKUP_MAX_BYTES = 2097152;
const FCP_BACKUP_MAX_FILES = 256;

function fcp_config_lock(string $dir, int $mode = LOCK_EX) {
    if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0700, true))) throw new RuntimeException('Configuration directory is unavailable.');
    $path = "$dir/.config.lock";
    if (is_link($path) || (file_exists($path) && !is_file($path))) throw new RuntimeException('Unsafe configuration lock.');
    // Service daemons must never inherit the request's flock descriptor.
    $lock = @fopen($path, 'ce');
    if ($lock === false || !flock($lock, $mode)) throw new RuntimeException('Could not lock the configuration.');
    return $lock;
}

function fcp_backup_fan_name(string $name): bool {
    return preg_match('/^fanctrlplus2_[A-Za-z0-9_-]+\.cfg$/D', $name) === 1;
}

function fcp_config_files(string $dir, bool $includeTemporary = false): array {
    $files = [];
    foreach (array_merge(glob("$dir/fanctrlplus2_*.cfg") ?: [], ["$dir/pwm_labels.cfg", "$dir/order.cfg"]) as $path) {
        if (!file_exists($path) && !is_link($path)) continue;
        $name = basename($path);
        if (!$includeTemporary && preg_match('/^fanctrlplus2_temp_\d+\.cfg$/D', $name)) continue;
        if ((!fcp_backup_fan_name($name) && !in_array($name, ['pwm_labels.cfg', 'order.cfg'], true)) || is_link($path) || !is_file($path)) {
            throw new RuntimeException('Unsafe managed configuration file.');
        }
        $text = @file_get_contents($path);
        if ($text === false) throw new RuntimeException('Could not read the configuration.');
        $files[$name] = $text;
    }
    ksort($files);
    return $files;
}

function fcp_export_config(string $dir): string {
    $lock = fcp_config_lock($dir, LOCK_SH);
    try {
        $files = fcp_config_files($dir);
        if (count($files) > FCP_BACKUP_MAX_FILES) throw new RuntimeException('Too many configuration files to back up.');
        $json = json_encode(['format'=>'fanctrlplus2-config', 'version'=>1, 'files'=>(object)$files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) + 1 > FCP_BACKUP_MAX_BYTES) throw new RuntimeException('Configuration cannot be encoded within the backup size limit.');
        fcp_validate_config_backup($json);
        return $json."\n";
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function fcp_backup_sys_path(string $path, bool $pwm): bool {
    if ($path === '') return true;
    if (preg_match('#(?:^|/)\.\.?(?:/|$)#', $path)) return false;
    $end = $pwm ? 'pwm\d+' : '(?:temp\d+_input|temp)';
    return preg_match('#^/sys/(?:devices|class/(?:hwmon|thermal))/[A-Za-z0-9_.:/-]+/'.$end.'$#D', $path) === 1;
}

function fcp_backup_binding(string $value): bool {
    if (strpos($value, 'usb:') !== 0) return fcp_backup_sys_path($value, true);
    $hex = substr($value, 4);
    if ($hex === '' || strlen($hex) > 8192 || strlen($hex) % 2 || !ctype_xdigit($hex)) return false;
    $parts = json_decode(hex2bin($hex), true);
    return is_array($parts) && array_keys($parts) === [0,1,2,3]
        && is_string($parts[0]) && preg_match('/^[0-9a-f]{4}$/D', $parts[0])
        && is_string($parts[1]) && preg_match('/^[0-9a-f]{4}$/D', $parts[1])
        && is_string($parts[2]) && $parts[2] !== '' && strlen($parts[2]) <= 1024
        && is_string($parts[3]) && preg_match('#^/[A-Za-z0-9_.:/*-]+/pwm\d+$#D', $parts[3]);
}

// Decode only literal shell assignments. Unlike an INI parser, this rejects
// unescaped substitutions and command tails before any file can be sourced.
function fcp_backup_assignments(string $text): array {
    if (preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $text)) throw new InvalidArgumentException('Configuration contains control characters.');
    $values = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim($line, " \t");
        if ($line === '' || $line[0] === '#') continue;
        if (!preg_match('/^([a-z][a-z0-9_]*)=(.*)$/D', $line, $m) || array_key_exists($m[1], $values)) throw new InvalidArgumentException('Invalid configuration assignment.');
        $encoded = $m[2]; $value = '';
        if (strlen($encoded) >= 2 && $encoded[0] === '"' && substr($encoded, -1) === '"') {
            $inside = substr($encoded, 1, -1);
            for ($i=0, $length=strlen($inside); $i<$length; $i++) {
                $c = $inside[$i];
                if ($c === '\\') {
                    if (++$i >= $length || strpos('\\"$`', $inside[$i]) === false) throw new InvalidArgumentException('Invalid configuration escape.');
                    $value .= $inside[$i];
                } else {
                    // Tabs are literal inside double quotes; the form can save them.
                    if (strpos('"$`', $c) !== false || (ord($c) < 32 && $c !== "\t")) throw new InvalidArgumentException('Configuration must contain literal values only.');
                    $value .= $c;
                }
            }
        } elseif (preg_match('#^[A-Za-z0-9_./:,+-]*$#D', $encoded)) {
            $value = $encoded;
        } else { throw new InvalidArgumentException('Invalid configuration value.'); }
        $values[$m[1]] = $value;
    }
    return $values;
}

function fcp_validate_fan_config(string $text): array {
    $values = fcp_backup_assignments($text);
    $boolean = ['service','syslog','log_enable','cpu_enable','aux_enable'];
    $numeric = ['pwm','max','idle','idle_percent','low','high','interval','interval_sec','disk_group_count','cpu_min_temp','cpu_max_temp','aux_min_temp','aux_max_temp'];
    $string = ['custom','label','controller','controller_identity','fan','disks','cpu_sensor','aux_sensor'];
    foreach ($values as $key=>$value) {
        $group = preg_match('/^disk_group_(\d+)_(name|disks|low|high)$/D', $key, $m);
        if ($group && (strlen($m[1]) > 3 || (int)$m[1] > 255)) throw new InvalidArgumentException('Too many disk groups.');
        if (in_array($key, $boolean, true)) {
            if ($value !== '0' && $value !== '1') throw new InvalidArgumentException('Invalid configuration switch.');
        } elseif (in_array($key, $numeric, true) || ($group && in_array($m[2], ['low','high'], true))) {
            if ($value === '' && in_array($key, ['cpu_min_temp','cpu_max_temp','aux_min_temp','aux_max_temp'], true)) continue;
            if (!preg_match('/^(?:0|-?[1-9]\d{0,8})$/D', $value)) throw new InvalidArgumentException('Invalid numeric configuration value.');
            $number = (int)$value;
            if (in_array($key, ['pwm','max','idle'], true) && ($number < 0 || $number > 255)) throw new InvalidArgumentException('PWM value is outside 0–255.');
            if (in_array($key, ['interval','interval_sec'], true) && $number <= 0) throw new InvalidArgumentException('Interval must be positive.');
            if ($key === 'disk_group_count' && ($number < 0 || $number > 256)) throw new InvalidArgumentException('Invalid disk group count.');
            if ($key === 'idle_percent' && ($number < 0 || $number > 100)) throw new InvalidArgumentException('Invalid idle percentage.');
        } elseif (!in_array($key, $string, true) && !$group) {
            throw new InvalidArgumentException('Unsupported configuration key.');
        }
        if ($key === 'controller' && !fcp_backup_sys_path($value, true)) throw new InvalidArgumentException('Invalid PWM controller path.');
        if ($key === 'controller_identity' && !fcp_backup_binding($value)) throw new InvalidArgumentException('Invalid controller identity.');
        if ($key === 'cpu_sensor' && !fcp_backup_sys_path($value, false)) throw new InvalidArgumentException('Invalid CPU sensor path.');
        if ($key === 'fan' && $value !== '') {
            $pwmPath = preg_replace('#/fan(\d+)_input$#', '/pwm$1', $value);
            if ($pwmPath === $value || !fcp_backup_sys_path($pwmPath, true)) throw new InvalidArgumentException('Invalid legacy fan input.');
        }
        if ($key === 'aux_sensor') {
            foreach ($value === '' ? [] : explode(',', $value) as $sensor) {
                if (fcp_backup_sys_path($sensor, false)) continue;
                if (!preg_match('/^(?:custom:[A-Za-z0-9_-][A-Za-z0-9._-]*|storcli:c\d+:roc|nvidia:gpu\d+|mlx:[0-9a-fA-F]{4}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2}\.[0-9a-fA-F])$/D', $sensor)) throw new InvalidArgumentException('Invalid auxiliary sensor.');
            }
        }
        if ($key === 'disks' || ($group && $m[2] === 'disks')) {
            if ($value !== '' && !preg_match('/^[A-Za-z0-9_.:#=@+-]+(?:,[A-Za-z0-9_.:#=@+-]+)*$/D', $value)) throw new InvalidArgumentException('Invalid disk identifier.');
        }
    }
    if (!isset($values['custom']) || !preg_match('/^[A-Za-z0-9_-]+$/D', $values['custom'])) throw new InvalidArgumentException('A saved fan must have a valid name.');
    return $values;
}

function fcp_validate_config_backup(string $json): array {
    if (strlen($json) > FCP_BACKUP_MAX_BYTES) throw new InvalidArgumentException('The backup exceeds the 2 MiB limit.');
    $doc = json_decode($json);
    if (!is_object($doc) || ($doc->format ?? null) !== 'fanctrlplus2-config' || ($doc->version ?? null) !== 1 || !isset($doc->files) || !is_object($doc->files)) throw new InvalidArgumentException('Invalid or unsupported backup format.');
    $files = get_object_vars($doc->files);
    if (count($files) > FCP_BACKUP_MAX_FILES) throw new InvalidArgumentException('Too many backup files.');
    $names = []; $filenames = [];
    foreach ($files as $name=>$text) {
        if (!is_string($text) || (!in_array($name, ['pwm_labels.cfg','order.cfg'], true) && (!fcp_backup_fan_name($name) || preg_match('/^fanctrlplus2_temp_\d+\.cfg$/D', $name)))) throw new InvalidArgumentException('Unsupported backup filename or content.');
        // The Unraid boot filesystem is case-insensitive.
        $folded = strtolower($name);
        if (isset($filenames[$folded])) throw new InvalidArgumentException('Backup filenames collide on the boot filesystem.');
        $filenames[$folded] = true;
        if (fcp_backup_fan_name($name)) {
            $fan = fcp_validate_fan_config($text);
            if (isset($names[$fan['custom']])) throw new InvalidArgumentException('Duplicate fan name in backup.');
            $names[$fan['custom']] = true;
        } elseif ($name === 'order.cfg') {
            foreach (fcp_backup_assignments($text) as $key=>$target) {
                if (!preg_match('/^(?:order\d+|col\d+_\d+|left\d+|right\d+)$/D', $key) || !fcp_backup_fan_name($target)) throw new InvalidArgumentException('Invalid fan ordering.');
            }
        } else {
            foreach (explode("\n", $text) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                $pair = explode('=', $line, 2);
                if (count($pair) !== 2) throw new InvalidArgumentException('Invalid PWM label entry.');
                if (preg_match('/^__FCP_[A-Z0-9_]+__$/D', $pair[0])) {
                    if ($pair[1] !== '0' && $pair[1] !== '1') throw new InvalidArgumentException('Invalid dashboard flag.');
                } elseif ($pair[0] === '' || !fcp_backup_binding($pair[0])) { throw new InvalidArgumentException('Invalid PWM label identity.'); }
            }
        }
    }
    ksort($files);
    return $files;
}

// Caller callbacks stop/restart control only when it was running. They run
// while the same lock used by every HTTP settings writer remains held.
function fcp_restore_config(string $dir, string $json, ?callable $before = null, ?callable $after = null): int {
    $files = fcp_validate_config_backup($json);
    $lock = fcp_config_lock($dir);
    $stage = null; $moved = []; $installed = []; $stopped = false; $retain = false;
    try {
        $old = fcp_config_files($dir, true);
        $stage = "$dir/.restore-".bin2hex(random_bytes(8));
        if (!mkdir($stage, 0700) || !mkdir("$stage/new", 0700) || !mkdir("$stage/old", 0700)) throw new RuntimeException('Could not stage the restoration.');
        foreach ($files as $name=>$text) {
            if (file_put_contents("$stage/new/$name", $text, LOCK_EX) !== strlen($text)) throw new RuntimeException('Could not stage a configuration file.');
        }
        if ($before !== null) { $stopped = true; $before(); }
        foreach ($old as $name=>$text) {
            if (!rename("$dir/$name", "$stage/old/$name")) throw new RuntimeException('Could not preserve the previous configuration.');
            $moved[] = $name;
        }
        foreach ($files as $name=>$text) {
            if (!rename("$stage/new/$name", "$dir/$name")) throw new RuntimeException('Could not install the restored configuration.');
            $installed[] = $name;
        }
        if ($after !== null) $after();
        return count($files);
    } catch (Throwable $error) {
        foreach ($installed as $name) {
            if (file_exists("$dir/$name") && !@unlink("$dir/$name")) $retain = true;
        }
        foreach ($moved as $name) {
            if (!@rename("$stage/old/$name", "$dir/$name")) $retain = true;
        }
        if ($stopped && $after !== null) { try { $after(); } catch (Throwable $restartError) { /* Files remain recovered; report the original failure. */ } }
        if ($retain) throw new RuntimeException('Restore failed ('.$error->getMessage().'); previous files remain in '.$stage.'/old.', 0, $error);
        throw $error;
    } finally {
        if ($stage !== null && !$retain) {
            foreach (glob("$stage/*/*") ?: [] as $path) @unlink($path);
            @rmdir("$stage/new"); @rmdir("$stage/old"); @rmdir($stage);
        }
        flock($lock, LOCK_UN); fclose($lock);
    }
}
