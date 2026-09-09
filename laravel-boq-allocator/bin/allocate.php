<?php

declare(strict_types=1);

use BoqAllocator\Services\BoqAllocationEngine;

require __DIR__ . '/../src/DTOs/AllocationResult.php';
require __DIR__ . '/../src/Services/BoqParserService.php';
require __DIR__ . '/../src/Services/AitoolV3Classifier.php';
require __DIR__ . '/../src/Services/AiProviderService.php';
require __DIR__ . '/../src/Services/DecisionCacheService.php';
require __DIR__ . '/../src/Services/AitoolV3Pipeline.php';
require __DIR__ . '/../src/Services/BoqAllocationEngine.php';

$options = getopt('', ['boq:', 'template:', 'output:', 'config::']);
$boqPath = (string)($options['boq'] ?? '');
$templatePath = (string)($options['template'] ?? '');
$outputPath = (string)($options['output'] ?? '');

if ($boqPath === '' || $templatePath === '' || $outputPath === '') {
    fwrite(STDERR, "Usage: php bin/allocate.php --boq=... --template=... --output=... [--config=...]\n");
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
    'api_keys' => [
        'openai' => (string)($external['openai_api_key'] ?? getenv('OPENAI_API_KEY') ?: ''),
        'gemini' => '',
        'anthropic' => '',
    ],
    'batch_size' => 18,
    'max_records_per_call' => (int)($external['max_records_per_call'] ?? 100),
    'max_context_items_per_bill' => 20,
    'cost_cap_usd' => (float)($external['cost_cap_usd'] ?? .15),
    'decision_cache_path' => (string)($external['decision_cache_path'] ?? getenv('BOQ_DECISION_CACHE_PATH') ?: dirname($outputPath) . '/decision-cache.php'),
];

$engine = new BoqAllocationEngine(null, $config);
$result = $engine->allocate(
    $boqPath,
    $templatePath,
    $config['default_model'],
    null,
    static fn(string $message, int $percent) => fwrite(STDERR, "[{$percent}%] {$message}\n")
);

$directory = dirname($outputPath);
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    throw new RuntimeException("Could not create output directory: {$directory}");
}
if (file_put_contents($outputPath, $result->toJson()) === false) {
    throw new RuntimeException("Could not write allocation output: {$outputPath}");
}

echo json_encode($result->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
