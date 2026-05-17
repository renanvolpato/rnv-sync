<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** SPEC F4.4/F4.7: a new conflict was detected. */
class ConflictDetected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $accountId,
        public int $pendingCount,
    ) {}

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new Channel('rnvsync')];
    }

    public function broadcastAs(): string
    {
        return 'conflict.detected';
    }
}
