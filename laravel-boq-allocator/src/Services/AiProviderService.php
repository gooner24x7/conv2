<?php

namespace BoqAllocator\Services;

use Exception;

class AiProviderService
{
    protected string $modelKey;
    protected array $config;
    protected array $modelCatalog;

    public function __construct(?string $modelKey = null, array $config = [])
    {
        $this->config = $config ?: (function_exists('config') ? config('boq-allocator', []) : []);
        $this->modelKey = $modelKey ?: ($this->config['default_model'] ?? getenv('BOQ_DEFAULT_MODEL') ?: 'gemini-3.6-flash');

        // Fallback for standalone usage
        if (empty($this->config['api_keys'])) {
            $this->config['api_keys'] = [
                'gemini' => getenv('GEMINI_API_KEY') ?: '',
                'openai' => getenv('OPENAI_API_KEY') ?: '',
                'anthropic' => getenv('ANTHROPIC_API_KEY') ?: '',
            ];
        }

        $this->modelCatalog = [
            'gpt-5.6-luna' => [
                'provider' => 'openai',
                'name' => getenv('OPENAI_MODEL') ?: 'gpt-4o-mini',
                'label' => 'OpenAI GPT-5.6 Luna',
                'cost_in_per_m' => 0.20,
                'cost_out_per_m' => 1.20
            ],
            'gemini-3.6-flash' => [
                'provider' => 'gemini',
                'name' => 'gemini-3.6-flash',
                'label' => 'Google Gemini 3.6 Flash (Fast & Balanced)',
                'cost_in_per_m' => 0.075,
                'cost_out_per_m' => 0.30
            ],
            'gemini-3.7-flash' => [
                'provider' => 'gemini',
                'name' => 'gemini-3.7-flash',
                'label' => 'Google Gemini 3.7 Flash (Ultra-Fast)',
                'cost_in_per_m' => 0.075,
                'cost_out_per_m' => 0.30
            ],
            'gemini-3.5-flash' => [
                'provider' => 'gemini',
                'name' => 'gemini-3.5-flash',
                'label' => 'Google Gemini 3.5 Flash',
                'cost_in_per_m' => 0.10,
                'cost_out_per_m' => 0.40
            ],
            'gemini-3.5-flash-lite' => [
                'provider' => 'gemini',
                'name' => 'gemini-3.5-flash-lite',
                'label' => 'Google Gemini 3.5 Flash-Lite',
                'cost_in_per_m' => 0.075,
                'cost_out_per_m' => 0.30
            ],
            'gemini-3.1-pro' => [
                'provider' => 'gemini',
                'name' => 'gemini-3.1-pro',
                'label' => 'Google Gemini 3.1 Pro (Deep Reasoning)',
                'cost_in_per_m' => 1.25,
                'cost_out_per_m' => 5.00
            ],
            'openai-sol' => [
                'provider' => 'openai',
                'name' => 'gpt-4o',
                'label' => 'OpenAI GPT-5.6 Sol (Flagship)',
                'cost_in_per_m' => 2.50,
                'cost_out_per_m' => 10.00
            ],
            'openai-terra' => [
                'provider' => 'openai',
                'name' => 'gpt-4o',
                'label' => 'OpenAI GPT-5.6 Terra (Balanced)',
                'cost_in_per_m' => 0.50,
                'cost_out_per_m' => 2.00
            ],
            'openai-luna' => [
                'provider' => 'openai',
                'name' => 'gpt-4o-mini',
                'label' => 'OpenAI GPT-5.6 Luna (Lightweight)',
                'cost_in_per_m' => 0.15,
                'cost_out_per_m' => 0.60
            ],
            'claude-3-7-sonnet' => [
                'provider' => 'anthropic',
                'name' => 'claude-3-7-sonnet-20250219',
                'label' => 'Anthropic Claude 3.7 Sonnet',
                'cost_in_per_m' => 3.00,
                'cost_out_per_m' => 15.00
            ]
        ];
    }

    public function getModelLabel(): string
    {
        return $this->modelCatalog[$this->modelKey]['label'] ?? $this->modelKey;
    }

    public function getProvider(): string
    {
        return $this->modelCatalog[$this->modelKey]['provider'] ?? 'gemini';
    }

    public function calculateCost(int $inTokens, int $outTokens): float
    {
        $meta = $this->modelCatalog[$this->modelKey] ?? [
            'cost_in_per_m' => 0.10,
            'cost_out_per_m' => 0.40
        ];
        return ($inTokens / 1000000.0 * $meta['cost_in_per_m']) + ($outTokens / 1000000.0 * $meta['cost_out_per_m']);
    }

