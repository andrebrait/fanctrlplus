<?php

$sourceRoot = getenv('FCP_SOURCE_ROOT') ?: __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2';
$page = file_get_contents($sourceRoot . '/fanctrlplus2.page');
$failures = [];

$identifyStart = strpos($page, '>Identify PWM Controller</span>');
$generalStart = strpos($page, '>General Settings</span>');
$syslogStart = strpos($page, '>Syslog &amp; Alerts</span>');

if ($identifyStart === false || $generalStart === false || $syslogStart === false) {
  $failures[] = 'Identify, General Settings, and Syslog cards must all exist.';
} elseif (!($identifyStart < $generalStart && $generalStart < $syslogStart)) {
  $failures[] = 'General Settings must appear between Identify and Syslog settings.';
} else {
  $identifyCard = substr($page, $identifyStart, $generalStart - $identifyStart);
  $generalCard = substr($page, $generalStart, $syslogStart - $generalStart);

  if (str_contains($identifyCard, 'fcp-airflow-switch')) {
    $failures[] = 'The FCP Airflow switch must not remain in Identify PWM Controller.';
  }
  if (!str_contains($generalCard, 'id="fcp-airflow-switch"')) {
    $failures[] = 'General Settings must contain the FCP Airflow switch.';
  }
  if (!str_contains($generalCard, '<strong>How to use</strong>')) {
    $failures[] = 'General Settings must contain its own help control.';
  }
  if (!str_contains($generalCard, '<strong>Tile Management</strong>')) {
    $failures[] = 'The FCP Airflow Tile Management guidance must move to General Settings.';
  }
  if (str_contains($identifyCard, '<strong>Tile Management</strong>')) {
    $failures[] = 'FCP Airflow Tile Management guidance must leave Identify PWM Controller.';
  }
}

if (substr_count($page, 'id="fcp-airflow-switch"') !== 1) {
  $failures[] = 'The FCP Airflow switch must appear exactly once.';
}

if (substr_count($page, 'id="fcp-history-switch"') !== 1) {
  $failures[] = 'The History tile switch must appear exactly once.';
}
if ($generalStart !== false && $syslogStart !== false) {
  $generalCard = substr($page, $generalStart, $syslogStart - $generalStart);
  if (!str_contains($generalCard, 'id="fcp-history-switch"')) {
    $failures[] = 'General Settings must contain the History tile switch.';
  }
}
if (!str_contains($page, "op: 'fcp_history_toggle'")) {
  $failures[] = 'The History switch must persist through the fcp_history_toggle op.';
}
if (!str_contains($page, "hasClass('fcp-history-switch')")) {
  $failures[] = 'The click handler must persist the History switch.';
}
if (!str_contains($page, ".fcp-airflow-switch, .fcp-history-switch'")) {
  $failures[] = 'The change handler must cover the History switch.';
}
if (substr_count($page, 'saveHistorySwitchState') < 2) {
  $failures[] = 'saveHistorySwitchState must be defined and called.';
}

$css = file_get_contents($sourceRoot . '/css/fcp.base.css');
if (!preg_match('/\.fcp-history-switch\s*\{\s*display:\s*none/', $css)) {
  $failures[] = 'The raw History checkbox must be hidden (slider is the visible control).';
}

// The per-fan Footer switch (#45): off by default, saved by its own op, and
// read by the footer page and the endpoint it polls.
if (!str_contains($page, "'footer' => '0'") || !str_contains($page, "['syslog', 'notify', 'footer'].find(")) {
  $failures[] = 'The Footer switch must default to off and save through its own op.';
}
$logic = file_get_contents($sourceRoot . '/include/FanctrlLogic.php');
if (!preg_match("/case 'setfooter':\s*\\\$key = substr\(\\\$op, 3\);/", $logic)) {
  $failures[] = 'FanctrlLogic.php must save the Footer switch under the footer key.';
}
if (!str_contains($logic, "'setnotify','setfooter','saveorder'")) {
  $failures[] = 'setfooter must hold the configuration lock, like the other switches.';
}
$footerPage = file_get_contents($sourceRoot . '/FanctrlPlus2Footer.page');
$endpoint = file_get_contents($sourceRoot . '/Fanctrl2Dashboard.php');
if (!str_contains($footerPage, 'Menu="Buttons"') || !str_contains($footerPage, "showFooter(\$footer, 'fcp-footer')")
    || !str_contains($endpoint, "'footer'      => (\$cfg['footer'] ?? '0') !== '0'")) {
  $failures[] = 'The footer page must show the fans the endpoint flags with their Footer switch on.';
}
// showFooter() re-prepends; calling it on every poll swaps places with other footer entries.
if (!str_contains($footerPage, 'else if ($old.length) $old.replaceWith($footer);')) {
  $failures[] = 'The footer entry must be updated in place after it is first shown.';
}

// Monitor only (service="2", #45): offered, never run, and read live.
$block = file_get_contents($sourceRoot . '/include/FanBlockRender.php');
if (!str_contains($block, '<option value="2"') || !str_contains($block, "<?php if ((\$cfg['service'] ?? '') !== '2'): // A Monitor only fan is never set. ?>")) {
  $failures[] = 'Fan Control must offer Monitor only, and the settings page must not offer Run Now for it.';
}
$tile = file_get_contents($sourceRoot . '/FanctrlPlus2.Dashboard.page');
if (!str_contains($tile, "\$run = (\$cfg['service'] ?? '0') === '2' ? '' :")) {
  $failures[] = 'The Dashboard tile must not offer Manual Run for a Monitor only fan.';
}
if (!str_contains($endpoint, "\$monitor = (\$cfg['service'] ?? '0') === '2';")
    || !str_contains($endpoint, "fcp_monitor_reading((string)(\$cfg['controller'] ?? ''), \$identity, \$identities ?? null)")) {
  $failures[] = 'The Dashboard endpoint must read a Monitor only fan live from its controller.';
}
$history = file_get_contents($sourceRoot . '/FanctrlPlusHistory2.Dashboard.page');
if (!str_contains($history, "(\$cfg['service'] ?? '') === '2') continue;")) {
  $failures[] = 'The History tile must skip Monitor only fans, which record no history.';
}

$rc = file_get_contents($sourceRoot . '/scripts/rc.fanctrlplus2');
// Controllers go back only once the fan loops have exited, on stop and start.
foreach (['fanctrlplus2.stop() {', 'fanctrlplus2.start_now() {'] as $fn) {
  $body = substr($rc, (int)strpos($rc, $fn), 1500);
  $ended = strpos($body, 'fanctrlplus2.end_loops');
  $given = strpos($body, 'fcp_give_back_unused "$cfg_path"');
  if ($ended === false || $given === false || $ended > $given) $failures[] = "$fn must give controllers back after the fan loops end.";
}
if (!str_contains($rc, "pkill -KILL -f \"\$loop_script\"")) $failures[] = 'A fan loop that outlives the wait must be killed.';

if ($failures) {
  fwrite(STDERR, implode("\n", $failures) . "\n");
  exit(1);
}

echo "settings layout tests passed\n";
