<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Sync lifecycle notification (started / completed / failed) — SPEC §10
 * notification categories.
 */
class SyncStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $accountId,
        public string $status,
        public string $message,
    ) {}

    /** @return array<int,Channel> */
    public function broadcastOn(): array
    {
        return [new Channel('rnvsync')];
    }

    public function broadcastAs(): string
    {
        return 'sync.status';
    }
}
