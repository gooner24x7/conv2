<?php

namespace BoqAllocator\Services;

class DecisionCacheService
{
    private array $entries = [];
    private bool $dirty = false;

    public function __construct(private readonly string $path = '')
    {
        if ($path === '' || !is_file($path)) return;
        $raw = file_get_contents($path);
        if (!is_string($raw)) return;
        if (str_starts_with($raw, "<?php exit; ?>\n")) $raw = substr($raw, 15);
        $decoded = json_decode($raw, true);
        if (is_array($decoded['entries'] ?? null)) $this->entries = $decoded['entries'];
    }

    public function get(string $key, string $dictionaryVersion): ?array
    {
        $entry = $this->entries[$key] ?? null;
        if (!is_array($entry) || ($entry['dictionary_version'] ?? '') !== $dictionaryVersion || !is_array($entry['decision'] ?? null)) return null;
        return $entry;
    }

    public function put(string $key, string $dictionaryVersion, array $decision, string $model): void
    {
        if ($this->path === '') return;
        $this->entries[$key] = [
            'dictionary_version' => $dictionaryVersion,
            'decision' => $decision,
            'model' => $model,
            'locked' => true,
            'updated_at' => gmdate('c'),
        ];
        $this->dirty = true;
    }

    public function save(): bool
    {
        if (!$this->dirty || $this->path === '') return true;
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) return false;
        $handle = fopen($this->path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) return false;
        rewind($handle);
        $raw = stream_get_contents($handle);
        if (is_string($raw) && str_starts_with($raw, "<?php exit; ?>\n")) $raw = substr($raw, 15);
        $existing = is_string($raw) ? json_decode($raw, true) : null;
        $entries = is_array($existing['entries'] ?? null) ? array_replace($existing['entries'], $this->entries) : $this->entries;
        $json = json_encode(['version' => 'AITOOLV3-DECISION-CACHE-1.0', 'entries' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        rewind($handle);
        $written = $json !== false && ftruncate($handle, 0) && fwrite($handle, "<?php exit; ?>\n" . $json) !== false && fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        if ($written) $this->dirty = false;
        return $written;
    }
}
