<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Account;
use App\Services\Cache\CacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Downloads a pinned ("always offline") path into the VFS cache in the
 * background (SPEC F3.6) so the UI never blocks — a pinned folder can be
 * very large.
 */
class WarmCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 3600;

    public function __construct(
        public int $accountId,
        public string $path,
    ) {}

    public function handle(CacheService $cache): void
    {
        $account = Account::find($this->accountId);

        if ($account) {
            $cache->warm($account, $this->path);
        }
    }
}
