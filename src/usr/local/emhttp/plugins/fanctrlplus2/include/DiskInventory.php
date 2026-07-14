<?php

function fcp_load_disk_state(array $paths): array {
  foreach ($paths as $path) {
    if (!is_readable($path)) continue;
    $state = @parse_ini_file($path, true, INI_SCANNER_RAW);
    if (is_array($state)) return $state;
  }
  return [];
}

function fcp_base_device(string $device): string {
  $device = trim($device);
  if ($device === '') return '';
  if (!str_starts_with($device, '/dev/')) $device = "/dev/$device";

  $real = realpath($device);
  if ($real !== false) $device = $real;

  foreach ([
    '#^(/dev/nvme\d+n\d+)p\d+$#',
    '#^(/dev/mmcblk\d+)p\d+$#',
    '#^(/dev/(?:sd|vd|xvd)[a-z]+)\d+$#',
  ] as $pattern) {
    if (preg_match($pattern, $device, $match)) return $match[1];
  }

  return $device;
}

function fcp_scan_by_id(): array {
  $records = [];
  foreach (glob('/dev/disk/by-id/*') ?: [] as $path) {
    $id = basename($path);
    if (!is_link($path) || preg_match('/-part\d+$/', $id) || str_starts_with($id, 'dm-')) continue;
    $real = realpath($path);
    if ($real === false) continue;
    $records[] = ['id' => $id, 'dev' => fcp_base_device($real)];
  }
  return $records;
}

function fcp_alias_without_transport(string $id): string {
  return preg_replace('/^(?:ata|nvme|scsi|usb)-/', '', $id);
}

function fcp_alias_score(string $id, string $unraidId): int {
  $plain = fcp_alias_without_transport($id);
  if ($unraidId !== '' && ($id === $unraidId || $plain === $unraidId)) return 0;
  if (str_starts_with($id, 'ata-')) return 10;
  if (str_starts_with($id, 'nvme-') && !preg_match('/^nvme-(?:eui|uuid)\./', $id)) return 20;
  if (str_starts_with($id, 'scsi-')) return 30;
  if (str_starts_with($id, 'usb-')) return 40;
  if (str_starts_with($id, 'wwn-')) return 50;
  return 60;
}

function fcp_preferred_alias(array $aliases, string $unraidId): string {
  usort($aliases, function ($left, $right) use ($unraidId) {
    return fcp_alias_score($left, $unraidId) <=> fcp_alias_score($right, $unraidId)
      ?: strlen($left) <=> strlen($right)
      ?: strnatcasecmp($left, $right);
  });
  return $aliases[0] ?? '';
}

function fcp_unraid_disk_name(string $name): string {
  $name = trim($name);
  if ($name === '') return 'Device';
  return ucfirst(preg_replace('/(\d+)$/', ' $1', $name));
}

function fcp_disk_member_name(string $type, string $name, bool $unassigned): string {
  if ($unassigned) return 'Unassigned';
  if ($type !== 'Cache') return fcp_unraid_disk_name($name);

  $pool = preg_replace('/(\d+$|~.*$)/', '', $name);
  $suffix = substr($name, strlen($pool));
  $number = ctype_digit($suffix) ? max(1, intval($suffix)) : 1;
  return "Device $number";
}

function fcp_format_disk_size(array $disk): string {
  $sectors = $disk['sectors'] ?? null;
  $sectorSize = $disk['sector_size'] ?? 512;
  if (!is_numeric($sectors) || !is_numeric($sectorSize)) return '';

  $size = (float)$sectors * (float)$sectorSize;
  $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  $unit = 0;
  while ($size >= 1000 && $unit < count($units) - 1) {
    $size /= 1000;
    $unit++;
  }
  $precision = $size >= 100 || floor($size) === $size ? 0 : ($size >= 10 ? 1 : 2);
  $formatted = number_format($size, $precision, '.', '');
  if ($precision > 0) $formatted = rtrim(rtrim($formatted, '0'), '.');
  return $formatted . ' ' . $units[$unit];
}

function fcp_unraid_device_id(string $id): string {
  $id = trim($id);
  $wwn = substr($id, -18);
  if (substr($wwn, 0, 2) === '_3' && !preg_match('/.[_-]/', $wwn)) {
    return substr($id, 0, strlen($id) - 18);
  }
  return $id;
}

function fcp_disk_icon_class(array $disk, string $device): string {
  $type = $disk['type'] ?? '';
  if ($type === 'Boot' || $type === 'Flash') return 'fa fa-usb';

  $rotational = (string)($disk['rotational'] ?? '');
  if ($rotational === '0' || str_starts_with(basename($device), 'nvme')) {
    return 'icon-nvme';
  }
  if (!empty($disk['luksState'])) return 'icon-disk-encrypted';
  return 'icon-disk';
}

function fcp_disk_group_name(string $type, string $name): string {
  return match ($type) {
    'Parity', 'Data' => 'Array',
    'Cache' => 'Pool: ' . fcp_unraid_disk_name(preg_replace('/(\d+$|~.*$)/', '', $name)),
    'Unassigned' => 'Unassigned Devices',
    default => 'Other Devices',
  };
}

