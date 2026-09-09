<?php

namespace BoqAllocator\Services;

use Exception;

class AitoolV3Pipeline
{
    public function __construct(
        private readonly BoqParserService $parser,
        private readonly AitoolV3Classifier $classifier,
        private readonly array $config = [],
    ) {}

    public function run(string $boqPath, string $templatePath, ?string $modelKey = null, ?callable $progressCallback = null): array
    {
        $emit = static function (string $message, int $percent) use ($progressCallback): void {
            if ($progressCallback) $progressCallback($message, $percent);
        };
        $modelKey = $modelKey ?: ($this->config['default_model'] ?? 'gpt-5.6-luna');
        $aiProvider = new AiProviderService($modelKey, $this->config);

        $emit('Phase 1: Loading allocation dictionary...', 5);
        $template = $this->parser->parseTemplate($templatePath);
        [$dictionary, $codeTargets] = $this->buildDictionary($template);
        if (!$dictionary) throw new Exception('The allocation template contains no valid candidates.');
        $dictionaryVersion = $this->dictionaryVersion($template['profile'], $dictionary);

        $emit('Phase 2: Extracting row-level BoQ records and source context...', 15);
        $boq = $this->parser->parseBoq($boqPath, (int)($this->config['max_context_items_per_bill'] ?? 20));
        $records = $this->classifier->annotateRecords($boq['records'], $template['profile']);
        if (!$boq['bills']) throw new Exception('No valid Bills found in General Summary of BoQ.');

        // Keep cache configuration private to the implementation so published
        // package configuration remains backward-compatible.
        $cachePath = (string)($this->config['decision_cache_path'] ?? (
            function_exists('storage_path')
                ? storage_path('app/boq-allocator/decision-cache.php')
                : (file_exists(dirname(__DIR__, 2) . '/decision-cache.php')
                    ? dirname(__DIR__, 2) . '/decision-cache.php'
                    : (file_exists(dirname(__DIR__, 3) . '/decision-cache.php') ? dirname(__DIR__, 3) . '/decision-cache.php' : ''))
        ));
        $cache = new DecisionCacheService($cachePath);
        $validCodes = array_fill_keys(array_keys($dictionary), true);
        $decisions = [];
        $unresolved = [];
        $cacheHits = 0;

        $emit('Phase 3: Applying AITOOLV3 deterministic allocation rules...', 30);
        foreach ($records as $index => &$record) {
            $record['item_id'] = (string)($index + 1);
            $record['decision_key'] = $this->decisionKey($template['profile'], $dictionaryVersion, $record);
            $decision = $this->classifier->classify($record, $template['profile'], $validCodes);
            $record['deterministic_decision'] = $decision;
            if ($decision['status'] === 'REVIEW') {
                $candidateCodes = array_values(array_unique(array_filter(array_merge([$decision['code']], $decision['alternatives'] ?? []))));
                $candidates = array_values(array_intersect_key($dictionary, array_flip($candidateCodes)));
                if ($candidates) {
                    $cached = ($this->config['bypass_cache'] ?? false) ? null : $cache->get($record['decision_key'], $dictionaryVersion);
                    if ($cached !== null) {
                        $decision = $this->validateAiDecision($cached['decision'], $decision, $candidates, $template['profile']);
                        $record['api_resolved'] = true;
                        $record['decision_model'] = (string)($cached['model'] ?? $modelKey);
                        $cacheHits++;
                    } else {
                        $record['candidates'] = $candidates;
                        $unresolved[] = $record;
                    }
                }
            }
            $decisions[$record['item_id']] = $decision;
        }
        unset($record);

        $inputTokens = $cachedInputTokens = $outputTokens = 0;
        if ($unresolved) {
            $emit("Phase 4: Resolving deterministic review cases with {$aiProvider->getModelLabel()}...", 55);
            $batchSize = min(100, max(1, (int)($this->config['max_records_per_call'] ?? 100)));
            $batches = array_chunk($unresolved, $batchSize);
            foreach ($batches as $batchIndex => $batch) {
                $response = $this->classifyBatchAdaptive($aiProvider, $template['profile'], $dictionaryVersion, $batch);
                $inputTokens += $response['input_tokens'];
                $cachedInputTokens += $response['cached_input_tokens'];
                $outputTokens += $response['output_tokens'];
                $byId = array_column($batch, null, 'item_id');
                foreach ($response['results'] as $item) {
                    $itemId = (string)($item['item_id'] ?? '');
                    if (!isset($byId[$itemId])) continue;
                    $decision = $this->validateAiDecision($item, $decisions[$itemId], $byId[$itemId]['candidates'], $template['profile']);
                    $decisions[$itemId] = $decision;
                    $cache->put($byId[$itemId]['decision_key'], $dictionaryVersion, $decision, $modelKey);
                    $records[(int)$itemId - 1]['api_resolved'] = true;
                    $records[(int)$itemId - 1]['decision_model'] = $modelKey;
                }
                $emit('Phase 4: Completed AI review batch '.($batchIndex + 1).' of '.count($batches).'.', 55 + (int)((($batchIndex + 1) / count($batches)) * 20));
            }
            if (!$cache->save()) throw new Exception('The AITOOLV3 decision cache could not be saved.');
        }

        foreach ($records as $index => &$record) $record['decision'] = $decisions[(string)($index + 1)];
        unset($record);
        $wdScopeGroups = str_starts_with($template['profile'], 'wd-work-packages-')
            ? $this->classifier->summarizeWdScopeGroups($records)
            : [];
        return compact('template', 'dictionary', 'codeTargets', 'dictionaryVersion', 'boq', 'records', 'decisions') + [
            'model_label' => $aiProvider->getModelLabel(),
            'token_usage' => ['input' => $inputTokens, 'cached_input' => $cachedInputTokens, 'output' => $outputTokens],
            'cache_hits' => $cacheHits,
            'api_requested' => count($unresolved),
            'estimated_cost' => $aiProvider->calculateCost(max(0, $inputTokens - $cachedInputTokens), $outputTokens) + ($cachedInputTokens / 1000000 * .02),
            'wd_scope_groups' => $wdScopeGroups,
            'mapping_rules_count' => str_starts_with($template['profile'], 'wd-work-packages-') ? count($this->classifier->wdMappingRules()) : 0,
        ];
    }

