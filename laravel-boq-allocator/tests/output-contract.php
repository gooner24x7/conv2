<?php

declare(strict_types=1);

require __DIR__ . '/../src/DTOs/AllocationResult.php';
require __DIR__ . '/../src/Services/BoqParserService.php';
require __DIR__ . '/../src/Services/AitoolV3Classifier.php';
require __DIR__ . '/../src/Services/BoqAllocationEngine.php';

use BoqAllocator\DTOs\AllocationResult;
use BoqAllocator\Services\BoqAllocationEngine;

$constructor = new ReflectionMethod(BoqAllocationEngine::class, '__construct');
$constructorParameters = array_map(
    static fn(ReflectionParameter $parameter): string => $parameter->getName(),
    $constructor->getParameters(),
);
if ($constructorParameters !== ['parser', 'config', 'classifier']) {
    throw new RuntimeException('BoqAllocationEngine constructor contract changed.');
}

$allocate = new ReflectionMethod(BoqAllocationEngine::class, 'allocate');
$actualParameters = array_map(
    static fn(ReflectionParameter $parameter): string => $parameter->getName(),
    $allocate->getParameters(),
);
$expectedParameters = ['boqPath', 'templatePath', 'modelKey', 'customPromptRules', 'progressCallback'];
if ($actualParameters !== $expectedParameters || $allocate->getReturnType()?->getName() !== AllocationResult::class) {
    throw new RuntimeException('BoqAllocationEngine::allocate public contract changed.');
}

$metadata = [
    'total_bills' => 1,
    'mapped_bills' => 1,
    'unmapped_bills' => 0,
    'packages_used' => 1,
    'engine' => 'GPT-5.6 Luna',
    'template' => 'NRM2 template.csv',
    'execution_time' => '0s',
    'overall_accuracy_score' => '100%',
    'avg_package_confidence' => '100%',
    'avg_trade_confidence' => '100%',
    'token_usage' => ['input' => 0, 'output' => 0, 'total' => 0],
    'estimated_cost' => '$0.00000',
];
$tree = [[
    'id' => 'ws_1',
    'name' => 'Section',
    'attributes' => ['package_type' => 'wd_template'],
    'children' => [[
        'id' => 'wi_1',
        'name' => 'Item',
        'attributes' => ['package_type' => 'tier2_item'],
        'children' => [[
            'id' => 'bill_1',
            'name' => 'Bill 1: Example',
            'attributes' => [
                'bill_number' => 1,
                'suggested_trade' => 'Item',
                'package_confidence' => 100,
                'trade_confidence' => 100,
                'ai_rationale' => 'Example',
            ],
            'source_evidence' => [],
        ]],
    ]],
]];
$array = (new AllocationResult($metadata, $tree))->toArray();
if (array_keys($array) !== ['metadata', 'work_packages']) {
    throw new RuntimeException('AllocationResult top-level JSON contract changed.');
}
if (array_keys($array['metadata']) !== array_keys($metadata)) {
    throw new RuntimeException('Allocation metadata contract changed.');
}
if (array_keys($array['metadata']['token_usage']) !== ['input', 'output', 'total']) {
    throw new RuntimeException('Token usage contract changed.');
}
if (array_keys($array['work_packages'][0]) !== ['id', 'name', 'attributes', 'children']) {
    throw new RuntimeException('Work package node contract changed.');
}
if (array_keys($array['work_packages'][0]['children'][0]) !== ['id', 'name', 'attributes', 'children']) {
    throw new RuntimeException('Tier-two node contract changed.');
}
$bill = $array['work_packages'][0]['children'][0]['children'][0];
if (array_keys($bill) !== ['id', 'name', 'attributes', 'source_evidence']) {
    throw new RuntimeException('Bill node contract changed.');
}
if (array_keys($bill['attributes']) !== ['bill_number', 'suggested_trade', 'package_confidence', 'trade_confidence', 'ai_rationale']) {
    throw new RuntimeException('Bill attributes contract changed.');
}

$cli = file_get_contents(__DIR__ . '/../bin/allocate.php');
if ($cli === false || !str_contains($cli, "getopt('', ['boq:', 'template:', 'output:', 'config::'])")) {
    throw new RuntimeException('Standalone CLI option contract changed.');
}

echo "Output contract OK\n";
