<?php

require_once __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2/include/DiskInventory.php';
require_once __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2/include/FanBlockRender.php';

function expect_same($expected, $actual, string $message): void {
  if ($expected === $actual) return;
  fwrite(STDERR, "$message\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
  exit(1);
}

$disks = [
  'parity' => [
    'name' => 'parity', 'type' => 'Parity', 'id' => 'PARITY_ID',
    'device' => 'sda', 'rotational' => '1', 'sectors' => '1000000', 'sector_size' => '512',
  ],
  'disk1' => [
    'name' => 'disk1', 'type' => 'Data', 'id' => 'DATA_ID',
    'device' => 'sdb', 'rotational' => '1', 'sectors' => '2000000', 'sector_size' => '512',
  ],
  'apps-pool' => [
    'name' => 'apps-pool', 'type' => 'Cache', 'id' => 'WD_Red_SA500_2TB_ONE',
    'device' => 'sdc', 'rotational' => '0', 'sectors' => '3907029168', 'sector_size' => '512',
  ],
  'apps-pool2' => [
    'name' => 'apps-pool2', 'type' => 'Cache', 'id' => 'WD_Red_SA500_2TB_TWO',
    'device' => 'sdd', 'rotational' => '0', 'sectors' => '3907029168', 'sector_size' => '512',
  ],
  'flash' => [
    'name' => 'flash', 'type' => 'Flash', 'id' => 'BOOT_ID',
    'device' => 'sdz', 'rotational' => '0',
  ],
];

$devs = [
  'dev1' => [
    'name' => 'dev1', 'id' => 'UNASSIGNED_ID', 'device' => 'sde',
    'rotational' => '1', 'sectors' => '4000000', 'sector_size' => '512',
  ],
];

$byIdRecords = [
  ['id' => 'wwn-parity', 'dev' => '/dev/sda'],
  ['id' => 'ata-PARITY_ID', 'dev' => '/dev/sda'],
  ['id' => 'wwn-data', 'dev' => '/dev/sdb'],
  ['id' => 'ata-DATA_ID', 'dev' => '/dev/sdb'],
  ['id' => 'ata-WD_Red_SA500_2TB_ONE', 'dev' => '/dev/sdc'],
  ['id' => 'ata-WD_Red_SA500_2TB_TWO', 'dev' => '/dev/sdd'],
  ['id' => 'usb-BOOT_ID', 'dev' => '/dev/sdz'],
  ['id' => 'ata-UNASSIGNED_ID', 'dev' => '/dev/sde'],
  ['id' => 'dm-name-sdc1', 'dev' => '/dev/dm-0'],
  ['id' => 'dm-uuid-CRYPT-LUKS1-example', 'dev' => '/dev/dm-0'],
];

$groups = list_valid_disks_by_id($disks, $devs, $byIdRecords, '/dev/sdz1');

expect_same(['Array', 'Pool: Apps-pool', 'Unassigned Devices'], array_keys($groups), 'Groups must follow Unraid assignments.');
expect_same(['Parity', 'Disk 1'], array_column($groups['Array'], 'name'), 'Array devices must use Unraid slot names.');
expect_same(['Device 1', 'Device 2'], array_column($groups['Pool: Apps-pool'], 'name'), 'Pool members must use Unraid member names.');
expect_same('icon-nvme', $groups['Pool: Apps-pool'][0]['icon'], 'Non-rotational pool members must use the Unraid NVMe/SSD icon.');
expect_same('WD_Red_SA500_2TB_ONE - 2 TB (sdc)', $groups['Pool: Apps-pool'][0]['description'], 'Device descriptions must match the Unraid format.');
expect_same('ata-DATA_ID', $groups['Array'][1]['id'], 'The model-based by-id alias must be preferred over WWN aliases.');
expect_same(['wwn-data', 'ata-DATA_ID'], $groups['Array'][1]['aliases'], 'All aliases must remain available for old config matching.');
expect_same(false, isset($groups['Other Devices']), 'Device-mapper aliases must not duplicate physical pool disks.');

$row = render_disk_group_row(0, 0, [
  'name' => 'Existing', 'disks' => ['wwn-data'], 'low' => 35, 'high' => 45,
], $groups);

if (!preg_match('/value="ata-DATA_ID"\s+selected/', $row)) {
  fwrite(STDERR, "A configuration using an old by-id alias must remain selected.\n");
  exit(1);
}
if (!str_contains($row, 'data-icon="icon-nvme"')) {
  fwrite(STDERR, "Rendered options must expose Unraid icon metadata.\n");
  exit(1);
}

echo "disk inventory tests passed\n";
