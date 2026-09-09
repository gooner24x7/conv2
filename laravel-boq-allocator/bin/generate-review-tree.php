<?php

declare(strict_types=1);

require __DIR__ . '/../src/Services/BoqParserService.php';
require __DIR__ . '/../src/Services/AitoolV3Classifier.php';
require __DIR__ . '/../src/Services/AiProviderService.php';
require __DIR__ . '/../src/Services/DecisionCacheService.php';
require __DIR__ . '/../src/Services/AitoolV3Pipeline.php';

use BoqAllocator\Services\AitoolV3Classifier;
use BoqAllocator\Services\AitoolV3Pipeline;
use BoqAllocator\Services\BoqParserService;

$options = getopt('', ['boq:', 'template:', 'output:', 'config::']);
$boqPath = (string)($options['boq'] ?? '');
$templatePath = (string)($options['template'] ?? '');
$outputPath = (string)($options['output'] ?? '');
if ($boqPath === '' || $templatePath === '' || $outputPath === '') {
    fwrite(STDERR, "Usage: php bin/generate-review-tree.php --boq=... --template=... --output=...\n");
    exit(2);
}

$external = [];
$configPath = (string)($options['config'] ?? '');
if ($configPath !== '') {
    $loaded = require $configPath;
    if (is_array($loaded)) $external = $loaded;
}
$config = [
    'default_model' => (string)($external['model'] ?? getenv('OPENAI_MODEL') ?: 'gpt-5.6-luna'),
    'api_keys' => ['openai' => (string)($external['openai_api_key'] ?? getenv('OPENAI_API_KEY') ?: '')],
    'max_records_per_call' => (int)($external['max_records_per_call'] ?? 100),
    'max_context_items_per_bill' => 20,
    'cost_cap_usd' => (float)($external['cost_cap_usd'] ?? .15),
    'decision_cache_path' => (string)($external['decision_cache_path'] ?? getenv('BOQ_DECISION_CACHE_PATH') ?: dirname($outputPath) . '/decision-cache.php'),
];
$parser = new BoqParserService();
$pipeline = new AitoolV3Pipeline($parser, new AitoolV3Classifier(), $config);
$run = $pipeline->run($boqPath, $templatePath, $config['default_model']);
$template = $run['template'];
$boq = $run['boq'];
$validCodes = [];
$nodesByCode = [];

if ($template['is_tiered']) {
    foreach ($template['list'] as $parent) {
        foreach ($template['tier2_map'][$parent['id']] ?? [] as $item) {
            $code = (string)$item['code'];
            $validCodes[$code] = true;
            $nodesByCode[$code] = ['parent'=>$parent['name'], 'name'=>$item['name']];
        }
    }
} else {
    foreach ($template['list'] as $package) {
        $code = (string)$package['code'];
        $validCodes[$code] = true;
        $nodesByCode[$code] = ['parent'=>'WD Works Packages', 'name'=>$package['name']];
    }
}

$linesByCode = [];
foreach ($run['records'] as $record) {
    $decision = $record['decision'];
    $code = (string)($decision['code'] ?? '');
    if ($decision['status'] !== 'AUTO' || $code === '' || !isset($nodesByCode[$code])) continue;
    $linesByCode[$code][] = [
        'item_id'=>(string)$record['item_id'], 'bill'=>(int)$record['bill'],
        'bill_name'=>(string)$record['bill_name'], 'section'=>(string)$record['section'],
        'description'=>(string)$record['description'], 'quantity'=>$record['quantity'] ?? '',
        'unit'=>(string)($record['unit'] ?? ''),
    ];
}

$isWd = str_starts_with($template['profile'], 'wd-work-packages-');
$groups = [];
foreach ($nodesByCode as $code => $node) {
    if (!isset($linesByCode[$code])) continue;
    if ($isWd) {
        $groups[$code] = ['code'=>$code, 'name'=>$node['name'], 'lines'=>$linesByCode[$code]];
    } else {
        $groups[$node['parent']][] = ['code'=>$code, 'name'=>$node['name'], 'lines'=>$linesByCode[$code]];
    }
}
$total = array_sum(array_map('count', $linesByCode));
$nodeCount = count($linesByCode);
$groupCount = count($groups);
$title = $isWd ? 'WD work-package allocation review' : ($template['profile'] === 'nrm2-v1' ? 'NRM2 work-item allocation review' : 'NRM1 cost-element allocation review');
$groupLabel = $template['profile'] === 'nrm2-v1' ? 'work sections' : 'group elements';
$nodeLabel = $isWd ? 'work packages' : ($template['profile'] === 'nrm2-v1' ? 'work items' : 'cost elements');
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

