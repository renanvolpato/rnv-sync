<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Real-time sync progress (SPEC F2.7). Emitted at least every 2 seconds
 * while a sync is in progress.
 */
class SyncProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $accountId,
        public ?int $syncFolderId,
        public string $currentFile,
        public float $percent,
        public string $speed,
    ) {}

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new Channel('rnvsync')];
    }

    public function broadcastAs(): string
    {
        return 'sync.progress';
    }
}
