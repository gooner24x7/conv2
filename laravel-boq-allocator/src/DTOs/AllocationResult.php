<?php

namespace BoqAllocator\DTOs;

class AllocationResult
{
    public array $metadata;
    public array $workPackages;

    public function __construct(array $metadata, array $workPackages)
    {
        $this->metadata = $metadata;
        $this->workPackages = $workPackages;
    }

    public function toArray(): array
    {
        return [
            'metadata' => $this->metadata,
            'work_packages' => $this->workPackages
        ];
    }

    public function toJson(int $options = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($this->toArray(), $options);
    }
}
