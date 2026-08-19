<?php

class QwenLocalAiService
{
    protected string $binaryPath;
    protected string $modelPath;
    protected int $gpuLayers;
    protected float $temperature;

    public function __construct(string $binaryPath, string $modelPath, int $gpuLayers = 99, float $temperature = 0.1)
    {
        $this->binaryPath = $binaryPath;
        $this->modelPath = $modelPath;
        $this->gpuLayers = $gpuLayers;
        $this->temperature = $temperature;
    }

    public function isAvailable(): bool
    {
        return file_exists($this->binaryPath) && file_exists($this->modelPath);
    }

    public function prompt(string $systemPrompt, string $userContent, int $maxTokens = 500): ?string
    {
        if (! $this->isAvailable()) {
            throw new Exception("Local AI is not available. Please verify binaryPath ({$this->binaryPath}) and modelPath ({$this->modelPath}).");
        }

        $formattedPrompt = "<|im_start|>system\n{$systemPrompt}<|im_end|>\n<|im_start|>user\n{$userContent}<|im_end|>\n<|im_start|>assistant\n";

        $tempPromptFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'temp_prompt_'.uniqid().'.txt';
        file_put_contents($tempPromptFile, $formattedPrompt);

        $stderrRedirect = stristr(PHP_OS, 'WIN') ? '2>nul' : '2>/dev/null';
        $cmd = sprintf(
            '%s -m %s -f %s -n %d --temp %.2f --no-display-prompt -ngl %d -st %s',
            escapeshellarg($this->binaryPath),
            escapeshellarg($this->modelPath),
            escapeshellarg($tempPromptFile),
            $maxTokens,
            $this->temperature,
            $this->gpuLayers,
            $stderrRedirect
        );

        $output = @shell_exec($cmd);

        if (file_exists($tempPromptFile)) {
            @unlink($tempPromptFile);
        }

        return $output ? trim($output) : null;
    }

    public function extractList(string $systemPrompt, string $userContent, int $maxTokens = 500): array
    {
        $response = $this->prompt($systemPrompt, $userContent, $maxTokens);
        
        if (! $response) {
            return [];
        }

        $mappings = [];
        // Look for simple pattern: wp_top_xxx -> wd_top_yyy or wp_top_xxx, wd_top_yyy
        $lines = explode("\n", $response);
        foreach ($lines as $line) {
            if (preg_match('/(wp_top_[a-z0-9]+)[\s,>-]+(wd_top_[a-z0-9]+)/i', $line, $matches)) {
                $mappings[$matches[1]] = $matches[2];
            }
        }
        return $mappings;
    }
}
