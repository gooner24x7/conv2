<?php
/**
 * Universal BoQ to Works Package Allocation Pipeline
 * Multi-Engine Architecture with Dual-Confidence Scoring & Web-Trigger Support
 */

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

require_once __DIR__ . '/AiProviderService.php';

// Simple .env loader
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            putenv(trim($parts[0]) . '=' . trim($parts[1]));
        }
    }
}
loadEnv(__DIR__ . '/.env');

// Determine Selected Model
$selectedModel = 'gemini-flash'; // default
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

// Determine Selected Template
$selectedTemplate = 'WD template.xlsx'; // default
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

$templatePath = __DIR__ . '/templates/' . basename($selectedTemplate);
if (!file_exists($templatePath)) {
    emitStatus("Error: Template file not found: $selectedTemplate");
    exit;
}

$aiService = new AiProviderService($selectedModel);
emitStatus("Starting BoQ Allocation Engine: " . $aiService->getModelLabel());
emitStatus("Using Works Package Template: " . $selectedTemplate);

// ============================================================
// EXCEL PARSER - Native ZIP/XML reader
// ============================================================
function parseXlsx(string $xlsxPath): array {
    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) throw new Exception("Cannot open $xlsxPath");
    $strings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $doc = new DOMDocument();
        @$doc->loadXML($ssXml);
        foreach ($doc->getElementsByTagName('si') as $si) {
            $text = '';
            foreach ($si->getElementsByTagName('t') as $t) $text .= $t->textContent;
            $strings[] = $text;
        }
    }
    $doc = new DOMDocument();
    @$doc->loadXML($zip->getFromName('xl/workbook.xml'));
    $sheetsByRid = [];
    foreach ($doc->getElementsByTagName('sheet') as $s) $sheetsByRid[$s->getAttribute('r:id')] = $s->getAttribute('name');
    $doc2 = new DOMDocument();
    @$doc2->loadXML($zip->getFromName('xl/_rels/workbook.xml.rels'));
    $sheetFiles = [];
    foreach ($doc2->getElementsByTagName('Relationship') as $r) {
        $rid = $r->getAttribute('Id');
        if (isset($sheetsByRid[$rid])) $sheetFiles[$sheetsByRid[$rid]] = 'xl/' . $r->getAttribute('Target');
    }
    $allSheets = [];
    foreach ($sheetFiles as $name => $path) {
        $xml = $zip->getFromName($path);
        if (!$xml) continue;
        $doc = new DOMDocument();
        @$doc->loadXML($xml);
        $rows = [];
        foreach ($doc->getElementsByTagName('row') as $row) {
            $rd = [];
            foreach ($row->getElementsByTagName('c') as $cell) {
                preg_match('/^([A-Z]+)/', $cell->getAttribute('r'), $m);
                $col = $m[1] ?? '';
                $v = $cell->getElementsByTagName('v')->item(0);
                if ($v) {
                    $rd[$col] = ($cell->getAttribute('t') == 's') ? ($strings[(int)$v->textContent] ?? '') : $v->textContent;
                }
            }
            $rows[] = $rd;
        }
        $allSheets[$name] = $rows;
    }
    $zip->close();
    return $allSheets;
}

function cleanText(string $s): string {
    return trim(preg_replace('/\s+/', ' ', str_replace("\n", " ", $s)));
}

// ============================================================
// PHASE 1: Load Works Packages
// ============================================================
emitStatus("Phase 1: Loading Works Packages from $selectedTemplate...");
$wdData = parseXlsx($templatePath);
$wdSheet = $wdData['Sheet1'] ?? reset($wdData);

$wdPackages = [];
$wdList = [];
foreach ($wdSheet as $row) {
    $name = trim($row['A'] ?? '');
    $desc = trim($row['B'] ?? ''); // Extract Strategy #2 Description Column
    
    // Skip if empty or if it's the header row
    if ($name === '' || stripos($name, 'Works Package') !== false || stripos($name, 'wd_') !== false) continue;
    
    $id = 'wd_' . count($wdPackages);
    $wdPackages[$name] = $id;
    $wdList[] = ['id' => $id, 'name' => $name, 'description' => $desc];
}
emitStatus("  -> Identified " . count($wdList) . " target Works Packages.");

