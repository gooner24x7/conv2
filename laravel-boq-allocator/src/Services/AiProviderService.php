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
        $this->config = $config ?: config('boq-allocator', []);
        $this->modelKey = $modelKey ?: ($this->config['default_model'] ?? 'gemini-3.6-flash');

        $this->modelCatalog = [
            'gemini-3.6-flash' => [
                'provider' => 'gemini',
                'name' => 'gemini-2.5-flash',
                'label' => 'Google Gemini 3.6 Flash (Fast & Balanced)',
                'cost_in_per_m' => 0.075,
                'cost_out_per_m' => 0.30
            ],
            'gemini-3.7-flash' => [
                'provider' => 'gemini',
                'name' => 'gemini-2.5-flash',
                'label' => 'Google Gemini 3.7 Flash (Ultra-Fast)',
                'cost_in_per_m' => 0.075,
                'cost_out_per_m' => 0.30
            ],
            'gemini-3.5-flash' => [
                'provider' => 'gemini',
                'name' => 'gemini-2.0-flash',
                'label' => 'Google Gemini 3.5 Flash',
                'cost_in_per_m' => 0.10,
                'cost_out_per_m' => 0.40
            ],
            'gemini-3.5-flash-lite' => [
                'provider' => 'gemini',
                'name' => 'gemini-2.0-flash-lite',
                'label' => 'Google Gemini 3.5 Flash-Lite',
                'cost_in_per_m' => 0.075,
                'cost_out_per_m' => 0.30
            ],
            'gemini-3.1-pro' => [
                'provider' => 'gemini',
                'name' => 'gemini-2.5-pro',
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

    protected function callGemini(string $model, string $systemPrompt, string $userPrompt): array
    {
        $apiKey = $this->config['api_keys']['gemini'] ?? env('GEMINI_API_KEY', '');
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
        $apiKey = $this->config['api_keys']['openai'] ?? env('OPENAI_API_KEY', '');
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
        $apiKey = $this->config['api_keys']['anthropic'] ?? env('ANTHROPIC_API_KEY', '');
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
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 180
        ]);

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new Exception("cURL Error: $err");
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
}
