<?php

namespace BoqAllocator\Jobs;

use BoqAllocator\Events\BoqAllocationProgress;
use BoqAllocator\Services\BoqAllocationEngine;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessBoqAllocationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $jobId;
    public string $boqPath;
    public string $templatePath;
    public ?string $modelKey;
    public ?string $customRules;
    public ?string $outputPath;

    public int $timeout = 600; // 10 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        string $jobId,
        string $boqPath,
        string $templatePath,
        ?string $modelKey = null,
        ?string $customRules = null,
        ?string $outputPath = null
    ) {
        $this->jobId = $jobId;
        $this->boqPath = $boqPath;
        $this->templatePath = $templatePath;
        $this->modelKey = $modelKey;
        $this->customRules = $customRules;
        $this->outputPath = $outputPath;
    }

    /**
     * Execute the job.
     */
    public function handle(BoqAllocationEngine $engine): void
    {
        try {
            $result = $engine->allocate(
                $this->boqPath,
                $this->templatePath,
                $this->modelKey,
                $this->customRules,
                function (string $message, int $pct) {
                    event(new BoqAllocationProgress($this->jobId, $message, $pct));
                }
            );

            // Save result output
            $json = $result->toJson();
            if ($this->outputPath) {
                file_put_contents($this->outputPath, $json);
            } else {
                Storage::put("allocations/{$this->jobId}.json", $json);
            }

            // Dispatch final completion event
            event(new BoqAllocationProgress(
                $this->jobId,
                "Allocation completed successfully.",
                100,
                $result->metadata
            ));
        } catch (Exception $e) {
            event(new BoqAllocationProgress(
                $this->jobId,
                "Error: " . $e->getMessage(),
                -1,
                ['error' => $e->getMessage()]
            ));
            throw $e;
        }
    }
}