// ============================================================
// PHASE 2 & 3: Extract General Summary & Rich Context
// ============================================================
emitStatus("Phase 2 & 3: Ingesting BoQ General Summary & detailed Bill Items...");
$boqData = parseXlsx('BoQ.xlsx');
$genSummary = $boqData['General Summary'] ?? [];
$bills = [];
foreach ($genSummary as $row) {
    $colA = trim($row['A'] ?? '');
    $colB = trim($row['B'] ?? '');
    if (preg_match('/^Bill\s+(\d+)$/i', $colA, $m) && $colB !== '') {
        $bills[(int)$m[1]] = cleanText($colB);
    }
}
ksort($bills);

$billContext = [];
$billItems = $boqData['Bill Items'] ?? [];
$lastBillNum = null;

foreach ($billItems as $row) {
    $colA = trim($row['A'] ?? '');
    $colE = trim($row['E'] ?? '');
    if ($colA !== '' && ctype_digit($colA)) $lastBillNum = (int)$colA;
    
    if ($lastBillNum !== null && isset($bills[$lastBillNum]) && $colE !== '') {
        $cleanDesc = cleanText(mb_substr($colE, 0, 400));
        if (strlen($cleanDesc) > 3 && !in_array($cleanDesc, ['To Collection', 'Total Carried to Summary', 'Carried to Summary'])) {
            if (!isset($billContext[$lastBillNum])) $billContext[$lastBillNum] = [];
            // Strategy #1 Reversal: Reduced from 50 to 20 to eliminate long-tail noise
            if (count($billContext[$lastBillNum]) < 20 && !in_array($cleanDesc, $billContext[$lastBillNum])) {
                $billContext[$lastBillNum][] = $cleanDesc;
            }
        }
    }
}
emitStatus("  -> Extracted " . count($bills) . " bills with rich scope context.");

// ============================================================
// PHASE 4: Bypass Programmatic Matching (100% AI Reasoning)
// ============================================================
$billMappings = [];
$unmappedForAi = $bills; // All bills require AI analysis

emitStatus("Phase 4: Programmatic matching disabled. 100% of bills (" . count($unmappedForAi) . ") will be sent to the AI Reasoning Engine.");

// ============================================================
// PHASE 5: AI Analysis with Dual-Confidence
// ============================================================
$totalInTokens = 0;
$totalOutTokens = 0;
$totalEstimatedCost = 0.0;

