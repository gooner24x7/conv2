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

$profileName = strtolower((string)($argv[1] ?? ''));
$boqPath = (string)($argv[2] ?? '');
$cachePath = (string)($argv[3] ?? (__DIR__ . '/../output/decision-cache.php'));
$profiles = [
    'nrm1' => [
        'template' => __DIR__ . '/../templates/NRM1 template.csv',
        'profile' => 'nrm1-v1',
        'dictionary_version' => 'NRM1-template-sha256:aac91ec9df88f6af067f40b5b177673e6bb6a997960dfc2852dd81269214b5aa',
        'template_items' => 202,
        'records' => 1759,
        'counts' => ['AUTO' => 1726, 'NO_WORK' => 8, 'REVIEW' => 25],
        'deterministic_hash' => 'b264a1940de7ac0027bb89cc1d42c4415e1c652f123a9e2519f89f1118939491',
    ],
    'wd' => [
        'template' => __DIR__ . '/../templates/WD template.csv',
        'profile' => 'wd-work-packages-v4',
        'dictionary_version' => 'WD-template-sha256:1488cd8166537aa1d4b4978179339e45c54f6beb358d8d91f0ddd71c06d7dd66',
        'template_items' => 26,
        'mapping_rules' => 130,
        'records' => 1759,
        'counts' => ['AUTO' => 1112, 'NO_WORK' => 8, 'REVIEW' => 7, 'UNALLOCATED' => 632],
        'deterministic_hash' => '0df1d42176f969dbdfce83bc1437aab6b95bed5b108eca5e8d0cda5aae87a6d9',
        'pipeline_counts' => ['AUTO' => 1113, 'NO_WORK' => 8, 'REVIEW' => 3, 'UNALLOCATED' => 635],
        'pipeline_hash' => '75b546a9619358884d42975e5637be4bd6fa4e006cbdb2ff053f20a3b93ee8e8',
    ],
];
if (!isset($profiles[$profileName]) || $boqPath === '') {
    fwrite(STDERR, "Usage: php tests/profile-parity.php nrm1|wd BOQ.xlsx [decision-cache.php]\n");
    exit(2);
}

$expected = $profiles[$profileName];
$parser = new BoqParserService();
$classifier = new AitoolV3Classifier();
$template = $parser->parseTemplate($expected['template']);
$pipeline = new AitoolV3Pipeline($parser, $classifier);
$reflection = new ReflectionClass($pipeline);
$buildDictionary = $reflection->getMethod('buildDictionary');
$dictionaryVersion = $reflection->getMethod('dictionaryVersion');
[$dictionary] = $buildDictionary->invoke($pipeline, $template);
$actualVersion = $dictionaryVersion->invoke($pipeline, $template['profile'], $dictionary);
$validCodes = array_fill_keys(array_keys($dictionary), true);
$records = $classifier->annotateRecords($parser->parseBoq($boqPath)['records'], $template['profile']);

$canonicalLines = [];
$counts = [];
foreach ($records as $record) {
    $decision = $classifier->classify($record, $template['profile'], $validCodes);
    $counts[$decision['status']] = ($counts[$decision['status']] ?? 0) + 1;
    $canonicalLines[] = json_encode([
        $record['record_id'], $decision['code'], $decision['status'], (float)$decision['confidence'],
        array_values($decision['flags'] ?? []), $decision['reason'] ?? '', array_values($decision['alternatives'] ?? []),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
ksort($counts);
$result = [
    'profile' => $profileName,
    'dictionary_version' => $actualVersion,
    'template_items' => count($dictionary),
    'mapping_rules' => $profileName === 'wd' ? count($classifier->wdMappingRules()) : null,
    'source_records' => count($records),
    'deterministic_counts' => $counts,
    'deterministic_hash' => hash('sha256', implode("\n", $canonicalLines)),
];
$failures = [];
foreach (['dictionary_version', 'template_items', 'source_records', 'deterministic_counts', 'deterministic_hash'] as $field) {
    $expectedField = $field === 'source_records' ? 'records' : ($field === 'deterministic_counts' ? 'counts' : $field);
    if ($result[$field] !== $expected[$expectedField]) $failures[] = $field;
}
if ($profileName === 'wd' && $result['mapping_rules'] !== $expected['mapping_rules']) $failures[] = 'mapping_rules';

if ($profileName === 'wd' && !$failures) {
    $cachedPipeline = new AitoolV3Pipeline($parser, $classifier, [
        'default_model' => 'gpt-5.6-luna', 'api_keys' => ['openai' => ''], 'decision_cache_path' => $cachePath,
    ]);
    $run = $cachedPipeline->run($boqPath, $expected['template']);
    $pipelineLines = [];
    $pipelineCounts = [];
    foreach ($run['records'] as $record) {
        $decision = $record['decision'];
        $pipelineCounts[$decision['status']] = ($pipelineCounts[$decision['status']] ?? 0) + 1;
        $pipelineLines[] = json_encode([
            $record['record_id'], $decision['code'], $decision['status'], (float)$decision['confidence'],
            array_values($decision['flags'] ?? []), $decision['reason'] ?? '', array_values($decision['alternatives'] ?? []),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    ksort($pipelineCounts);
    $result['pipeline_counts'] = $pipelineCounts;
    $result['pipeline_hash'] = hash('sha256', implode("\n", $pipelineLines));
    $result['cache_hits'] = $run['cache_hits'];
    $result['api_requested'] = $run['api_requested'];
    foreach (['pipeline_counts', 'pipeline_hash'] as $field) if ($result[$field] !== $expected[$field]) $failures[] = $field;
    if ($run['cache_hits'] !== 7 || $run['api_requested'] !== 0) $failures[] = 'cached_review_coverage';
}

$result['status'] = $failures ? 'FAIL' : 'PASS';
$result['failures'] = $failures;
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($failures ? 1 : 0);
