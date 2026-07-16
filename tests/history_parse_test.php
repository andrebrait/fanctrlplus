<?php

require_once __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2/include/Common.php';

$failures = [];

$point = fcp_parse_history_line('1730000000|disk:1|Disk: SSDs|43|127');
if ($point !== ['t' => 1730000000, 'src' => 'disk:1', 'label' => 'Disk: SSDs', 'temp' => 43, 'pwm' => 127]) {
    $failures[] = 'A valid disk-group line must parse into typed fields.';
}

$point = fcp_parse_history_line('1730000060|idle|Idle||51');
if ($point === null || $point['temp'] !== null || $point['pwm'] !== 51) {
    $failures[] = 'An idle line must parse with a null temperature.';
}

foreach ([
    'not a history line'                       => 'free text',
    '1730000000|disk:1|label|43'               => 'missing pwm field',
    '1730000000|ssd|label|43|127'              => 'unknown source key',
    'abc|cpu|CPU|43|127'                       => 'non-numeric epoch',
    '1730000000|cpu|CPU|hot|127'               => 'non-numeric temperature',
    '1730000000|cpu|CPU|43|999'                => 'pwm above 255',
    '1730000000|cpu|CPU|43|-5'                 => 'negative pwm',
    '1730000000|disk:|label|43|127'            => 'disk source without index',
] as $line => $why) {
    if (fcp_parse_history_line($line) !== null) {
        $failures[] = "Malformed line must be rejected ($why).";
    }
}

if (fcp_parse_history_line('1730000000|cpu|CPU|0|0') === null) {
    $failures[] = 'Zero temperature and zero pwm are valid values, not missing ones.';
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "history parse tests passed\n";
