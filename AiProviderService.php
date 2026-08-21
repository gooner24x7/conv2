<?php
/**
 * Universal AI Provider Dispatcher
 * Supports: Gemini, OpenAI, Anthropic Claude, and Local Llama.cpp
 */
class AiProviderService {
    private $modelKey;
    private $geminiKey;
    private $openaiKey;
    private $anthropicKey;

    public $lastUsage = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'total_tokens' => 0,
        'estimated_cost' => 0.0
    ];

    public function __construct(string $modelKey = 'gemini-flash') {
        $this->modelKey = $modelKey;
        $this->geminiKey = getenv('GEMINI_API_KEY') ?: '';
        $this->openaiKey = getenv('OPENAI_API_KEY') ?: '';
        $this->anthropicKey = getenv('ANTHROPIC_API_KEY') ?: '';
    }

    public function getModelLabel(): string {
        $labels = [
            'gemini-3.7-flash'      => 'Google Gemini 3.7 Flash',
            'gemini-3.6-flash'      => 'Google Gemini 3.6 Flash',
            'gemini-3.5-flash'      => 'Google Gemini 3.5 Flash',
            'gemini-3.5-flash-lite' => 'Google Gemini 3.5 Flash-Lite',
            'gemini-3.1-pro'        => 'Google Gemini 3.1 Pro',
            'gemini-flash'          => 'Google Gemini 3.6 Flash',
            'gemini-pro'            => 'Google Gemini 3.1 Pro',
            'openai-sol'            => 'OpenAI GPT-5.6 Sol',
            'openai-terra'          => 'OpenAI GPT-5.6 Terra',
            'openai-luna'           => 'OpenAI GPT-5.6 Luna',
            'claude-opus'           => 'Anthropic Claude Opus',
            'claude-sonnet'         => 'Anthropic Claude 3.7 Sonnet'
        ];
        if (isset($labels[$this->modelKey])) {
            return $labels[$this->modelKey];
        }
        if (strpos($this->modelKey, 'gemini-') === 0) {
            return 'Google Gemini (' . $this->modelKey . ')';
        }
        return $this->modelKey;
    }

    public function prompt(string $systemPrompt, string $userPrompt): string {
        switch ($this->modelKey) {
            case 'gemini-3.7-flash':
                return $this->callGemini('gemini-3.7-flash', $systemPrompt, $userPrompt, 0.75, 3.75);
            case 'gemini-3.6-flash':
            case 'gemini-flash':
                return $this->callGemini('gemini-3.6-flash', $systemPrompt, $userPrompt, 1.50, 7.50);
            case 'gemini-3.5-flash':
                return $this->callGemini('gemini-3.5-flash', $systemPrompt, $userPrompt, 1.50, 9.00);
            case 'gemini-3.5-flash-lite':
                return $this->callGemini('gemini-3.5-flash-lite', $systemPrompt, $userPrompt, 0.075, 0.30);
            case 'gemini-3.1-pro':
            case 'gemini-pro':
                return $this->callGemini('gemini-3.1-pro-preview', $systemPrompt, $userPrompt, 2.00, 12.00);
            case 'openai-sol':
                return $this->callOpenAI('gpt-5.6-sol', $systemPrompt, $userPrompt, 5.00, 30.00);
            case 'openai-terra':
                return $this->callOpenAI('gpt-5.6-terra', $systemPrompt, $userPrompt, 2.00, 12.00);
            case 'openai-luna':
                return $this->callOpenAI('gpt-5.6-luna', $systemPrompt, $userPrompt, 0.20, 1.20);
            case 'claude-opus':
                return $this->callAnthropic('claude-3-opus-20240229', $systemPrompt, $userPrompt, 5.00, 25.00);
            case 'claude-sonnet':
                return $this->callAnthropic('claude-3-5-sonnet-20241022', $systemPrompt, $userPrompt, 0.59, 2.93);
            default:
                if (strpos($this->modelKey, 'gemini-') === 0) {
                    return $this->callGemini($this->modelKey, $systemPrompt, $userPrompt, 1.50, 7.50);
                }
                return $this->callGemini('gemini-3.6-flash', $systemPrompt, $userPrompt, 1.50, 7.50);
        }
    }

    private function callGemini(string $model, string $systemPrompt, string $userPrompt, float $inPriceM, float $outPriceM): string {
        if (empty($this->geminiKey)) {
            throw new Exception("GEMINI_API_KEY is not configured in .env");
        }
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$this->geminiKey}";
        $payload = [
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
            'generationConfig' => [
                'maxOutputTokens' => 8192,
                'temperature' => 0.1,
                'responseMimeType' => 'application/json'
            ]
        ];

        $maxRetries = 5;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $attempt++;
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);

            $res = curl_exec($ch);
            $err = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($err) throw new Exception("Gemini cURL Error: $err");

            $data = json_decode($res, true);

            // Handle Rate Limits, High Demand, and Server Errors (HTTP 429, 503, 500)
            if (isset($data['error'])) {
                $msg = $data['error']['message'];
                $isRetryable = stripos($msg, 'quota') !== false || stripos($msg, 'rate') !== false || stripos($msg, 'demand') !== false || in_array($httpCode, [429, 500, 503]);
                
                if ($isRetryable) {
                    if ($attempt < $maxRetries) {
                        preg_match('/retry in ([0-9.]+)s/i', $msg, $m);
                        $sleepSec = isset($m[1]) ? ((int)ceil((float)$m[1]) + 2) : 15;
                        sleep($sleepSec);
                        continue;
                    }
                }
                throw new Exception("Gemini API Error: " . $msg);
            }

            $in = $data['usageMetadata']['promptTokenCount'] ?? 0;
            $out = $data['usageMetadata']['candidatesTokenCount'] ?? 0;
            $total = $data['usageMetadata']['totalTokenCount'] ?? ($in + $out);

            $this->lastUsage = [
                'input_tokens' => $in,
                'output_tokens' => $out,
                'total_tokens' => $total,
                'estimated_cost' => ($in / 1000000) * $inPriceM + ($out / 1000000) * $outPriceM
            ];

            return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        }

        throw new Exception("Gemini API Error: Max retries exceeded due to rate limits.");
    }

    private function callOpenAI(string $model, string $systemPrompt, string $userPrompt, float $inPriceM, float $outPriceM): string {
        if (empty($this->openaiKey) || strpos($this->openaiKey, 'your_') === 0) {
            throw new Exception("OPENAI_API_KEY is not configured in .env");
        }
        $url = "https://api.openai.com/v1/chat/completions";
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt]
            ],
            'temperature' => 1
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->openaiKey
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) throw new Exception("OpenAI cURL Error: $err");

        $data = json_decode($res, true);
        if (isset($data['error'])) throw new Exception("OpenAI API Error: " . $data['error']['message']);

        $in = $data['usage']['prompt_tokens'] ?? 0;
        $out = $data['usage']['completion_tokens'] ?? 0;
        $total = $data['usage']['total_tokens'] ?? ($in + $out);

        $this->lastUsage = [
            'input_tokens' => $in,
            'output_tokens' => $out,
            'total_tokens' => $total,
            'estimated_cost' => ($in / 1000000) * $inPriceM + ($out / 1000000) * $outPriceM
        ];

        return $data['choices'][0]['message']['content'] ?? '';
    }

    private function callAnthropic(string $model, string $systemPrompt, string $userPrompt, float $inPriceM, float $outPriceM): string {
        if (empty($this->anthropicKey) || strpos($this->anthropicKey, 'your_') === 0) {
            throw new Exception("ANTHROPIC_API_KEY is not configured in .env");
        }
        $url = "https://api.anthropic.com/v1/messages";
        $payload = [
            'model' => $model,
            'system' => $systemPrompt,
            'messages' => [['role' => 'user', 'content' => $userPrompt]],
            'max_tokens' => 4096,
            'temperature' => 0.1
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-key: ' . $this->anthropicKey,
            'anthropic-version: 2023-06-01'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) throw new Exception("Anthropic cURL Error: $err");

        $data = json_decode($res, true);
        if (isset($data['error'])) throw new Exception("Anthropic API Error: " . $data['error']['message']);

        $in = $data['usage']['input_tokens'] ?? 0;
        $out = $data['usage']['output_tokens'] ?? 0;
        $total = $in + $out;

        $this->lastUsage = [
            'input_tokens' => $in,
            'output_tokens' => $out,
            'total_tokens' => $total,
            'estimated_cost' => ($in / 1000000) * $inPriceM + ($out / 1000000) * $outPriceM
        ];

        return $data['content'][0]['text'] ?? '';
    }

    }
