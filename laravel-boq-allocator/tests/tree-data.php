<?php

require __DIR__ . '/../src/Services/BoqParserService.php';
require __DIR__ . '/../src/Services/AitoolV3Classifier.php';

use BoqAllocator\Services\AitoolV3Classifier;
use BoqAllocator\Services\BoqParserService;

$parser = new BoqParserService();
$classifier = new AitoolV3Classifier();
$template = $parser->parseTemplate($argv[2]);
$valid = [];
$itemsByCode = [];
foreach ($template['list'] as $section) {
    foreach ($template['tier2_map'][$section['id']] ?? [] as $item) {
        $valid[$item['code']] = true;
        $itemsByCode[$item['code']] = ['section'=>$section['name'], 'item'=>$item['name']];
    }
}
if (!$template['is_tiered']) {
    foreach ($template['list'] as $package) {
        $valid[$package['code']] = true;
        $itemsByCode[$package['code']] = ['section'=>'WD Works Packages', 'item'=>$package['name']];
    }
}
$counts = [];
foreach ($parser->parseBoq($argv[1])['records'] as $record) {
    $decision = $classifier->classify($record, $template['profile'], $valid);
    if ($decision['status'] === 'AUTO' && $decision['code'] !== '') $counts[$decision['code']] = ($counts[$decision['code']] ?? 0) + 1;
}
$tree = [];
foreach ($counts as $code => $count) {
    $meta = $itemsByCode[$code];
    $tree[$meta['section']][] = ['code'=>$code, 'name'=>$meta['item'], 'count'=>$count];
}
foreach ($tree as &$items) usort($items, fn($a,$b)=>strnatcasecmp($a['code'],$b['code']));
unset($items);
echo json_encode(['total'=>array_sum($counts),'sections'=>count($tree),'items'=>count($counts),'tree'=>$tree], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
