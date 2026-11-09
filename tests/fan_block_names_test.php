<?php
// Each fan block is numbered and named as one fan header, with examples.
$sourceRoot = __DIR__ . '/../src/usr/local/emhttp/plugins/fanctrlplus2';
$failures = [];
$check = function ($condition, string $message) use (&$failures) {
    if (!$condition) $failures[] = $message;
};

$render = file_get_contents("$sourceRoot/include/FanBlockRender.php");
$check(str_contains($render, '<legend class="fcp-fan-legend">Fan <span class="fcp-fan-number"></span></legend>'), 'Every block must be headed "Fan N".');
$check(str_contains($render, 'Fan <span class="fcp-fan-number"></span> name'), 'The name field must read "Fan N name".');
$check(str_contains($render, 'One block drives one fan header (PWM channel)'), 'The name tooltip must say what a block is.');
$check(preg_match('/title="[^"]*ex\. HDDBayFan or RearFan[^"]*"/', $render) === 1, 'The name tooltip must give example names.');
$check(str_contains($render, "['HDDBayFan', 'RearFan'][\$i] ?? 'FrontFan'"), 'Empty name fields must show example names as placeholders.');

$css = file_get_contents("$sourceRoot/css/fcp.base.css");
$check(preg_match('/#fan-area \{[^}]*counter-reset: fcp-fan;/', $css) === 1, 'The page must reset the fan counter.');
$check(preg_match('/\.fan-block \{[^}]*counter-increment: fcp-fan;/', $css) === 1, 'Each block must count itself, so numbers follow reordering.');
$check(str_contains($css, '.fcp-fan-number::after { content: counter(fcp-fan); }'), 'The number must come from the counter.');

$page = file_get_contents("$sourceRoot/fanctrlplus2.page");
$check(str_contains($page, "addFan({ example: 1 }).then(() => addFan({ example: 1 }))"), 'An empty page must show two example blocks, one after the other.');
$check(str_contains($page, 'title="Add a block for another fan header, ex.'), 'Add Fan must say what it adds, with an example.');
$check(!str_contains($page, 'Custom Name'), 'The page must not call the field Custom Name any more.');

$logic = file_get_contents("$sourceRoot/include/FanctrlLogic.php");
$check(str_contains($logic, "\$service = (\$_REQUEST['example'] ?? '') === '1' ? '0' : '1';"), 'Example blocks must start switched off; Add Fan keeps its default.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "fan block name tests passed\n";
