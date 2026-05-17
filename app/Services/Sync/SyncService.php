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

        $remote = $account->remote_name.':'.ltrim($folder->remote_path, '/');

        $result = $this->rclone->run(
            [...$this->bisyncArgs(), $remote, $folder->local_path],
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
    public function bisyncArgs(): array
    {
        $cfg = config('rnvsync.sync');

        $args = [
            'bisync',
            '--resync', // first-run safe; rclone no-ops if state exists
            '--transfers='.$cfg['transfers'],
            '--checkers='.$cfg['checkers'],
            '--tpslimit='.$cfg['tpslimit'],
            '--tpslimit-burst='.$cfg['tpslimit_burst'],
            '--stats=1s',
            '--stats-one-line',
        ];

        $bwlimit = $this->settings->get('bandwidth_limit_kbps');
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
