<?php

$sourceRoot = getenv('FCP_SOURCE_ROOT') ?: __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2';
$page = file_get_contents($sourceRoot . '/fanctrlplus2.page');
$failures = [];

$identifyStart = strpos($page, '>Identify PWM Controller</span>');
$generalStart = strpos($page, '>General Settings</span>');
$syslogStart = strpos($page, '>Syslog Setting</span>');

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

if ($failures) {
  fwrite(STDERR, implode("\n", $failures) . "\n");
  exit(1);
}

echo "settings layout tests passed\n";
