<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SyncHistory;
use App\Services\Files\PendingOps;
use App\Services\Rclone\RcloneRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Self-heal: clear the stuck states that otherwise pin the tray icon on
 * "Syncing…" forever. All operations are cheap (a few SQLite queries + a
 * file check) and idempotent, so this runs frequently from the scheduler.
 *
 * It never touches the user's files, never kills the worker (systemd's
 * Restart=always + the worker's own --max-time/job timeouts cover crashes
 * and hung rclone) — it only tidies up leftover bookkeeping.
 */
class HealCommand extends Command
{
    protected $signature = 'rnvsync:heal';

    protected $description = 'Self-heal stuck sync state so the tray never sticks on "syncing"';

    /** A live-stats pointer older than this (s) is from a crashed transfer. */
    private const RC_STALE_AFTER = 3700;

    public function handle(): int
    {
        // 1) Sync runs left "running" by a killed job → mark as error so the
        //    dashboard/activity/tray stop showing them as in-progress forever.
        $history = SyncHistory::sweepStale();

        // 2) Pending download / keep-online markers whose local file no longer
        //    exists (a crashed job, or test garbage) → drop them.
        $missing = PendingOps::sweepStale();

        // 3) Orphaned pending markers: a marker is set ONLY right before a
        //    Download/FreeOnline job is dispatched, and the job clears it on
        //    finish (success or failure). So if markers remain while NO such
        //    job exists in the queue, the job was hard-killed before cleanup —
        //    the markers are stale and would pin the tray icon. Clear them.
        $orphaned = $this->clearOrphanPendingOps();

        // 4) Stale live-stats pointer from a transfer that died without its
        //    cleanup → remove it so /sync-state doesn't poll a dead port.
        $rc = $this->clearStaleRcState();

        $this->info("Healed: history={$history} pending_missing={$missing} pending_orphan={$orphaned} rc_stale={$rc}");

        return self::SUCCESS;
    }

    /** Clear pending markers when no Download/FreeOnline job backs them. */
    private function clearOrphanPendingOps(): int
    {
        $pending = PendingOps::all();
        if ($pending === []) {
            return 0;
        }

        $hasOps = DB::table('jobs')
            ->where(fn ($q) => $q
                ->where('payload', 'like', '%DownloadPathJob%')
                ->orWhere('payload', 'like', '%FreeOnlineJob%'))
            ->exists();

        if ($hasOps) {
            return 0; // real per-file operations are in flight — leave them
        }

        foreach ($pending as $path) {
            PendingOps::clear($path);
        }

        return count($pending);
    }

    /** Remove the live-stats pointer if it outlived the max transfer timeout. */
    private function clearStaleRcState(): int
    {
        $file = RcloneRunner::rcStateFile();
        if (! is_file($file)) {
            return 0;
        }

        $state = json_decode((string) @file_get_contents($file), true);
        $startedAt = (int) (($state['started_at'] ?? 0));

        if ($startedAt > 0 && (time() - $startedAt) > self::RC_STALE_AFTER) {
            @unlink($file);

            return 1;
        }

        return 0;
    }
}