ob_start();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($title) ?></title>
<style>
:root{color-scheme:light dark;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}*{box-sizing:border-box}body{margin:0;background:Canvas;color:CanvasText}main{max-width:1180px;margin:auto;padding:24px}h1{font-size:1.5rem;margin:0 0 6px}.summary{color:GrayText;margin:0 0 18px}.toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}button{font:inherit;padding:7px 12px;border:1px solid ButtonBorder;border-radius:6px;background:ButtonFace;color:ButtonText;cursor:pointer}.group{border-top:1px solid GrayText}.group>summary{font-weight:600;padding:14px 4px}.item{margin:0 0 0 18px;border-left:2px solid GrayText;padding-left:12px}.item>summary{padding:10px 4px}.label{display:flex;gap:10px;align-items:baseline}.name{flex:1}.count{color:GrayText;white-space:nowrap}code{font-size:.9em}table{width:100%;border-collapse:collapse;margin:0 0 14px;font-size:.9rem}th,td{text-align:left;vertical-align:top;padding:7px 8px;border-bottom:1px solid GrayText}th{position:sticky;top:0;background:Canvas}.bill,.qty,.unit{white-space:nowrap}.description{min-width:320px}@media(max-width:700px){main{padding:14px}.table-wrap{overflow-x:auto}.item{margin-left:6px}.label{align-items:flex-start}.count{white-space:normal}}
</style>
</head>
<body>
<main>
<h1><?= $h($title) ?></h1>
<p class="summary"><?= number_format($total) ?> allocated bill lines · <?php if ($isWd): ?><?= number_format($nodeCount) ?> top-level <?= $h($nodeLabel) ?><?php else: ?><?= number_format($groupCount) ?> <?= $h($groupLabel) ?> · <?= number_format($nodeCount) ?> <?= $h($nodeLabel) ?><?php endif; ?></p>
<div class="toolbar"><button type="button" id="expand-groups">Expand <?= $isWd ? 'packages' : 'groups' ?></button><button type="button" id="expand-all">Expand all</button><button type="button" id="collapse-all">Collapse all</button></div>
<?php if ($isWd): ?>
<?php foreach ($groups as $package): ?>
<details class="group">
<summary><span class="label"><code><?= $h($package['code']) ?></code><span class="name"><?= $h($package['name']) ?></span><span class="count"><?= number_format(count($package['lines'])) ?> lines</span></span></summary>
<div class="table-wrap"><table>
<thead><tr><th>Item</th><th>Bill</th><th>Bill name</th><th>Section</th><th class="description">Description</th><th>Quantity</th><th>Unit</th></tr></thead>
<tbody>
<?php foreach ($package['lines'] as $line): ?>
<tr><td><?= $h($line['item_id']) ?></td><td class="bill"><?= $h($line['bill']) ?></td><td><?= $h($line['bill_name']) ?></td><td><?= $h($line['section']) ?></td><td class="description"><?= $h($line['description']) ?></td><td class="qty"><?= $h($line['quantity']) ?></td><td class="unit"><?= $h($line['unit']) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</details>
<?php endforeach; ?>
<?php else: ?>
<?php foreach ($groups as $groupName => $items): $groupTotal = array_sum(array_map(fn($item) => count($item['lines']), $items)); ?>
<details class="group">
<summary><span class="label"><span class="name"><?= $h($groupName) ?></span><span class="count"><?= count($items) ?> <?= $h($nodeLabel) ?> · <?= number_format($groupTotal) ?> lines</span></span></summary>
<?php foreach ($items as $item): ?>
<details class="item">
<summary><span class="label"><code><?= $h($item['code']) ?></code><span class="name"><?= $h($item['name']) ?></span><span class="count"><?= number_format(count($item['lines'])) ?> lines</span></span></summary>
<div class="table-wrap"><table>
<thead><tr><th>Item</th><th>Bill</th><th>Bill name</th><th>Section</th><th class="description">Description</th><th>Quantity</th><th>Unit</th></tr></thead>
<tbody>
<?php foreach ($item['lines'] as $line): ?>
<tr><td><?= $h($line['item_id']) ?></td><td class="bill"><?= $h($line['bill']) ?></td><td><?= $h($line['bill_name']) ?></td><td><?= $h($line['section']) ?></td><td class="description"><?= $h($line['description']) ?></td><td class="qty"><?= $h($line['quantity']) ?></td><td class="unit"><?= $h($line['unit']) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</details>
<?php endforeach; ?>
</details>
<?php endforeach; ?>
<?php endif; ?>
</main>
<script>
const all=()=>document.querySelectorAll('details');
document.getElementById('expand-groups').addEventListener('click',()=>{all().forEach(d=>d.open=d.classList.contains('group'))});
document.getElementById('expand-all').addEventListener('click',()=>all().forEach(d=>d.open=true));
document.getElementById('collapse-all').addEventListener('click',()=>all().forEach(d=>d.open=false));
</script>
</body>
</html>
<?php
$html = (string)ob_get_clean();
if (file_put_contents($outputPath, $html) === false) throw new RuntimeException("Could not write {$outputPath}");
echo json_encode(['output'=>$outputPath,'groups'=>$groupCount,'nodes'=>$nodeCount,'lines'=>$total,'bytes'=>strlen($html)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
