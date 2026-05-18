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
        // Physical mode uses real files (no FUSE). Self-heal: if a stale
        // rclone FUSE mount is left over (e.g. from a previous mount-mode
        // run), unmount it so the folder works as a normal directory and
        // the file manager doesn't error ("Transport endpoint is not
        // connected").
        if ($settings->isPhysical()) {
            foreach (Account::all() as $account) {
                $base = rtrim($settings->mountBase(), '/').'/'.$account->name;
                $isStale = trim((string) shell_exec(
                    'mount 2>/dev/null | grep -F '.escapeshellarg(' '.$base.' ').' | grep -c fuse.rclone'
                ));
                if ($isStale !== '' && $isStale !== '0') {
                    shell_exec('fusermount -u '.escapeshellarg($base).' 2>/dev/null');
                    shell_exec('fusermount -uz '.escapeshellarg($base).' 2>/dev/null');
                    $this->info("Unmounted stale FUSE mount: {$base}");
                }
            }

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
