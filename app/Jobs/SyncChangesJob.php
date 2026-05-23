<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SyncFolder;
use App\Services\Rclone\RcloneConfigGenerator;
use App\Services\Rclone\RcloneRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Lightweight two-way change sync for an on-demand folder. Designed to
 * be gentle on the machine — NO recursive whole-tree scan:
 *
 *  • Push: upload real local files (new/edited). `--min-size 1` skips
 *    our 0-byte cloud placeholders, so the cloud is never wiped.
 *  • Pull: update ONLY the files the user keeps offline (real, size>0)
 *    via an explicit --files-from list — placeholders are never
 *    hydrated and online-only files are left alone.
 *
 * Surfacing NEW cloud-side files as ☁ placeholders (the heavy recursive
 * remote listing) is handled OUT of band by rnvsync:refresh-placeholders,
 * so this job stays fast and never monopolises the single queue worker.
 *
 * Low concurrency / tpslimit keep CPU, disk and API use small.
 */
class SyncChangesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800;

    // Unique per folder while queued/running. The watcher and the
    // scheduler both fire this often; without uniqueness a slow run
    // (big upload + OneDrive throttling) lets duplicates pile up into
    // the hundreds. The lock releases as soon as the job finishes;
    // uniqueFor is just the safety ceiling.
    public int $uniqueFor = 1800;

    // Above this many real (kept-offline) files, skip the per-file pull.
    // Re-checking tens of thousands of files against the OneDrive API
    // every sync (tpslimit 12) blows past the timeout and monopolises
    // the single worker — which is exactly what made one 72k-file folder
    // freeze all sync. Such folders still PUSH local edits up and still
    // surface NEW remote files as placeholders; only refreshing an
    // already-downloaded file after a *cloud-side* edit is skipped, which
    // is rare for folders that large.
    private const MAX_PULL_FILES = 3000;

    public function uniqueId(): string
    {
        return 'sync-changes-'.$this->syncFolderId;
    }

    // Gentle on the machine + correct two-way sync.
    //
    // --update is what makes this a correct two-way sync: each copy
    // skips files that are NEWER on the destination, so the push
    // (local→remote) never clobbers a fresher cloud edit and the pull
    // (remote→local) never clobbers a fresher local edit — newest
    // mtime wins, no heavy bisync state. --modify-window 1s absorbs
    // OneDrive's second-level mtime precision (avoids ping-pong).
    // --ignore-errors so one bad item doesn't fail the whole run.
    private const GENTLE = [
        '--transfers=4', '--checkers=8', '--tpslimit=12', '--ignore-errors',
        '--update', '--modify-window', '1s',
    ];

    // Skip the OneDrive Personal Vault and Trash on the recursive PUSH
    // scan (rclone can't traverse the Vault → would abort the run).
    // Also skip editor lock/temp/swap files so they never reach the
    // cloud: a `.~lock.*` left next to a .docx makes Office Online
    // treat the document as "in use" and refuse to open it. These
    // patterns must NOT be combined with --files-from: rclone treats
    // "--files-from + any other filter" as a fatal config error, which
    // would silently kill the pull entirely.
    private const PUSH_EXCLUDES = [
        '--exclude', 'Cofre Pessoal/**', '--exclude', 'Personal Vault/**',
        '--exclude', '.Trash-1000/**',
        // editor lock / autosave / swap / temp files
        '--exclude', '.~lock.*',     // LibreOffice
        '--exclude', '~$*',          // MS Office
        '--exclude', '.~*',          // generic ~ tempfiles
        '--exclude', '*.tmp',
        '--exclude', '*.swp', '--exclude', '*.swo',  // vim
        '--exclude', '.#*',          // emacs
        '--exclude', '*~',           // editor backups
        '--exclude', '.goutputstream-*', // gnome atomic write
        '--exclude', '.DS_Store', '--exclude', 'Thumbs.db', // OS junk
    ];

    public function __construct(public int $syncFolderId) {}

    public function handle(RcloneRunner $rclone, RcloneConfigGenerator $config): void
    {
        $folder = SyncFolder::with('account')->find($this->syncFolderId);

        if (! $folder || ! $folder->is_active || $folder->sync_mode !== 'on_demand'
            || ! $folder->account || ! is_dir($folder->local_path)) {
            return;
        }

        $config->regenerate();
        $remote = $folder->account->remote_name.':'.ltrim($folder->remote_path, '/');
        $local = $folder->local_path;

        // Build the real-file list ONCE, up front (low-memory streaming
        // iterator — see buildRealFileList). Reused both to decide whether
        // there is anything to sync and as the explicit pull set.
        [$listFile, $realCount] = $this->buildRealFileList($local);

        $ok = true;

        // A fully-online folder (placeholders only, no real files) has
        // nothing to push up or pull down. Skip the transfer engine instead
        // of making rclone enumerate tens of thousands of 0-byte
        // placeholders for nothing — that head-of-line work is what kept the
        // single worker busy on big online-only folders (e.g. a 72k-file
        // folder kept entirely in the cloud).
        if ($realCount > 0) {
            // 1) Push local creations/edits (real files only). NOTE the
            // explicit "1b": rclone reads a unitless --min-size as KiB, so
            // bare "1" silently skipped every file < 1 KiB. "1b" = 1 byte,
            // so only our true 0-byte placeholders are excluded.
            // --no-traverse: only the few real files are pushed, so checking
            // each against the remote individually is far cheaper than
            // listing the whole remote tree every sync.
            $push = $rclone->run(
                ['copy', $local, $remote, '--min-size', '1b', '--no-traverse', ...self::GENTLE, ...self::PUSH_EXCLUDES],
                ['timeout' => 1700],
            );
            $ok = $ok && $push->successful();

            // 2) Pull updates for kept-offline files only, via the explicit
            // --files-from list. Placeholders are never hydrated and
            // online-only files are left alone. --files-from must be used
            // WITHOUT any --exclude (rclone rejects the combination), so
            // GENTLE here carries no excludes.
            if ($realCount <= self::MAX_PULL_FILES) {
                $pull = $rclone->run(
                    ['copy', $remote, $local, '--files-from', $listFile, ...self::GENTLE],
                    ['timeout' => 1700],
                );
                $ok = $ok && $pull->successful();
            }
        }

        @unlink($listFile);

        // Surfacing NEW cloud-side files as ☁ placeholders is the heavy step
        // (a recursive remote listing, minutes on big folders) and runs OUT
        // of band in rnvsync:refresh-placeholders — off this single queue
        // worker — so the change-sync stays fast and the sync icon settles.

        $folder->update([
            'last_synced_at' => Carbon::now(),
            'last_sync_status' => $ok ? 'success' : 'error',
        ]);
    }

    /**
     * Stream the folder's real (size > 0) files into a temp --files-from
     * list with a low-memory iterator. NOT File::allFiles() (Symfony
     * Finder), which eagerly collects AND sorts the whole tree and OOM'd
     * the worker on huge folders. Returns [listFilePath, realFileCount].
     *
     * @return array{0:string,1:int}
     */
    private function buildRealFileList(string $local): array
    {
        $listFile = tempnam(sys_get_temp_dir(), 'rnv-ff-');
        $handle = fopen($listFile, 'w');
        $count = 0;

        $tree = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $local,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO,
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        foreach ($tree as $f) {
            if ($f->isFile() && $f->getSize() > 0) {
                fwrite($handle, ltrim(str_replace($local, '', $f->getPathname()), '/')."\n");
                $count++;
            }
        }
        fclose($handle);

        return [$listFile, $count];
    }
}
