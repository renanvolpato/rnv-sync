<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Events\SyncProgress;
use App\Events\SyncStatusChanged;
use App\Exceptions\RcloneException;
use App\Jobs\StartSyncJob;
use App\Models\SyncFolder;
use App\Models\SyncHistory;
use App\Services\Conflicts\ConflictsService;
use App\Services\Rclone\JsonLogParser;
use App\Services\Rclone\RcloneConfigGenerator;
use App\Services\Rclone\RcloneRunner;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Bidirectional folder sync via `rclone bisync` (SPEC F2.2).
 *
 * Network/throttling retry policy lives in {@see StartSyncJob};
 * this service performs one sync attempt and records it in
 * rnvsync_sync_history (SPEC F2.3).
 */
class SyncService
{
    public function __construct(
        private readonly RcloneRunner $rclone,
        private readonly RcloneConfigGenerator $configGenerator,
        private readonly JsonLogParser $parser,
        private readonly SettingsRepository $settings,
        private readonly ConflictsService $conflicts,
    ) {}

    public function isPaused(): bool
    {
        return (bool) $this->settings->get('sync_paused', false);
    }

    public function setPaused(bool $paused): void
    {
        $this->settings->set('sync_paused', $paused);

        // Pause has to be visible IMMEDIATELY: kill any rclone process this app
        // spawned (recursive copies of a "keep local" folder can run for many
        // minutes and would otherwise keep filling the disk). Matching on the
        // app's config path is unique enough to never hit unrelated rclones.
        if ($paused) {
            $this->killRunningRclone();
        }
    }

    /** SIGTERM every rclone this app started (identified by our --config path). */
    private function killRunningRclone(): void
    {
        try {
            Process::timeout(5)->run([
                'pkill', '-TERM', '-f', storage_path('rclone/rclone.conf'),
            ]);
        } catch (\Throwable) {
            // Best-effort — pause still takes effect via the per-job checks.
        }
    }

    /**
     * Run one bisync attempt for a folder. Returns the history row.
     *
     * @throws RcloneException on a retryable failure (network/throttle).
     */
    public function runSync(SyncFolder $folder): SyncHistory
    {
        $account = $folder->account;

        $history = SyncHistory::create([
            'account_id' => $account->id,
            'sync_folder_id' => $folder->id,
            'started_at' => Carbon::now(),
            'status' => 'running',
        ]);

        event(new SyncStatusChanged($account->id, 'started', $folder->remote_path));

        $this->configGenerator->regenerate();

        // rclone bisync aborts with "directory not found" if the local
        // side doesn't exist yet — create it on first sync.
        File::ensureDirectoryExists($folder->local_path);

        $remote = $account->remote_name.':'.ltrim($folder->remote_path, '/');

        $result = $this->rclone->run(
            [...$this->bisyncArgs($folder), $remote, $folder->local_path],
            ['timeout' => 3600, 'json_log' => true],
        );

        $entries = $this->parser->parse($result->stderr."\n".$result->stdout);
        $stats = $this->extractStats($entries);

        // SPEC F4.4: persist any conflicts bisync reported.
        $this->conflicts->detectFromLog($account, $entries);

        // Surface throttling distinctly so the job respects Retry-After.
        if ($this->isRateLimited($entries)) {
            $history->update(['status' => 'error', 'completed_at' => Carbon::now()]);
            throw new RcloneException('rclone hit a 429 rate limit (Retry-After respected).');
        }

        if (! $result->successful()) {
            $history->update([
                'status' => 'error',
                'completed_at' => Carbon::now(),
                'errors_count' => max(1, $stats['errors']),
            ]);

            event(new SyncStatusChanged($account->id, 'failed', $folder->remote_path));

            // Treat non-zero exit as retryable (network etc.).
            throw RcloneException::commandFailed('bisync', $result->exitCode, $result->stderr);
        }

        $history->update([
            'status' => 'success',
            'completed_at' => Carbon::now(),
            'files_transferred' => $stats['files'],
            'bytes_transferred' => $stats['bytes'],
            'errors_count' => $stats['errors'],
        ]);

        $folder->update([
            'last_synced_at' => Carbon::now(),
            'last_sync_status' => 'success',
        ]);
        $account->update(['last_synced_at' => Carbon::now()]);

        event(new SyncProgress($account->id, $folder->id, '', 100.0, ''));
        event(new SyncStatusChanged($account->id, 'completed', $folder->remote_path));

        return $history->refresh();
    }

    /**
     * Default bisync flags (SPEC §8) plus the optional global bandwidth
     * limit (SPEC F2.8 EARS: WHERE bandwidth limit is set, pass --bwlimit).
     *
     * @return list<string>
     */
    public function bisyncArgs(?SyncFolder $folder = null): array
    {
        $cfg = config('rnvsync.sync');

        // SPEC F5.3: per-folder advanced overrides.
        $transfers = $folder?->transfers ?: $cfg['transfers'];
        $checkers = $folder?->checkers ?: $cfg['checkers'];

        $args = [
            'bisync',
            '--resync', // first-run safe; rclone no-ops if state exists
            '--transfers='.$transfers,
            '--checkers='.$checkers,
            '--tpslimit='.$cfg['tpslimit'],
            '--tpslimit-burst='.$cfg['tpslimit_burst'],
            '--stats=1s',
            '--stats-one-line',
        ];

        if ($folder?->chunk_size) {
            $args[] = '--onedrive-chunk-size='.$folder->chunk_size;
        }

        // SPEC F2.8 + F5.2: effective limit may come from the scheduler.
        $bwlimit = app(BandwidthScheduler::class)->effectiveLimitKbps();
        if ($bwlimit) {
            $args[] = '--bwlimit='.$bwlimit.'k';
        }

        return $args;
    }

    /**
     * @param  list<array{level:string,msg:string,raw:array<string,mixed>}>  $entries
     * @return array{files:int,bytes:int,errors:int}
     */
    private function extractStats(array $entries): array
    {
        $files = 0;
        $bytes = 0;
        $errors = 0;

        foreach ($entries as $entry) {
            if ($entry['level'] === 'error') {
                $errors++;
            }

            $stats = $entry['raw']['stats'] ?? null;
            if (is_array($stats)) {
                $files = max($files, (int) ($stats['transfers'] ?? 0));
                $bytes = max($bytes, (int) ($stats['bytes'] ?? 0));
                $errors = max($errors, (int) ($stats['errors'] ?? 0));
            }
        }

        return ['files' => $files, 'bytes' => $bytes, 'errors' => $errors];
    }

    /**
     * @param  list<array{level:string,msg:string,raw:array<string,mixed>}>  $entries
     */
    private function isRateLimited(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (str_contains($entry['msg'], '429') || str_contains($entry['msg'], 'Too Many Requests')) {
                return true;
            }
        }

        return false;
    }
}
