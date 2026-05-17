<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\UsageSnapshot;
use App\Services\Cache\CacheService;
use Illuminate\Console\Command;

/** Daily storage usage snapshot for trends (SPEC F5.5). */
class CaptureUsageCommand extends Command
{
    protected $signature = 'rnvsync:capture-usage';

    protected $description = 'Capture a daily storage usage snapshot';

    public function handle(CacheService $cache): int
    {
        $cacheUsage = $cache->usageBytes();

        foreach (Account::all() as $account) {
            UsageSnapshot::updateOrCreate(
                ['account_id' => $account->id, 'captured_on' => today()],
                [
                    'cloud_used_bytes' => $account->quota_used_bytes ?? 0,
                    'cloud_total_bytes' => $account->quota_total_bytes ?? 0,
                    'cache_used_bytes' => $cacheUsage,
                ],
            );
        }

        $this->info('Usage snapshot captured.');

        return self::SUCCESS;
    }
}