    private function validateAiDecision(array $item, array $fallback, array $candidates, string $profile): array
    {
        $allowed = array_column($candidates, 'code');
        $code = (string)($item['code'] ?? '');
        $valid = $code !== '' && in_array($code, $allowed, true);
        $status = (string)($item['status'] ?? 'REVIEW');
        $auto = $status === 'AUTO' && $valid && (float)($item['confidence'] ?? 0) >= .9;
        $unallocated = str_starts_with($profile, 'wd-work-packages-') && $status === 'UNALLOCATED' && $code === '';
        return [
            'code' => $valid ? $code : ($fallback['code'] ?? ''),
            'status' => $auto ? 'AUTO' : ($unallocated ? 'UNALLOCATED' : 'REVIEW'),
            'confidence' => $auto ? (float)$item['confidence'] : ($unallocated ? 0 : min(.9, (float)($item['confidence'] ?? .9))),
            'flags' => array_slice(array_values(array_unique(array_filter(array_map('strval', $item['flags'] ?? [])))), 0, 3),
            'reason' => substr((string)($item['reason'] ?? 'Luna retained review status'), 0, 160),
            'alternatives' => $fallback['alternatives'] ?? [],
        ];
    }

    /** Reproduce each AITOOLV3 converter's profile-specific cache-key formula. */
    private function decisionKey(string $profile, string $dictionaryVersion, array $record): string
    {
        if ($profile === 'nrm2-v1') {
            return hash('sha256', implode('|', [
                $profile,
                $dictionaryVersion,
                (string)$record['bill'],
                trim((string)$record['section']),
                (string)$record['description'],
                (string)$record['context'],
            ]));
        }

        $normalize = static function (mixed $value): string {
            $text = str_replace(["\r\n", "\r"], "\n", (string)($value ?? ''));
            $text = trim($text);
            $text = preg_replace('/[ \t\f\x0B]+/u', ' ', $text) ?? $text;
            $text = preg_replace('/\n+/u', "\n", $text) ?? $text;
            return mb_strtolower($text, 'UTF-8');
        };
        $parts = [
            $dictionaryVersion,
            $normalize($record['bill_name'] ?? ''),
            $normalize($record['context'] ?? ''),
            $normalize($record['description'] ?? ''),
            (string)($record['quantity'] ?? ''),
            $normalize($record['unit'] ?? ''),
        ];
        if (str_starts_with($profile, 'wd-work-packages-')) array_unshift($parts, $profile);
        return hash('sha256', implode("\n", $parts));
    }

