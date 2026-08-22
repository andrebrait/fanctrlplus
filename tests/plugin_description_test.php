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
    $failures[] = "The packaged description is $size bytes; the limit is 500.";
  }
  if (!str_starts_with(trim($text), '**FanCtrl Plus 2**')) {
    $failures[] = 'The description must open with the plugin name in bold, as the others do.';
  }
  // Markdown images, any HTML element, and either fence style: the text lands
  // in a table cell, so anything that wants a block of its own does not belong.
  if (preg_match('/!\[[^\]]*\](?:\([^)]*\)|\[[^\]]*\])|<\s*[a-z][^>]*>|```|~~~/i', $text)) {
    $failures[] = 'The description must not contain images, HTML or code fences.';
  }
  if (str_contains($text, 'img.shields.io')) {
    $failures[] = 'The description must not carry badges.';
  }
}

// The workflow must package that file rather than the project README, which is
// how the whole thing ended up in the description in the first place.
$workflow = file_get_contents($root . '/.github/workflows/release.yml');
if (preg_match('/cp\s+README\.md\s/', $workflow)) {
  $failures[] = 'The release must not package the project README as the plugin description.';
}
// The whole command, not just the path: naming the file in a comment, or
// copying it somewhere other than the plugin's README, would both pass a
// looser check while leaving the description wrong.
if (!preg_match(
  '/^\s*cp\s+plugin\/description\.md\s+src\/usr\/local\/emhttp\/plugins\/fanctrlplus2\/README\.md\s*$/m',
  $workflow
)) {
  $failures[] = 'The release must copy plugin/description.md to the plugin README.';
}

if ($failures) {
  fwrite(STDERR, implode("\n", $failures) . "\n");
  fwrite(STDERR, count($failures) . " plugin description assertion(s) failed.\n");
  exit(1);
}
echo "plugin description tests passed.\n";
