<?php

namespace BoqAllocator\Services;

use BoqAllocator\DTOs\AllocationResult;
use Exception;

class BoqAllocationEngine
{
    protected BoqParserService $parser;
    protected ?AiProviderService $aiProvider = null;
    protected array $config;

    public function __construct(?BoqParserService $parser = null, array $config = [])
    {
        $this->parser = $parser ?: new BoqParserService();
        $this->config = $config ?: (function_exists('config') ? config('boq-allocator', []) : []);
    }

    /**
     * Run full BoQ allocation against a Works Package template.
     *
     * @param string $boqPath Absolute path to the BoQ .xlsx file
     * @param string $templatePath Absolute path to the template .csv or .xlsx file
     * @param string|null $modelKey Specific AI model key (optional)
     * @param string|null $customPromptRules Custom domain rules/overrides to inject into prompt (optional)
     * @param callable|null $progressCallback Callback function for real-time progress notifications
     * @return AllocationResult
     */
    public function allocate(
        string $boqPath,
        string $templatePath,
        ?string $modelKey = null,
        ?string $customPromptRules = null,
        ?callable $progressCallback = null
    ): AllocationResult {
        $startTime = microtime(true);
        $emit = function(string $msg, int $percentage = 0) use ($progressCallback) {
            if ($progressCallback) {
                call_user_func($progressCallback, $msg, $percentage);
            }
        };

        $modelKey = $modelKey ?: ($this->config['default_model'] ?? 'gemini-3.6-flash');
        $this->aiProvider = new AiProviderService($modelKey, $this->config);

        // ----------------------------------------------------
        // PHASE 1: Parse Template
        // ----------------------------------------------------
        $emit("Phase 1: Loading Works Package template...", 5);
        $templateData = $this->parser->parseTemplate($templatePath);
        $wdList = $templateData['list'];
        $wdPackages = $templateData['packages'];

        // ----------------------------------------------------
        // PHASE 2 & 3: Parse BoQ & Context
        // ----------------------------------------------------
        $emit("Phase 2 & 3: Extracting BoQ summary & detailed item context...", 15);
        $maxContext = (int)($this->config['max_context_items_per_bill'] ?? 20);
        $boqData = $this->parser->parseBoq($boqPath, $maxContext);
        $bills = $boqData['bills'];
        $billContext = $boqData['billContext'];

        if (empty($bills)) {
            throw new Exception("No valid Bills found in General Summary of BoQ.");
        }

        // ----------------------------------------------------
        // PHASE 4: Build System Prompt
        // ----------------------------------------------------
        $emit("Phase 4: Constructing AI prompt with strict measurement rules...", 25);
        $rulesTxt = "AVAILABLE WORKS PACKAGES (ID | Package Name | Inclusions):\n";
        foreach ($wdList as $wp) {
            $rulesTxt .= "- ID: {$wp['id']} | Name: {$wp['name']} | Scope: {$wp['description']}\n";
        }
        $rulesTxt .= "- ID: wd_unmapped | Name: Unmapped / General Scope | Scope: General contractor allowances, non-allocable provisions, or undefinable work.\n";

        $customRulesBlock = '';
        if (!empty($customPromptRules)) {
            $customRulesBlock = "\nUSER-DEFINED ALLOCATION RULES (HIGHEST PRIORITY):\n" . trim($customPromptRules) . "\n";
        }

        $systemPrompt = <<<PROMPT
You are a Senior Construction Commercial Manager and Expert Estimator specializing in standard measurement methods (NRM, CESMM, SMM7, bespoke works packages).
Your task is to accurately allocate each Bill from a Bill of Quantities (BoQ) to its most appropriate target Works Package.

$rulesTxt
$customRulesBlock

ALLOCATION & REASONING GUIDELINES:
1. Examine both the Bill Title and the Detailed Sub-Item Context lines provided.
2. Select the single best matching Works Package ID from the list above.
3. If a bill contains general contractor overheads or allowances not matching a trade package, allocate to "wd_unmapped".
4. Determine the primary subcontractor Trade Name (e.g. "Groundworks Subcontractor", "Steel Fabricator", "Drylining Contractor").
5. Provide a confidence score (0 to 100) for package allocation and trade categorization.
6. Provide a concise, expert commercial rationale for your decision.

RESPONSE FORMAT:
You must output a single JSON array containing an allocation object for every bill in the batch:
[
  {
    "bill_number": 1,
    "target_wd_id": "wd_0",
    "package_confidence": 95,
    "trade": "Subcontractor Trade Name",
    "trade_confidence": 90,
    "rationale": "Commercial reasoning based on items present."
  }
]
PROMPT;

        // ----------------------------------------------------
        // PHASE 5: Batch Processing
        // ----------------------------------------------------
        $batchSize = (int)($this->config['batch_size'] ?? 18);
        $batches = array_chunk(array_keys($bills), $batchSize);
        $totalBatches = count($batches);
        $billMappings = [];
        $totalInTokens = 0;
        $totalOutTokens = 0;

        foreach ($batches as $bIndex => $batchBillNumbers) {
            $bNum = $bIndex + 1;
            $pct = 25 + (int)(($bNum / $totalBatches) * 60);
            $emit("Phase 5: Processing AI reasoning batch $bNum of $totalBatches (" . count($batchBillNumbers) . " bills)...", $pct);

            $batchPayload = [];
            foreach ($batchBillNumbers as $bn) {
                $batchPayload[] = [
                    'bill_number' => $bn,
                    'bill_name' => $bills[$bn],
                    'detailed_context_items' => $billContext[$bn] ?? []
                ];
            }

            $userPrompt = "Analyze and allocate the following batch of Bills:\n" . json_encode($batchPayload, JSON_PRETTY_PRINT);

            $isOpenAI = $this->aiProvider->getProvider() === 'openai';
            if (!$isOpenAI) {
                $userPrompt .= "\n\nRemember to write your <scratchpad> reasoning first, followed by the ```json block.";
            }

            try {
                // PASS 1: Generate Draft Mapping
                $emit("  -> [Pass 1] Generating draft mapping...", $pct);
                $draftResponse = $this->aiProvider->sendChat($systemPrompt, $userPrompt);
                $totalInTokens += $draftResponse['input_tokens'];
                $totalOutTokens += $draftResponse['output_tokens'];

                // PASS 2: Self-Reflection & Critique
                $emit("  -> [Pass 2] Self-Reflecting and correcting errors...", $pct + 2);
                $baseReflect = "ORIGINAL BILLS TO MAP:\n" . json_encode($batchPayload, JSON_PRETTY_PRINT) . "\n\nYOUR DRAFT MAPPING:\n" . $draftResponse['text'] . "\n\nREVIEW AND REFINE:\nCritique your draft mapping above against the original bills. Check strictly against the RULES & CONSTRAINTS. Specifically:\n1. Did you map a bill to 'wd_unmapped' just because it contained minor scaffolding/fixings? If so, correct it to the dominant permanent trade.\n2. Ensure the 'rationale' justifies the final choice based on the DOMINANT items.\n\n";
                
                if ($isOpenAI) {
                    $reflectionPrompt = $baseReflect . "Output ONLY the final, corrected JSON array containing the refined mappings in the exact same format. No markdown, pure JSON.";
                } else {
                    $reflectionPrompt = $baseReflect . "First, put your reflection in a <scratchpad>. Then output the final, corrected JSON array in a ```json block.";
                }

                $finalResponse = $this->aiProvider->sendChat($systemPrompt, $reflectionPrompt);
                $totalInTokens += $finalResponse['input_tokens'];
                $totalOutTokens += $finalResponse['output_tokens'];

                $cleanJson = $this->extractJson($finalResponse['text']);
                $parsed = json_decode($cleanJson, true);

                if (is_array($parsed)) {
                    // Normalize if wrapped in a key
                    if (isset($parsed['allocations'])) $parsed = $parsed['allocations'];
                    if (isset($parsed['bills'])) $parsed = $parsed['bills'];

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
                $emit("  [ERROR in Batch $bNum] " . $e->getMessage(), $pct);
            }
        }

        // ----------------------------------------------------
        // PHASE 6: Compile Hierarchy & Calculate Metrics
        // ----------------------------------------------------
        $emit("Phase 6: Compiling Final Package Hierarchy & Accuracy Metrics...", 95);

        $outputTree = [];
        $unmappedChildren = [];
        $totalPkgConfidence = 0.0;
        $totalTradeConfidence = 0.0;
        $allocatedBillIds = [];

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
                        'name' => "Bill $bn: " . ($bills[$bn] ?? "Bill $bn"),
                        'attributes' => [
                            'bill_number' => $bn,
                            'suggested_trade' => $mapData['trade'],
                            'package_confidence' => $mapData['package_confidence'],
                            'trade_confidence' => $mapData['trade_confidence'],
                            'ai_rationale' => $mapData['rationale']
                        ],
                        'source_evidence' => $billContext[$bn] ?? []
                    ];
                    $allocatedBillIds[$bn] = true;
                    $totalPkgConfidence += (float)$mapData['package_confidence'];
                    $totalTradeConfidence += (float)$mapData['trade_confidence'];
                }
            }
            if (count($node['children']) > 0) {
                $outputTree[] = $node;
            }
        }

        foreach ($bills as $bn => $name) {
            if (!isset($allocatedBillIds[$bn])) {
                $mapData = $billMappings[$bn] ?? null;
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
            $outputTree[] = [
                'id' => 'wd_unmapped',
                'name' => 'Unmapped / General Scope',
                'attributes' => ['package_type' => 'wd_template'],
                'children' => $unmappedChildren
            ];
        }

        // Metrics (Strictly 0% - 100%)
        $totalBillsCount = count($bills);
        $mappedCount = count($allocatedBillIds);
        $scoredCount = $mappedCount;

        $avgPkgConf = $scoredCount > 0 ? round($totalPkgConfidence / $scoredCount, 1) : 0;
        $avgTradeConf = $scoredCount > 0 ? round($totalTradeConfidence / $scoredCount, 1) : 0;

        $mappingRate = $totalBillsCount > 0 ? min(1.0, max(0.0, $mappedCount / $totalBillsCount)) : 0.0;
        $rawAccuracy = $avgPkgConf * ($mappingRate * 0.2 + 0.8);
        $overallAccuracy = round(min(100.0, max(0.0, $rawAccuracy)), 1);

        $totalEstimatedCost = $this->aiProvider->calculateCost($totalInTokens, $totalOutTokens);
        $executionTime = round(microtime(true) - $startTime, 2);

        $metadata = [
            'total_bills' => $totalBillsCount,
            'mapped_bills' => $mappedCount,
            'unmapped_bills' => count($unmappedChildren),
            'packages_used' => count(array_filter($outputTree, fn($n) => $n['id'] !== 'wd_unmapped')),
            'engine' => $this->aiProvider->getModelLabel(),
            'template' => basename($templatePath),
            'execution_time' => $executionTime . 's',
            'overall_accuracy_score' => $overallAccuracy . '%',
            'avg_package_confidence' => $avgPkgConf . '%',
            'avg_trade_confidence' => $avgTradeConf . '%',
            'token_usage' => [
                'input' => $totalInTokens,
                'output' => $totalOutTokens,
                'total' => $totalInTokens + $totalOutTokens
            ],
            'estimated_cost' => '$' . number_format($totalEstimatedCost, 5)
        ];

        $emit("Completed BoQ Allocation in {$executionTime}s.", 100);

        return new AllocationResult($metadata, $outputTree);
    }

    protected function extractJson(string $text): string
    {
        // Strip markdown code fences if present
        if (preg_match('/```(?:json)?\s*(\[\s*\{.*\}\s*\])\s*```/is', $text, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $text, $m)) {
            return trim($m[1]);
        }
        $start = strpos($text, '[');
        $end = strrpos($text, ']');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }
        return $text;
    }
}
