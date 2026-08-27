<?php

namespace BoqAllocator\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BoqAllocationProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $jobId;
    public string $message;
    public int $percentage;
    public ?array $metadata;

    public function __construct(string $jobId, string $message, int $percentage = 0, ?array $metadata = null)
    {
        $this->jobId = $jobId;
        $this->message = $message;
        $this->percentage = $percentage;
        $this->metadata = $metadata;
    }

    /**
     * The channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('boq-allocation.' . $this->jobId),
        ];
    }

    /**
     * Broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'progress';
    }
}
