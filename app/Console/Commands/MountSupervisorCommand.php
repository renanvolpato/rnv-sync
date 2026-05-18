<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\SyncStatusChanged;
use App\Models\Account;
use App\Models\MountProcess;
use App\Services\Mount\MountService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Mount supervisor (SPEC F3.1/F3.2 / §8 Process Supervision).
 *
 * Runs every minute:
 *  - Auto-starts mounts for active accounts (also covers app start).
 *  - Detects dead mounts (≤60s) and restarts up to 3 times; after that
 *    marks the account as error and notifies (SPEC §17).
 */
class MountSupervisorCommand extends Command
{
    protected $signature = 'rnvsync:mount-supervisor';

    protected $description = 'Mount active accounts and restart failed mounts';

    public function handle(MountService $mounts, SettingsRepository $settings): int
    {
        // Physical mode uses real files (no FUSE) — nothing to mount.
        if ($settings->isPhysical()) {
            return self::SUCCESS;
        }

        foreach (Account::where('status', Account::STATUS_ACTIVE)->get() as $account) {
            $mp = MountProcess::where('account_id', $account->id)->first();
            $key = "mount_restarts_{$account->id}";

            if ($mp && $mounts->isHealthy($mp)) {
                Cache::forget($key);
                $mp->update(['last_health_check_at' => now()]);

                continue;
            }

            $attempts = (int) Cache::get($key, 0);

            if ($attempts >= config('rnvsync.mount.max_restarts')) {
                $mp?->update(['status' => 'failed']);
                $account->update(['status' => Account::STATUS_ERROR]);
                event(new SyncStatusChanged($account->id, 'failed', 'Mount failed after retries.'));

                continue;
            }

            $mounts->mount($account);
            Cache::put($key, $attempts + 1, now()->addHour());
            $this->info("(Re)mounted account {$account->id} (attempt ".($attempts + 1).').');
        }

        return self::SUCCESS;
    }
}
