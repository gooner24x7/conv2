<?php
class GeminiCloudAiService {
    private $apiKey;
    private $model;

    public $lastUsageMetadata = [];

    public function __construct($apiKey, $model = 'gemini-3.5-flash') {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    public function prompt($systemPrompt, $userPrompt, $maxTokens = 8192) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        $data = [
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
                'maxOutputTokens' => $maxTokens,
                'temperature' => 0.1,
                'responseMimeType' => 'application/json'
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        // Add timeout since it's a network request
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            echo "  [CURL ERROR] $error\n";
            return '';
        }

        $result = json_decode($response, true);
        
        if (isset($result['error'])) {
            echo "  [API ERROR] " . $result['error']['message'] . "\n";
            return '';
        }

        if (isset($result['usageMetadata'])) {
            $this->lastUsageMetadata = $result['usageMetadata'];
        }

        return $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }
}
