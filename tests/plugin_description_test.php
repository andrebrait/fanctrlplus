<?php
// Unraid's plugin manager renders the README.md shipped inside the plugin
// directory as the plugin's description on the Plugins page:
//
//   $readme = "plugins/{$name}/README.md";
//   $desc = file_exists($readme) ? Markdown(file_get_contents($readme)) : ...
//
// so what is packaged has to be a description, not the project's README. The
// ones Unraid and other plugins ship run from 75 to about 260 bytes: the name
// in bold, then a sentence or two.

$root = dirname(__DIR__);
$failures = [];

$description = $root . '/plugin/description.md';
if (!is_file($description)) {
  $failures[] = 'The plugin must carry a short description to ship as its README.';
} else {
  $text = file_get_contents($description);
  $size = strlen($text);

  if ($size > 500) {
    $failures[] = "The packaged description is $size bytes; every other plugin's is under 300.";
  }
  if (!str_starts_with(trim($text), '**FanCtrl Plus 2**')) {
    $failures[] = 'The description must open with the plugin name in bold, as the others do.';
  }
  foreach (['<img', '<div', 'img.shields.io', '```'] as $markup) {
    if (str_contains($text, $markup)) {
      $failures[] = "The description must not carry \"$markup\": it is rendered into a table cell.";
    }
  }
}

// The workflow must package that file rather than the project README, which is
// how the whole thing ended up in the description in the first place.
$workflow = file_get_contents($root . '/.github/workflows/release.yml');
if (preg_match('/cp\s+README\.md\s/', $workflow)) {
  $failures[] = 'The release must not package the project README as the plugin description.';
}
if (!str_contains($workflow, 'plugin/description.md')) {
  $failures[] = 'The release must package plugin/description.md as the plugin README.';
}

if ($failures) {
  fwrite(STDERR, implode("\n", $failures) . "\n");
  fwrite(STDERR, count($failures) . " plugin description assertion(s) failed.\n");
  exit(1);
}
echo "plugin description tests passed.\n";
