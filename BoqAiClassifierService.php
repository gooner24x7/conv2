<?php

namespace App\Services;

use App\Models\ProcessingJob;
use App\Models\WorkItem;
use App\Models\WorkPackage;
use App\Parsers\BoqParser;
use App\Parsers\CustomerTemplateParser;
use Exception;
use Illuminate\Support\Facades\Log;

class BoqAiClassifierService
{
    public function __construct(
        protected QwenLocalAiService $aiService,
        protected CustomerTemplateParser $templateParser,
        protected BoqParser $boqParser
    ) {}

    public function processJob(ProcessingJob $job): void
    {
        try {
            $job->update([
                'status' => 'parsing',
                'current_step' => 'Parsing Customer Template & BoQ Files',
                'progress_percent' => 5,
            ]);

            // 1. Parse Customer Template and create target WorkPackages
            $targetPackagesData = $this->templateParser->parse($job->template_file_path);
            $packageModels = [];

            foreach ($targetPackagesData as $pkgData) {
                $packageModels[] = WorkPackage::create([
                    'processing_job_id' => $job->id,
                    'package_code' => $pkgData['code'],
                    'name' => $pkgData['name'],
                ]);
            }

            // 2. Parse BoQ file and save unclassified WorkItems
            $boqItemsData = $this->boqParser->parse($job->boq_file_path);
            $itemModels = [];

            foreach ($boqItemsData as $itemData) {
                $itemModels[] = WorkItem::create([
                    'processing_job_id' => $job->id,
                    'work_package_id' => null, // pending classification
                    'item_ref' => $itemData['item_ref'],
                    'description' => $itemData['description'],
                    'unit' => $itemData['unit'],
                    'quantity' => $itemData['quantity'],
                    'rate' => $itemData['rate'],
                    'total' => $itemData['total'],
                ]);
            }

            $totalItems = count($itemModels);
            $job->update([
                'total_items' => $totalItems,
                'status' => 'classifying',
                'current_step' => "Classifying {$totalItems} items using Local Qwen AI",
                'progress_percent' => 10,
            ]);

            if ($totalItems === 0 || empty($packageModels)) {
                $job->update([
                    'status' => 'completed',
                    'progress_percent' => 100,
                    'current_step' => 'Completed (No items to classify)',
                ]);

                return;
            }

            // Build WorkPackage taxonomy reference string for AI system prompt
            $taxonomyList = [];
            foreach ($packageModels as $pkg) {
                $taxonomyList[] = "ID: {$pkg->id} | Code: {$pkg->package_code} | Name: {$pkg->name}";
            }
            $taxonomyStr = implode("\n", $taxonomyList);

            $systemPrompt = "You are an expert UK construction work package classifier.\n".
                "Map each BOQ line item to the SINGLE best matching target Work Package ID from the list below.\n\n".
                "Target Work Packages:\n{$taxonomyStr}\n\n".
                "Respond ONLY with a valid JSON array of objects mapping item_id to package_id.\n".
                "Example format:\n".
                '[{"item_id": 1, "package_id": 2}, {"item_id": 2, "package_id": 1}]';

            // 3. Batch AI Processing (5 items per batch for speed & precision)
            $batchSize = 5;
            $chunks = array_chunk($itemModels, $batchSize);
            $processedCount = 0;
            $firstPackageId = $packageModels[0]->id;

            foreach ($chunks as $chunkIndex => $chunk) {
                $job->refresh();
                if ($job->status === 'aborted') {
                    Log::info("Job {$job->id} aborted by user.");
                    return;
                }

                $itemsPromptList = [];
                foreach ($chunk as $item) {
                    $descSnippet = mb_substr(preg_replace('/\s+/', ' ', $item->description), 0, 150);
                    $itemsPromptList[] = "Item ID: {$item->id} | Ref: {$item->item_ref} | Unit: {$item->unit} | Description: {$descSnippet}";
                }
                $userContent = "Classify these items:\n".implode("\n", $itemsPromptList);

                // Call local AI
                $classificationResult = null;
                if ($this->aiService->isAvailable()) {
                    try {
                        $classificationResult = $this->aiService->extractJson($systemPrompt, $userContent, 300);
                    } catch (Exception $e) {
                        Log::warning('AI batch classification error: '.$e->getMessage());
                    }
                }

                // Process AI mappings
                $mappedIds = [];
                if (is_array($classificationResult)) {
                    foreach ($classificationResult as $mapping) {
                        if (isset($mapping['item_id'], $mapping['package_id'])) {
                            $mappedIds[$mapping['item_id']] = $mapping['package_id'];
                        }
                    }
                }

                // Update items with assigned work_package_id
                foreach ($chunk as $item) {
                    $assignedPackageId = $mappedIds[$item->id] ?? null;

                    // Fallback keyword matching if AI did not return mapping
                    if (! $assignedPackageId) {
                        $assignedPackageId = $this->fallbackKeywordMatch($item->description, $packageModels) ?? $firstPackageId;
                    }

                    $item->update(['work_package_id' => $assignedPackageId]);
                    $processedCount++;
                }

                $progress = 10 + (int) round(($processedCount / $totalItems) * 90);
                $job->update([
                    'processed_items' => $processedCount,
                    'progress_percent' => min(99, $progress),
                    'current_step' => "Classified {$processedCount} of {$totalItems} items",
                ]);
            }

            $job->update([
                'status' => 'completed',
                'progress_percent' => 100,
                'current_step' => 'Classification Completed Successfully',
            ]);

        } catch (Exception $e) {
            Log::error('BoQ Job Failed: '.$e->getMessage());
            $job->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'current_step' => 'Failed: '.$e->getMessage(),
            ]);
        }
    }

    private function fallbackKeywordMatch(string $description, array $packageModels): ?int
    {
        $descLower = strtolower($description);
        foreach ($packageModels as $pkg) {
            $words = explode(' ', strtolower($pkg->name));
            foreach ($words as $word) {
                if (strlen($word) > 3 && str_contains($descLower, $word)) {
                    return $pkg->id;
                }
            }
        }

        return null;
    }
}
