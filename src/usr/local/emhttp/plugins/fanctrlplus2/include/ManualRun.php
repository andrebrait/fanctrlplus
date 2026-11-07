<?php
// Run Now. The manual-evaluation script runs to completion inside the request,
// so its exit status is the answer: a lock, setup or controller failure reaches
// the page, rather than being reported as success after a background spawn.

/**
 * Runs the manual evaluation for the fan named $custom, waiting for it to end.
 * $script is the script to run, by default the plugin's own.
 *
 * @return array{ok: bool, message: string} ok is the script's exit status being
 *         0; message is everything it printed, which on failure is the reason.
 */
function fcp_manual_run(string $custom, ?string $script = null): array {
    if ($custom === '' || strpos($custom, "\0") !== false) {
        return ['ok' => false, 'message' => 'The fan name is not valid.'];
    }
    $script ??= __DIR__ . '/../scripts/fanctrlplus2_refresh_single.sh';
    $output = [];
    $status = 1;
    exec('bash ' . escapeshellarg($script) . ' ' . escapeshellarg($custom) . ' 2>&1', $output, $status);
    return ['ok' => $status === 0, 'message' => trim(implode("\n", $output))];
}
