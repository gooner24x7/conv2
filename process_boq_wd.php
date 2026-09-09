<?php
/**
 * Universal BoQ to Works Package Allocation Pipeline
 * Multi-Engine Architecture with Dual-Confidence Scoring & Web-Trigger Support
 * 
 * NOTE: This is now a lightweight runner utilizing the clean PSR-4 core from 
 *       laravel-boq-allocator/src/
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$globalStartTime = microtime(true);

// Disable PHP execution time limit for long AI requests
set_time_limit(0);
ini_set('max_execution_time', '0');
ignore_user_abort(false);

// Handle output buffering for real-time web streaming
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    if (ob_get_level()) ob_end_clean();
    ob_implicit_flush(true);
}

function emitStatus(string $msg, int $progressPercent = -1) {
    $time = date('H:i:s');
    $line = "[$time] $msg\n";
    echo $line;
    if (php_sapi_name() !== 'cli') {
        flush();
    }
}

// Simple .env loader for standalone usage
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            putenv(trim($parts[0]) . '=' . trim(trim($parts[1]), '"\''));
        }
    }
}
loadEnv(__DIR__ . '/.env');

require_once __DIR__ . '/vendor/autoload.php';

use BoqAllocator\Services\BoqAllocationEngine;

// 1. Resolve Model
$selectedModel = 'openai-luna'; // default
if (isset($_GET['model'])) {
    $selectedModel = trim($_GET['model']);
} elseif (isset($_POST['model'])) {
    $selectedModel = trim($_POST['model']);
} else {
    foreach ($argv ?? [] as $arg) {
        if (strpos($arg, '--model=') === 0) {
            $selectedModel = substr($arg, 8);
        }
    }
}

// Fall back to openai-luna if a non-OpenAI model is supplied (AITOOLV3 pipeline uses OpenAI Responses API)
if (!str_starts_with($selectedModel, 'openai-') && !str_starts_with($selectedModel, 'gpt-')) {
    emitStatus("Note: Defaulting '{$selectedModel}' to OpenAI Luna (AITOOLV3 uses OpenAI Responses API).", 2);
    $selectedModel = 'openai-luna';
}

// 2. Resolve Template
$selectedTemplate = 'WD template.csv';
if (isset($_GET['template'])) {
    $selectedTemplate = trim($_GET['template']);
} elseif (isset($_POST['template'])) {
    $selectedTemplate = trim($_POST['template']);
} else {
    foreach ($argv ?? [] as $arg) {
        if (strpos($arg, '--template=') === 0) {
            $selectedTemplate = substr($arg, 11);
        }
    }
}

$templatePath = __DIR__ . '/laravel-boq-allocator/templates/' . basename($selectedTemplate);
if (!file_exists($templatePath)) {
    emitStatus("Error: Template file not found: $selectedTemplate");
    exit;
}

$boqPath = __DIR__ . '/BoQ.xlsx';
if (!file_exists($boqPath)) {
    emitStatus("Error: BoQ file not found: $boqPath");
    exit;
}

try {
    // 3. Initialize the Engine
    $engine = new BoqAllocationEngine();

    // 4. Run Allocation with live progress streaming
    $result = $engine->allocate(
        $boqPath,
        $templatePath,
        $selectedModel,
        null, // custom rules
        function(string $msg, int $pct) {
            emitStatus($msg, $pct);
        }
    );

    // 5. Save Outputs
    $finalOutput = $result->toArray();
    
    // Save active output
    file_put_contents(__DIR__ . '/output_wd.json', json_encode($finalOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Save per-run output for historical viewing
    $runsDir = __DIR__ . '/runs';
    if (!file_exists($runsDir)) {
        @mkdir($runsDir, 0777, true);
    }
    
    // Hash to identify this run
    $runId = 'run_' . md5($result->metadata['engine'] . '::' . $selectedTemplate);
    $runFile = "runs/{$runId}.json";
    file_put_contents(__DIR__ . '/' . $runFile, json_encode($finalOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // 6. Append to Historical Benchmark Data
    $historyFile = __DIR__ . '/benchmark_history.json';
    $history = file_exists($historyFile) ? json_decode(file_get_contents($historyFile), true) : [];
    if (!is_array($history)) $history = [];

    // Calculate Hierarchy Counts by Level
    $l1Count = 0;
    $l2Count = 0;
    $l3Count = 0;

    if (!empty($finalOutput['work_packages'])) {
        $l1Count = count($finalOutput['work_packages']);
        foreach ($finalOutput['work_packages'] as $wdPkg) {
            if (!empty($wdPkg['children'])) {
                foreach ($wdPkg['children'] as $child) {
                    if (isset($child['attributes']['package_type']) && $child['attributes']['package_type'] === 'tier2_item') {
                        $l2Count++;
                        if (!empty($child['children'])) {
                            $l3Count += count($child['children']);
                        }
                    } else {
                        $l3Count++;
                    }
                }
            }
        }
    }
    $totalWorksPackages = $l2Count > 0 ? ($l1Count + $l2Count) : $l1Count;

    $newRun = [
        'timestamp' => date('Y-m-d H:i:s'),
        'model' => $result->metadata['engine'],
        'template' => $selectedTemplate,
        'total_bills' => $result->metadata['total_bills'],
        'mapped_bills' => $result->metadata['mapped_bills'],
        'mapping_rate' => $result->metadata['mapped_bills'] / max(1, $result->metadata['total_bills']),
        'accuracy' => (float)str_replace('%', '', $result->metadata['overall_accuracy_score']),
        'total_packages' => $totalWorksPackages,
        'level_1_count' => $l1Count,
        'level_2_count' => $l2Count,
        'level_3_count' => $l3Count,
        'execution_time_sec' => (float)str_replace('s', '', $result->metadata['execution_time']),
        'cost' => (float)str_replace('$', '', $result->metadata['estimated_cost']),
        'output_file' => $runFile
    ];

    $updatedHistory = [];
    foreach ($history as $run) {
        if (is_array($run) && isset($run['model'])) {
            $runTemplate = $run['template'] ?? 'WD template.csv';
            // Keep runs if they are a different model OR a different template
            if ($run['model'] !== $newRun['model'] || $runTemplate !== $newRun['template']) {
                $updatedHistory[] = $run;
            }
        }
    }
    $updatedHistory[] = $newRun;

    file_put_contents($historyFile, json_encode($updatedHistory, JSON_PRETTY_PRINT));

    emitStatus("=== COMPLETE ===");
    emitStatus("Mapped: {$newRun['mapped_bills']} / {$newRun['total_bills']} | Accuracy Score: {$newRun['accuracy']}% | Cost: $" . number_format($newRun['cost'], 5));

} catch (Exception $e) {
    emitStatus("FATAL ERROR: " . $e->getMessage());
}
