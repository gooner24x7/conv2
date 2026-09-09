<?php

declare(strict_types=1);

require __DIR__ . '/../src/Services/BoqParserService.php';
require __DIR__ . '/../src/Services/AitoolV3Classifier.php';

use BoqAllocator\Services\AitoolV3Classifier;
use BoqAllocator\Services\BoqParserService;

$boqPath = $argv[1] ?? '';
$convertedPath = $argv[2] ?? '';
$templatePath = $argv[3] ?? (__DIR__ . '/../templates/NRM2 template.csv');
$cachePath = $argv[4] ?? '';
if ($boqPath === '' || $convertedPath === '') {
    fwrite(STDERR, "Usage: php tests/nrm2-parity.php BOQ.xlsx NRM2_Converted_BOQ.xlsx [template.csv]\n");
    exit(2);
}

$parser = new BoqParserService();
$classifier = new AitoolV3Classifier();
$template = $parser->parseTemplate($templatePath);
$validCodes = [];
foreach ($template['tier2_map'] as $items) {
    foreach ($items as $item) $validCodes[(string)$item['code']] = true;
}

$converted = $parser->parseXlsx($convertedPath);
$models = [];
foreach ($converted['Decision Cache'] ?? [] as $row) {
    $recordId = (string)($row['B'] ?? '');
    if ($recordId !== '') $models[$recordId] = (string)($row['I'] ?? '');
}
$expected = [];
foreach ($converted['NRM2 Detail'] ?? [] as $row) {
    $excelRow = trim((string)($row['K'] ?? ''));
    if ($excelRow === '' || !is_numeric($excelRow)) continue;
    $recordId = 'Bill Items!' . (string)(int)$excelRow;
    $expected[$recordId] = [
        'code' => (string)($row['A'] ?? ''),
        'status' => (string)($row['S'] ?? ''),
        'confidence' => (float)($row['T'] ?? 0),
        'flags' => (string)($row['U'] ?? ''),
        'reason' => (string)($row['V'] ?? ''),
        'model' => $models[$recordId] ?? '',
    ];
}

$counts = ['AUTO' => 0, 'REVIEW' => 0, 'NO_WORK' => 0];
$mismatches = [];
$records = $parser->parseBoq($boqPath)['records'];
foreach ($records as $record) {
    $actual = $classifier->classify($record, 'nrm2-v1', $validCodes);
    $counts[$actual['status']] = ($counts[$actual['status']] ?? 0) + 1;
    $recordId = (string)$record['record_id'];
    $reference = $expected[$recordId] ?? null;
    if (!$reference || $reference['model'] !== 'LOCKED_RULE') continue;
    if (
        $actual['code'] !== $reference['code'] ||
        $actual['status'] !== $reference['status'] ||
        (float)$actual['confidence'] !== $reference['confidence'] ||
        implode('; ', $actual['flags'] ?? []) !== $reference['flags'] ||
        (string)($actual['reason'] ?? '') !== $reference['reason']
    ) {
        $mismatches[] = [
            'record_id' => $recordId,
            'description' => $record['description'],
            'expected' => array_diff_key($reference, ['model' => true]),
            'actual' => $actual,
        ];
    }
}

$result = [
    'source_records' => count($records),
    'deterministic_counts' => $counts,
    'locked_rule_records' => count(array_filter($expected, fn(array $row) => $row['model'] === 'LOCKED_RULE')),
    'locked_rule_mismatches' => count($mismatches),
    'mismatches' => array_slice($mismatches, 0, 25),
];

if ($cachePath !== '') {
    require __DIR__ . '/../src/Services/AiProviderService.php';
    require __DIR__ . '/../src/Services/DecisionCacheService.php';
    require __DIR__ . '/../src/Services/AitoolV3Pipeline.php';
    $pipeline = new BoqAllocator\Services\AitoolV3Pipeline($parser, $classifier, [
        'default_model' => 'gpt-5.6-luna',
        'api_keys' => ['openai' => ''],
        'decision_cache_path' => $cachePath,
    ]);
    $run = $pipeline->run($boqPath, $templatePath);
    $fullMismatches = [];
    foreach ($run['records'] as $record) {
        $reference = $expected[$record['record_id']] ?? null;
        $decision = $record['decision'];
        if (!$reference || $decision['code'] !== $reference['code'] || $decision['status'] !== $reference['status'] || (float)$decision['confidence'] !== $reference['confidence'] || implode('; ', $decision['flags'] ?? []) !== $reference['flags'] || (string)($decision['reason'] ?? '') !== $reference['reason']) {
            $fullMismatches[] = $record['record_id'];
        }
    }
    $result['pipeline'] = [
        'dictionary_version' => $run['dictionaryVersion'],
        'cache_hits' => $run['cache_hits'],
        'api_requested' => $run['api_requested'],
        'full_mismatches' => count($fullMismatches),
        'first_mismatches' => array_slice($fullMismatches, 0, 25),
    ];
    if ($fullMismatches) $mismatches[] = ['pipeline' => $fullMismatches];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($mismatches ? 1 : 0);
