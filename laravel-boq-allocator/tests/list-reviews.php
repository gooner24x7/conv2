<?php

require __DIR__ . '/../src/Services/BoqParserService.php';
require __DIR__ . '/../src/Services/AitoolV3Classifier.php';

use BoqAllocator\Services\AitoolV3Classifier;
use BoqAllocator\Services\BoqParserService;

$boqPath = $argv[1] ?? '';
$templatePath = $argv[2] ?? '';
if ($boqPath === '' || $templatePath === '') exit(2);

$parser = new BoqParserService();
$classifier = new AitoolV3Classifier();
$template = $parser->parseTemplate($templatePath);
$codes = [];
foreach ($template['tier2_map'] as $items) {
    foreach ($items as $item) $codes[$item['code']] = $item['name'];
}

$boq = $parser->parseBoq($boqPath);
$reviewNumber = 0;
foreach ($boq['records'] as $record) {
    $decision = $classifier->classify($record, $template['profile'], array_fill_keys(array_keys($codes), true));
    if ($decision['status'] !== 'REVIEW') continue;
    $reviewNumber++;
    echo json_encode([
        'number' => $reviewNumber,
        'item_id' => $record['item_id'],
        'bill' => $record['bill'],
        'bill_name' => $record['bill_name'],
        'section' => $record['section'],
        'description' => $record['description'],
        'suggested_code' => $decision['code'],
        'suggested_item' => $codes[$decision['code']] ?? '',
        'alternatives' => array_map(fn(string $code) => ['code'=>$code, 'item'=>$codes[$code] ?? ''], $decision['alternatives']),
        'reason' => $decision['reason'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
