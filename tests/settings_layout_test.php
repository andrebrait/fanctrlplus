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
$footerPage = file_get_contents($sourceRoot . '/FanctrlPlus2Footer.page');
$endpoint = file_get_contents($sourceRoot . '/Fanctrl2Dashboard.php');
if (!str_contains($footerPage, 'Menu="Buttons"') || !str_contains($footerPage, "showFooter(\$footer, 'fcp-footer')")
    || !str_contains($endpoint, "'footer'      => (\$cfg['footer'] ?? '0') !== '0'")) {
  $failures[] = 'The footer page must show the fans the endpoint flags with their Footer switch on.';
}

if ($failures) {
  fwrite(STDERR, implode("\n", $failures) . "\n");
  exit(1);
}

echo "settings layout tests passed\n";