if (count($unmappedForAi) > 0) {
    emitStatus("Phase 5: Processing with " . $aiService->getModelLabel() . "...");

    $targetListStr = "";
    foreach ($wdList as $wp) {
        if (!empty($wp['description'])) {
            $targetListStr .= "- {$wp['id']}: {$wp['name']} (SCOPE DEFINITION: {$wp['description']})\n";
        } else {
            $targetListStr .= "- {$wp['id']}: {$wp['name']}\n";
        }
    }

    $isOpenAI = (strpos($selectedModel, 'openai-') === 0);
    
    // Check for Custom Rules Injection
    $customRulesInjection = "";
    if (file_exists(__DIR__ . '/custom_rules.txt')) {
        $customText = trim(file_get_contents(__DIR__ . '/custom_rules.txt'));
        if (!empty($customText)) {
            $customRulesInjection = "\n\nUSER SPECIFIC OVERRIDE RULES (PRIORITIZE THESE):\n" . $customText;
        }
    }

    $baseRules = "TARGET WORKS PACKAGES:\n$targetListStr\n\nRULES & CONSTRAINTS:\n1. CHAIN OF THOUGHT: You MUST generate your \"rationale\" FIRST. Think through the scope before selecting the package.\n2. DOMINANT TRADE FOCUS: Bills often contain a \"long tail\" of minor items (e.g. temporary scaffolding, fixings, or cleaning) that support a main trade. IGNORE these minor items. Map the bill based exclusively on the DOMINANT permanent trade.\n3. NEGATIVE CONSTRAINTS: Only map to \"wd_unmapped\" (General/Preliminaries) if the ENTIRE bill consists of temporary works or site setups. If there is permanent construction, pick a package.\n4. EDGE CASES: If a bill is ambiguously named, rely strictly on item descriptions. If a 50/50 split, map to \"wd_unmapped\".\n5. BASELINES: A clear bill like \"Brickwork\" should map directly to the masonry works package." . $customRulesInjection . "\n\nEXAMPLES:\n[\n  {\n    \"rationale\": \"The items list site cabins and scaffolding. These are temporary works and preliminaries.\",\n    \"bill_number\": 1,\n    \"target_wd_id\": \"wd_unmapped\",\n    \"package_confidence\": 99,\n    \"trade\": \"Preliminaries\",\n    \"trade_confidence\": 99\n  },\n  {\n    \"rationale\": \"Scope includes facing bricks and mortar, perfectly aligning with masonry.\",\n    \"bill_number\": 2,\n    \"target_wd_id\": \"wd_4\",\n    \"package_confidence\": 95,\n    \"trade\": \"Bricklayer / Mason\",\n    \"trade_confidence\": 98\n  }\n]\n\nEvaluate both the Works Package match and the Subcontractor Trade independently.\nFor each bill, return:\n1. \"rationale\": concise 1-sentence commercial explanation citing specific items or materials.\n2. \"bill_number\": integer\n3. \"target_wd_id\": string (e.g. \"wd_3\" or \"wd_unmapped\")\n4. \"package_confidence\": integer (0 to 100)\n5. \"trade\": string (specific subcontractor trade)\n6. \"trade_confidence\": integer (0 to 100)";
    
    // Check for Full Prompt Override
    $systemPrompt = "";
    if (file_exists(__DIR__ . '/custom_prompt.txt')) {
        $overridePrompt = trim(file_get_contents(__DIR__ . '/custom_prompt.txt'));
        if (!empty($overridePrompt)) {
            // If they provided the tag [TARGET_WORKS_PACKAGES], inject the list
            $systemPrompt = str_replace('[TARGET_WORKS_PACKAGES]', $targetListStr, $overridePrompt);
        }
    }

    if (empty($systemPrompt)) {
        if ($isOpenAI) {
            $systemPrompt = "You are a Senior UK Quantity Surveyor. Analyze each BoQ Bill and map it to the SINGLE best Works Package.\n\n" . $baseRules . "\n\nYou must return a valid JSON array of objects. Output JSON ONLY. No markdown formatting. No conversational text.";
        } else {
            $systemPrompt = "You are a Senior UK Quantity Surveyor. Analyze each BoQ Bill and map it to the SINGLE best Works Package.\n\n" . $baseRules . "\n\nIMPORTANT: To ensure deep reasoning, you MUST write out your commercial analysis inside <scratchpad>...</scratchpad> tags BEFORE outputting the JSON array.\nAfter the scratchpad, output the final result in a ```json code block.";
        }
    }

    $unmappedKeys = array_keys($unmappedForAi);
    $batchSize = 12;
    $batches = array_chunk($unmappedKeys, $batchSize);

    foreach ($batches as $idx => $batchBills) {
        // Halt if the user cancelled the request in the UI
        if (connection_aborted()) {
            exit;
        }

        $bNum = $idx + 1;
        emitStatus("  -> Running AI Batch $bNum of " . count($batches) . " (" . count($batchBills) . " bills)...");

        $userLines = [];
        foreach ($batchBills as $bn) {
            $name = $unmappedForAi[$bn];
            $items = $billContext[$bn] ?? [];
            // Remove the hardcoded limit of 10 that was squashing Strategy #1
            $itemsList = !empty($items) ? implode('; ', array_slice($items, 0, 50)) : 'No detailed item descriptions';
            $userLines[] = "Bill $bn: \"$name\"\nScope: $itemsList\n";
        }
        if ($isOpenAI) {
            $userContent = "Analyze and map these BoQ Bills:\n\n" . implode("\n", $userLines);
        } else {
            $userContent = "Analyze and map these BoQ Bills:\n\n" . implode("\n", $userLines) . "\n\nRemember to write your <scratchpad> reasoning first, followed by the ```json block.";
        }

        try {
            // PASS 1: Generate Draft Mapping
            emitStatus("  -> [Pass 1] Generating draft mapping...");
            $draftResponse = $aiService->prompt($systemPrompt, $userContent);

            $totalInTokens += $aiService->lastUsage['input_tokens'];
            $totalOutTokens += $aiService->lastUsage['output_tokens'];
            $totalEstimatedCost += $aiService->lastUsage['estimated_cost'];

            // PASS 2: Self-Reflection & Critique
            emitStatus("  -> [Pass 2] Self-Reflecting and correcting errors...");
            $baseReflect = "ORIGINAL BILLS TO MAP:\n" . implode("\n", $userLines) . "\n\nYOUR DRAFT MAPPING:\n" . $draftResponse . "\n\nREVIEW AND REFINE:\nCritique your draft mapping above against the original bills. Check strictly against the RULES & CONSTRAINTS. Specifically:\n1. Did you map a bill to 'wd_unmapped' just because it contained minor scaffolding/fixings? If so, correct it to the dominant permanent trade.\n2. Ensure the 'ai_rationale' justifies the final choice based on the DOMINANT items.\n\n";
            if ($isOpenAI) {
                $reflectionPrompt = $baseReflect . "Output ONLY the final, corrected JSON array containing the refined mappings in the exact same format. No markdown, pure JSON.";
            } else {
                $reflectionPrompt = $baseReflect . "First, put your reflection in a <scratchpad>. Then output the final, corrected JSON array in a ```json block.";
            }
                                
            $finalResponse = $aiService->prompt($systemPrompt, $reflectionPrompt);
            
            $totalInTokens += $aiService->lastUsage['input_tokens'];
            $totalOutTokens += $aiService->lastUsage['output_tokens'];
            $totalEstimatedCost += $aiService->lastUsage['estimated_cost'];

            $parsed = json_decode($finalResponse, true);
            if (!$parsed && preg_match('/\[\s*\{.*\}\s*\]/s', $finalResponse, $m)) {
                $parsed = json_decode($m[0], true);
            }
            
            // Safety Catch: If the AI wrapped the array in a root object (e.g. {"bills": [...]})
            if (is_array($parsed) && !isset($parsed[0])) {
                foreach ($parsed as $key => $val) {
                    if (is_array($val) && isset($val[0])) {
                        $parsed = $val;
                        break;
                    }
                }
            }

            if (is_array($parsed)) {
                foreach ($parsed as $item) {
                    $bn = (int)($item['bill_number'] ?? 0);
                    $target = trim($item['target_wd_id'] ?? 'unmapped');
                    if ($bn > 0 && isset($bills[$bn])) {
                        $billMappings[$bn] = [
                            'target' => (strpos($target, 'wd_') === 0) ? $target : 'wd_unmapped',
                            'package_confidence' => (int)($item['package_confidence'] ?? 85),
                            'trade' => $item['trade'] ?? 'General Subcontractor',
                            'trade_confidence' => (int)($item['trade_confidence'] ?? 85),
                            'rationale' => $item['rationale'] ?? 'Mapped via AI reasoning.'
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            emitStatus("  [ERROR in Batch $bNum] " . $e->getMessage());
        }
    }
}

// ============================================================
// PHASE 6: Compile Hierarchy & Overall Accuracy Metric
// ============================================================
emitStatus("Phase 6: Compiling Final Allocation Hierarchy...");

$output = [];
$mappedCount = 0;
$unmappedChildren = [];
$totalPkgConfidence = 0;
$totalTradeConfidence = 0;
$scoredCount = 0;

foreach ($wdList as $wp) {
    $node = [
        'id' => $wp['id'],
        'name' => $wp['name'],
        'attributes' => ['package_type' => 'wd_template'],
        'children' => []
    ];
    
    foreach ($billMappings as $bn => $mapData) {
        if ($mapData['target'] === $wp['id']) {
            $node['children'][] = [
                'id' => 'bill_' . $bn,
                'name' => "Bill $bn: " . $bills[$bn],
                'attributes' => [
                    'bill_number' => $bn,
                    'suggested_trade' => $mapData['trade'],
                    'package_confidence' => $mapData['package_confidence'],
                    'trade_confidence' => $mapData['trade_confidence'],
                    'ai_rationale' => $mapData['rationale']
                ],
                'source_evidence' => $billContext[$bn] ?? []
            ];
            $mappedCount++;
            $totalPkgConfidence += $mapData['package_confidence'];
            $totalTradeConfidence += $mapData['trade_confidence'];
            $scoredCount++;
        }
    }
    if (count($node['children']) > 0) $output[] = $node;
}

foreach ($bills as $bn => $name) {
    $mapData = $billMappings[$bn] ?? null;
    if (!$mapData || $mapData['target'] === 'wd_unmapped') {
        $unmappedChildren[] = [
            'id' => 'bill_' . $bn,
            'name' => "Bill $bn: $name",
            'attributes' => [
                'bill_number' => $bn,
                'suggested_trade' => $mapData['trade'] ?? 'General / Allowance',
                'package_confidence' => $mapData['package_confidence'] ?? 0,
                'trade_confidence' => $mapData['trade_confidence'] ?? 50,
                'ai_rationale' => $mapData['rationale'] ?? 'Identified as general allowance or unallocated scope.'
            ],
            'source_evidence' => $billContext[$bn] ?? []
        ];
    }
}

if (count($unmappedChildren) > 0) {
    $output[] = [
        'id' => 'wd_unmapped',
        'name' => 'Unmapped / General Scope',
        'attributes' => ['package_type' => 'wd_template'],
        'children' => $unmappedChildren
    ];
}

// Calculate Overall Accuracy Score
$avgPkgConf = $scoredCount > 0 ? round($totalPkgConfidence / $scoredCount, 1) : 0;
$avgTradeConf = $scoredCount > 0 ? round($totalTradeConfidence / $scoredCount, 1) : 0;
// Overall accuracy combines allocation rate and average confidence
$mappingRate = count($bills) > 0 ? ($mappedCount / count($bills)) : 0;
$overallAccuracy = round((($avgPkgConf * 0.95) + ($avgTradeConf * 0.05)) * ($mappingRate * 0.2 + 0.8), 1);

$finalOutput = [
    'metadata' => [
        'total_bills' => count($bills),
        'mapped_bills' => $mappedCount,
        'unmapped_bills' => count($unmappedChildren),
        'wd_packages_used' => count(array_filter($output, fn($n) => $n['id'] !== 'wd_unmapped')),
        'engine' => $aiService->getModelLabel(),
        'template' => $selectedTemplate,
        'execution_time' => round(microtime(true) - $globalStartTime, 2) . 's',
        'overall_accuracy_score' => $overallAccuracy . '%',
        'avg_package_confidence' => $avgPkgConf . '%',
        'avg_trade_confidence' => $avgTradeConf . '%',
        'token_usage' => [
            'input' => $totalInTokens,
            'output' => $totalOutTokens,
            'total' => $totalInTokens + $totalOutTokens
        ],
        'estimated_cost' => '$' . number_format($totalEstimatedCost, 5)
    ],
    'work_packages' => $output
];

file_put_contents('output_wd.json', json_encode($finalOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Append to Historical Benchmark Data (Keeping only the latest run per model + template combination)
$historyFile = 'benchmark_history.json';
$history = file_exists($historyFile) ? json_decode(file_get_contents($historyFile), true) : [];
if (!is_array($history)) $history = [];

$newRun = [
    'timestamp' => date('Y-m-d H:i:s'),
    'model' => $aiService->getModelLabel(),
    'template' => $selectedTemplate,
    'total_bills' => count($bills),
    'mapped_bills' => $mappedCount,
    'mapping_rate' => $mappingRate,
    'accuracy' => (float)$overallAccuracy,
    'execution_time_sec' => round(microtime(true) - $globalStartTime, 2),
    'cost' => (float)$totalEstimatedCost
];

$updatedHistory = [];
foreach ($history as $run) {
    if (is_array($run) && isset($run['model'])) {
        $runTemplate = $run['template'] ?? 'WD template.xlsx';
        // Keep runs if they are a different model OR a different template
        if ($run['model'] !== $newRun['model'] || $runTemplate !== $newRun['template']) {
            $updatedHistory[] = $run;
        }
    }
}
$updatedHistory[] = $newRun;

file_put_contents($historyFile, json_encode($updatedHistory, JSON_PRETTY_PRINT));

emitStatus("=== COMPLETE ===");
emitStatus("Mapped: $mappedCount / " . count($bills) . " | Accuracy Score: {$overallAccuracy}% | Cost: $" . number_format($totalEstimatedCost, 5));

