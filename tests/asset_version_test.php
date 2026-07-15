<?php

require_once __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2/include/Common.php';

$failures = [];
$pluginRoot = __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2';
$relative = 'css/fcp.base.css';
$expectedVersion = substr(hash_file('sha256', "$pluginRoot/$relative"), 0, 16);

if (fcp_asset_version($relative) !== $expectedVersion) {
    $failures[] = 'Asset versions must use the first 16 characters of the content SHA-256.';
}
if (fcp_asset_url($relative) !== "/plugins/fanctrlplus2/$relative?v=$expectedVersion") {
    $failures[] = 'Asset URLs must contain the content-hash cache key.';
}
if (fcp_asset_version('../outside') !== 'missing') {
    $failures[] = 'Asset version paths must reject parent-directory traversal.';
}
if (!in_array('include/asset-version.js', fcp_ui_asset_paths(), true)) {
    $failures[] = 'The asset monitor must participate in the aggregate UI version.';
}
if (!preg_match('/^[a-f0-9]{16}$/', fcp_ui_asset_version())) {
    $failures[] = 'The aggregate UI version must be a short SHA-256 value.';
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "asset version tests passed\n";