    public function sendChat(string $systemPrompt, string $userPrompt): array
    {
        $provider = $this->getProvider();
        $modelName = $this->modelCatalog[$this->modelKey]['name'] ?? 'gemini-2.5-flash';

        if ($provider === 'gemini') {
            return $this->callGemini($modelName, $systemPrompt, $userPrompt);
        } elseif ($provider === 'openai') {
            return $this->callOpenAi($modelName, $systemPrompt, $userPrompt);
        } elseif ($provider === 'anthropic') {
            return $this->callAnthropic($modelName, $systemPrompt, $userPrompt);
        }

        throw new Exception("Unsupported AI provider: $provider");
    }

    /**
     * AITOOLV3-compatible classification through OpenAI Responses Structured Outputs.
     */
    public function classifyRecords(string $profile, string $dictionaryVersion, array $records): array
    {
        $apiKey = $this->config['api_keys']['openai'] ?? '';
        if ($apiKey === '') throw new Exception('OPENAI_API_KEY is not configured.');
        $isWd = str_starts_with($profile, 'wd-work-packages-');
        $isNrm2 = $profile === 'nrm2-v1';
        $systemPrompt = $isWd
            ? 'Classify UK construction BOQ records against the supplied WD works-package candidates and their exact inclusion and exclusion scopes. Return AUTO with one supplied code only when the record is clearly within that package. Return UNALLOCATED with an empty code when none of the supplied candidates covers the work. Return REVIEW with an empty code or the best supplied code only when evidence is genuinely insufficient. Never invent a package ID, force out-of-scope work into a package, split a row, alter source information, or infer quantity, rate or extension. Return one result for every item_id in the same order. Keep reason to 12 words or fewer and flags only when necessary. No prose.'
            : ($isNrm2
                ? 'Classify each UK construction BOQ record into one supplied NRM2 work-section/work-item candidate. Return AUTO with exactly one supplied code only when the description and context support that work item. Otherwise return REVIEW and an empty code or best supplied code. Never invent a code, force a match, split a row, alter source information, or infer quantity, rate or extension. Return one result for every item_id in the same order. Keep reason to 12 words or fewer and flags only when necessary. No prose.'
                : 'Classify UK construction BOQ records into the supplied NRM1 candidate packages. Select exactly one supplied candidate or leave REVIEW. Never invent a code, split a row, alter source information, or infer quantity, rate or extension. Use AUTO only when the supplied BOQ text is unambiguous. Return one result for every item_id in the same order. Keep reason to 12 words or fewer and flags only when necessary. No prose.');
        $modelRecords = array_map(fn(array $record) => [
            'item_id'=>(string)$record['item_id'],'bill'=>(int)$record['bill'],'bill_name'=>(string)$record['bill_name'],
            'section'=>(string)$record['section'],'description'=>(string)$record['description'],'context'=>(string)$record['context'],
            'quantity'=>$record['quantity'] ?? '','unit'=>(string)($record['unit'] ?? ''),'candidates'=>$record['candidates'],
        ], $records);
        $statuses = $isWd ? ['AUTO','REVIEW','UNALLOCATED'] : ['AUTO','REVIEW'];
        $schema = ['type'=>'object','additionalProperties'=>false,'required'=>['results'],'properties'=>['results'=>[
            'type'=>'array','minItems'=>count($records),'maxItems'=>count($records),'items'=>[
                'type'=>'object','additionalProperties'=>false,'required'=>['item_id','code','status','confidence','flags','reason'],
                'properties'=>[
                    'item_id'=>['type'=>'string'],'code'=>['type'=>'string'],'status'=>['type'=>'string','enum'=>$statuses],
                    'confidence'=>['type'=>'number','enum'=>[0,.9,.95,.98]],
                    'flags'=>['type'=>'array','items'=>['type'=>'string'],'maxItems'=>2],
                    'reason'=>['type'=>'string','maxLength'=>80],
                ],
            ],
        ]]];
        $input = [
            ['role'=>'system','content'=>$systemPrompt],
            ['role'=>'user','content'=>json_encode(['classification_profile'=>$profile,'dictionary_version'=>$dictionaryVersion,'records'=>$modelRecords], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)],
        ];
        $maxOutputTokens = min(16000, max(1500, count($records) * 90));
        $estimatedInputTokens = (int)ceil(strlen((string)json_encode($input)) / 4);
        $estimatedMaxCost = ($estimatedInputTokens * .20 + $maxOutputTokens * 1.20) / 1000000;
        $targetModel = $this->modelCatalog[$this->modelKey]['name'] ?? $this->modelKey;
        $requestPayload = [
            'model' => $targetModel,
            'store' => false,
            'prompt_cache_key' => hash('sha256', $profile . ':' . $dictionaryVersion),
            'max_output_tokens' => $maxOutputTokens,
            'input' => $input,
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'boq_package_classification', 'strict' => true, 'schema' => $schema]],
        ];
        if (str_starts_with($targetModel, 'o1') || str_starts_with($targetModel, 'o3') || str_starts_with($targetModel, 'o4')) {
            $requestPayload['reasoning'] = ['effort' => 'low'];
        }
        $response = $this->httpPost('https://api.openai.com/v1/responses', $requestPayload, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
        if (($response['status'] ?? '') === 'incomplete') throw new Exception('OpenAI returned an incomplete response.');
        $rawText = $response['output_text'] ?? '';
        if ($rawText === '') foreach (($response['output'] ?? []) as $output) foreach (($output['content'] ?? []) as $content) if (isset($content['text'])) { $rawText = $content['text']; break 2; }
        $parsed = json_decode(trim((string)$rawText), true);
        if (!is_array($parsed['results'] ?? null)) throw new Exception('OpenAI structured output did not contain the required results array.');
        return [
            'results'=>$parsed['results'],
            'input_tokens'=>(int)($response['usage']['input_tokens'] ?? 0),
            'cached_input_tokens'=>(int)($response['usage']['input_tokens_details']['cached_tokens'] ?? 0),
            'output_tokens'=>(int)($response['usage']['output_tokens'] ?? 0),
        ];
    }

    protected function callGemini(string $model, string $systemPrompt, string $userPrompt): array
    {
        $apiKey = $this->config['api_keys']['gemini'] ?? '';
        if (empty($apiKey)) {
            throw new Exception("GEMINI_API_KEY is not configured.");
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);
        $payload = [
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]]
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $userPrompt]]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'responseMimeType' => 'application/json'
            ]
        ];

        $res = $this->httpPost($url, $payload, ['Content-Type: application/json']);
        $rawText = $res['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $usage = $res['usageMetadata'] ?? [];

        return [
            'text' => $rawText,
            'input_tokens' => (int)($usage['promptTokenCount'] ?? (strlen($systemPrompt . $userPrompt) / 4)),
            'output_tokens' => (int)($usage['candidatesTokenCount'] ?? (strlen($rawText) / 4))
        ];
    }

    protected function callOpenAi(string $model, string $systemPrompt, string $userPrompt): array
    {
        $apiKey = $this->config['api_keys']['openai'] ?? '';
        if (empty($apiKey)) {
            throw new Exception("OPENAI_API_KEY is not configured.");
        }

        $url = "https://api.openai.com/v1/chat/completions";
        $payload = [
            'model' => $model,
            'temperature' => 0.1,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt]
            ],
            'response_format' => ['type' => 'json_object']
        ];

        $res = $this->httpPost($url, $payload, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ]);

        $rawText = $res['choices'][0]['message']['content'] ?? '';
        $usage = $res['usage'] ?? [];

        return [
            'text' => $rawText,
            'input_tokens' => (int)($usage['prompt_tokens'] ?? (strlen($systemPrompt . $userPrompt) / 4)),
            'output_tokens' => (int)($usage['completion_tokens'] ?? (strlen($rawText) / 4))
        ];
    }

    protected function callAnthropic(string $model, string $systemPrompt, string $userPrompt): array
    {
        $apiKey = $this->config['api_keys']['anthropic'] ?? '';
        if (empty($apiKey)) {
            throw new Exception("ANTHROPIC_API_KEY is not configured.");
        }

        $url = "https://api.anthropic.com/v1/messages";
        $payload = [
            'model' => $model,
            'max_tokens' => 4096,
            'temperature' => 0.1,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt]
            ]
        ];

        $res = $this->httpPost($url, $payload, [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01'
        ]);

        $rawText = $res['content'][0]['text'] ?? '';
        $usage = $res['usage'] ?? [];

        return [
            'text' => $rawText,
            'input_tokens' => (int)($usage['input_tokens'] ?? (strlen($systemPrompt . $userPrompt) / 4)),
            'output_tokens' => (int)($usage['output_tokens'] ?? (strlen($rawText) / 4))
        ];
    }

    protected function httpPost(string $url, array $data, array $headers): array
    {
        $maxRetries = 5;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 180,
                CURLOPT_SSL_VERIFYPEER => false
            ]);

            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $isRetryableCode = in_array($code, [429, 500, 502, 503, 504]);

            if ($err || $isRetryableCode) {
                $attempt++;
                if ($attempt >= $maxRetries) {
                    $errorMsg = $err ? "cURL Error: $err" : "API HTTP $code Error: " . substr($resp, 0, 500);
                    throw new Exception($errorMsg . " (Failed after $maxRetries retries)");
                }
                // Exponential backoff: 2s, 4s, 8s, 16s
                sleep(pow(2, $attempt));
                continue;
            }

            if ($code >= 400) {
                throw new Exception("API HTTP $code Error: " . substr($resp, 0, 500));
            }

            $decoded = json_decode($resp, true);
            if ($decoded === null) {
                throw new Exception("Invalid JSON response from AI API.");
            }

            return $decoded;
        }

        throw new Exception("Unexpected error in httpPost.");
    }
}
