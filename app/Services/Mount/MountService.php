<?php

declare(strict_types=1);

namespace App\Services\Mount;

use App\Models\Account;
use App\Models\MountProcess;
use App\Services\Rclone\RcloneConfigGenerator;
use App\Services\Rclone\RcloneRunner;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * FUSE mount lifecycle via `rclone mount` (SPEC F3.1/F3.2).
 *
 * Each active account is mounted at {mount_base}/{account name}. Mount
 * processes are tracked in rnvsync_mount_processes by PID so a scheduled
 * health check can restart dead mounts (SPEC §8 Process Supervision).
 */
class MountService
{
    public function __construct(
        private readonly RcloneRunner $rclone,
        private readonly RcloneConfigGenerator $configGenerator,
        private readonly SettingsRepository $settings,
    ) {}

    public function mountPoint(Account $account): string
    {
        return rtrim($this->settings->mountBase(), '/').'/'.$account->name;
    }

    /**
     * Cache size limit in bytes (SPEC F3.3 / §17): 10% of free disk
     * space, clamped to [1 GB, 20 GB], or an explicit user override.
     */
    public function cacheLimitBytes(): int
    {
        $gb = 1024 ** 3;

        $override = $this->settings->get('cache_max_gb');
        if ($override) {
            return (int) $override * $gb;
        }

        $cfg = config('rnvsync.defaults.cache');
        $free = @disk_free_space(storage_path()) ?: (10 * $gb);

        $bytes = (int) ($free * $cfg['free_space_fraction']);

        return max($cfg['min_gb'] * $gb, min($cfg['max_gb'] * $gb, $bytes));
    }

    /**
     * @return list<string>
     */
    public function buildMountArgs(Account $account): array
    {
        $cfg = config('rnvsync.mount');
        $remote = $account->remote_name.':';
        $point = $this->mountPoint($account);
        $cacheDir = config('rnvsync.rclone.cache_dir');
        $logFile = storage_path("logs/rnvsync-mount-{$account->id}.log");

        return [
            'mount', $remote, $point,
            '--vfs-cache-mode='.$cfg['vfs_cache_mode'],
            '--vfs-cache-max-size='.$this->cacheLimitBytes(),
            '--vfs-cache-max-age='.$cfg['vfs_cache_max_age'],
            '--vfs-read-ahead='.$cfg['vfs_read_ahead'],
            '--buffer-size='.$cfg['buffer_size'],
            '--dir-cache-time='.$cfg['dir_cache_time'],
            '--poll-interval='.$cfg['poll_interval'],
            '--tpslimit='.$cfg['tpslimit'],
            '--tpslimit-burst='.$cfg['tpslimit_burst'],
            '--cache-dir='.$cacheDir,
            '--allow-non-empty',
            // Friendly label in the file manager instead of the raw
            // remote name (e.g. "RNV Sync — My OneDrive").
            '--volname=RNV Sync — '.$account->name,
            '--log-file='.$logFile,
        ];
    }

    public function mount(Account $account): MountProcess
    {
        $point = $this->mountPoint($account);
        File::ensureDirectoryExists($point);
        File::ensureDirectoryExists(config('rnvsync.rclone.cache_dir'));

        $this->configGenerator->regenerate();

        $pid = $this->rclone->runBackground($this->buildMountArgs($account), ['json_log' => true]);

        return MountProcess::updateOrCreate(
            ['account_id' => $account->id],
            [
                'mount_point' => $point,
                'pid' => $pid,
                'started_at' => Carbon::now(),
                'status' => 'running',
                'last_health_check_at' => Carbon::now(),
            ],
        );
    }

    public function unmount(Account $account): void
    {
        $mp = MountProcess::where('account_id', $account->id)->first();

        if ($mp?->pid) {
            $this->rclone->killProcess($mp->pid);
        }

        $mp?->update(['status' => 'stopped']);
    }

    public function isHealthy(MountProcess $mp): bool
    {
        return $mp->pid !== null && $this->rclone->isProcessAlive($mp->pid);
    }

    /** Mount every active account that is not already mounted (SPEC F3.1). */
    public function mountAllActive(): int
    {
        $count = 0;

        foreach (Account::where('status', Account::STATUS_ACTIVE)->get() as $account) {
            $mp = MountProcess::where('account_id', $account->id)->first();

            if ($mp && $this->isHealthy($mp)) {
                continue;
            }

            $this->mount($account);
            $count++;
        }

        return $count;
    }
}