    private function classifyBatchAdaptive(AiProviderService $provider, string $profile, string $dictionaryVersion, array $batch): array
    {
        try {
            return $provider->classifyRecords($profile, $dictionaryVersion, $batch);
        } catch (Exception $exception) {
            $message = mb_strtolower($exception->getMessage());
            if (count($batch) <= 20 || (!str_contains($message, 'max_output_tokens') && !str_contains($message, 'incomplete'))) throw $exception;
            $merged = ['results' => [], 'input_tokens' => 0, 'cached_input_tokens' => 0, 'output_tokens' => 0];
            foreach (array_chunk($batch, (int)ceil(count($batch) / 2)) as $half) {
                $result = $this->classifyBatchAdaptive($provider, $profile, $dictionaryVersion, $half);
                $merged['results'] = array_merge($merged['results'], $result['results']);
                foreach (['input_tokens', 'cached_input_tokens', 'output_tokens'] as $field) $merged[$field] += $result[$field];
            }
            return $merged;
        }
    }

    private function buildDictionary(array $template): array
    {
        $dictionary = [];
        $targets = [];
        if ($template['is_tiered']) {
            foreach ($template['tier2_map'] as $parentId => $items) foreach ($items as $item) {
                $code = (string)($item['code'] ?? '');
                if ($code === '') continue;
                $parent = array_values(array_filter($template['list'], fn(array $candidate) => $candidate['id'] === $parentId))[0] ?? [];
                if ($template['profile'] === 'nrm2-v1') {
                    $section = (string)($item['section_name'] ?? $parent['section_name'] ?? '');
                    $name = (string)($item['item_name'] ?? $item['name']);
                    $dictionary[$code] = ['code' => $code, 'title' => $section.' — '.$name, 'group' => $section, 'element' => $name, 'scope' => $name];
                } else {
                    $dictionary[$code] = [
                        'code' => $code,
                        'title' => (string)($item['package_name'] ?? $item['name']),
                        'group' => (string)($item['group_name'] ?? $parent['name'] ?? ''),
                        'element' => (string)($item['element_name'] ?? $item['name']),
                        'scope' => (string)($item['scope'] ?? $item['description']),
                    ];
                }
                $targets[$code] = ['target' => $parentId, 'target_tier2' => $item['id']];
            }
        } else foreach ($template['list'] as $item) {
            $code = (string)$item['code'];
            $dictionary[$code] = ['code' => $code, 'title' => $item['name'], 'group' => 'WD Works Packages', 'element' => $item['name'], 'scope' => $item['description']];
            $targets[$code] = ['target' => $item['id']];
        }
        return [$dictionary, $targets];
    }

    private function dictionaryVersion(string $profile, array $dictionary): string
    {
        $prefix = str_starts_with($profile, 'wd-') ? 'WD-template-sha256:' : ($profile === 'nrm2-v1' ? 'NRM2-template-sha256:' : 'NRM1-template-sha256:');
        $lines = array_map(
            fn(array $item) => $profile === 'nrm2-v1' ? $item['code'].'|'.$item['group'].'|'.$item['element'] : $item['code'].'|'.$item['title'].'|'.$item['scope'],
            $dictionary,
        );
        if (str_starts_with($profile, 'wd-work-packages-')) {
            foreach ($this->classifier->wdMappingRules() as $rule) {
                $lines[] = implode('|', [
                    $rule['id'], $rule['type'], $rule['code'], $rule['phrase'], $rule['field'],
                    $rule['action'], $rule['auto_allowed'] ? 'true' : 'false', $rule['priority'], $rule['status'],
                ]);
            }
        }
        return $prefix.hash('sha256', implode("\n", $lines));
    }
}