function fcp_disk_type_label(string $type, string $name): string {
  return match ($type) {
    'Parity' => 'Array parity',
    'Data' => 'Array data',
    'Cache' => 'Pool ' . preg_replace('/(\d+$|~.*$)/', '', $name),
    'Unassigned' => 'Unassigned device',
    default => 'Other device',
  };
}

function list_valid_disks_by_id(
  ?array $disks = null,
  ?array $devs = null,
  ?array $byIdRecords = null,
  ?string $bootDevice = null
): array {
  $disks ??= fcp_load_disk_state([
    '/var/local/emhttp/disks.ini',
    '/usr/local/emhttp/state/disks.ini',
  ]);
  $devs ??= fcp_load_disk_state([
    '/var/local/emhttp/devs.ini',
    '/usr/local/emhttp/state/devs.ini',
  ]);
  $byIdRecords ??= fcp_scan_by_id();
  $bootDevice ??= trim((string)shell_exec('findmnt -n -o SOURCE --target /boot 2>/dev/null'));
  $bootBase = fcp_base_device($bootDevice);

  $aliasesByDevice = [];
  foreach ($byIdRecords as $record) {
    $id = basename((string)($record['id'] ?? ''));
    $device = fcp_base_device((string)($record['dev'] ?? ''));
    if ($id === '' || $device === '' || preg_match('/-part\d+$/', $id) || str_starts_with($id, 'dm-')) continue;
    $aliasesByDevice[$device][] = $id;
  }
  foreach ($aliasesByDevice as &$aliases) {
    $aliases = array_values(array_unique($aliases));
  }
  unset($aliases);

  $groups = [];
  $seenDevices = [];
  $excludedDevices = $bootBase !== '' ? [$bootBase => true] : [];

  $append = function (string $section, array $disk, bool $unassigned) use (&$groups, &$seenDevices, &$excludedDevices, $aliasesByDevice) {
    $name = trim((string)($disk['name'] ?? $section));
    $type = $unassigned ? 'Unassigned' : trim((string)($disk['type'] ?? ''));
    $device = fcp_base_device((string)($disk['device'] ?? ''));
    if ($device === '') return;

    if ($type === 'Boot' || $type === 'Flash') {
      $excludedDevices[$device] = true;
      return;
    }
    if (isset($excludedDevices[$device]) || isset($seenDevices[$device])) return;

    $aliases = $aliasesByDevice[$device] ?? [];
    if (!$aliases) return;

    $unraidId = trim((string)($disk['id'] ?? ''));
    $preferred = fcp_preferred_alias($aliases, $unraidId);
    if ($preferred === '') return;

    $displayName = fcp_disk_member_name($type, $name, $unassigned);
    $displayId = fcp_unraid_device_id($unraidId ?: fcp_alias_without_transport($preferred));
    $deviceName = basename($device);
    $size = fcp_format_disk_size($disk);
    $description = $displayId . ($size !== '' ? " - $size" : '') . " ($deviceName)";
    $label = "$displayName — $description";
    $group = fcp_disk_group_name($type, $name);
    $typeLabel = fcp_disk_type_label($type, $name);

    $groups[$group][] = [
      'id' => $preferred,
      'aliases' => $aliases,
      'dev' => $device,
      'label' => $label,
      'name' => $displayName,
      'description' => $description,
      'title' => "$displayName\n$typeLabel\n$description\n$device",
      'type' => $typeLabel,
      'icon' => fcp_disk_icon_class($disk, $device),
      'sort_name' => $name,
      'sort_type' => $type,
    ];
    $seenDevices[$device] = true;
  };

  foreach ($disks as $section => $disk) {
    if (is_array($disk)) $append((string)$section, $disk, false);
  }
  foreach ($devs as $section => $disk) {
    if (is_array($disk)) $append((string)$section, $disk, true);
  }

  foreach ($aliasesByDevice as $device => $aliases) {
    if (isset($seenDevices[$device]) || isset($excludedDevices[$device])) continue;
    $preferred = fcp_preferred_alias($aliases, '');
    $displayId = fcp_unraid_device_id(fcp_alias_without_transport($preferred));
    $deviceName = basename($device);
    $groups['Other Devices'][] = [
      'id' => $preferred,
      'aliases' => $aliases,
      'dev' => $device,
      'label' => "$displayId ($deviceName)",
      'name' => 'Other device',
      'description' => "$displayId ($deviceName)",
      'title' => "Other device\n$displayId\n$device",
      'type' => 'Other device',
      'icon' => fcp_disk_icon_class([], $device),
      'sort_name' => $displayId,
      'sort_type' => 'Other',
    ];
  }

  foreach ($groups as $group => &$entries) {
    usort($entries, function ($left, $right) use ($group) {
      if ($group === 'Array') {
        $rank = ['Parity' => 0, 'Data' => 1];
        $typeOrder = ($rank[$left['sort_type']] ?? 2) <=> ($rank[$right['sort_type']] ?? 2);
        if ($typeOrder !== 0) return $typeOrder;
      }
      return strnatcasecmp($left['sort_name'], $right['sort_name']);
    });
  }
  unset($entries);

  uksort($groups, function ($left, $right) {
    $rank = fn($name) => $name === 'Array' ? 0
      : (str_starts_with($name, 'Pool: ') ? 1
      : ($name === 'Unassigned Devices' ? 2 : 3));
    return $rank($left) <=> $rank($right) ?: strnatcasecmp($left, $right);
  });

  return $groups;
}
