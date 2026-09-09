<?php

require __DIR__ . '/../src/Services/BoqParserService.php';
require __DIR__ . '/../src/Services/AitoolV3Classifier.php';

use BoqAllocator\Services\AitoolV3Classifier;
use BoqAllocator\Services\BoqParserService;

$parser = new BoqParserService();
$classifier = new AitoolV3Classifier();
$templates = ['WD template.csv', 'NRM1 template.csv', 'NRM2 template.csv'];

foreach ($templates as $name) {
    $template = $parser->parseTemplate(__DIR__ . '/../templates/' . $name);
    $codes = [];
    if ($template['is_tiered']) {
        foreach ($template['tier2_map'] as $items) foreach ($items as $item) $codes[$item['code']] = true;
    } else {
        foreach ($template['list'] as $item) $codes[$item['code']] = true;
    }
    if (!$codes) throw new RuntimeException("No codes parsed for {$name}");
}

$cases = [
    ['wd-work-packages-v4', ['WD-12'=>true], 5, 'IKO Permatec hot melt waterproofing', 'WD-12'],
    ['nrm1-v1', ['8.6.2'=>true], 17, 'Inspection chamber and gully', '8.6.2'],
    ['nrm2-v1', ['34.6'=>true], 17, 'Construct manhole', '34.6'],
];
foreach ($cases as [$profile, $codes, $bill, $description, $expected]) {
    $decision = $classifier->classify(['bill'=>$bill,'bill_name'=>'','section'=>'','description'=>$description,'context'=>''], $profile, $codes);
    if ($decision['code'] !== $expected) throw new RuntimeException("{$profile}: expected {$expected}, got {$decision['code']}");
}

if (isset($argv[1])) {
    $boq = $parser->parseBoq($argv[1]);
    foreach ($templates as $name) {
        $template = $parser->parseTemplate(__DIR__ . '/../templates/' . $name);
        $codes = [];
        if ($template['is_tiered']) foreach ($template['tier2_map'] as $items) foreach ($items as $item) $codes[$item['code']] = true;
        else foreach ($template['list'] as $item) $codes[$item['code']] = true;
        foreach ($boq['records'] as $record) {
            $decision = $classifier->classify($record, $template['profile'], $codes);
            if ($decision['code'] !== '' && !isset($codes[$decision['code']])) throw new RuntimeException("Invalid {$template['profile']} code {$decision['code']}");
        }
    }
    echo count($boq['records']) . " representative BoQ rows validated across all profiles.\n";
}

echo "AITOOLV3 compatibility smoke checks passed.\n";
