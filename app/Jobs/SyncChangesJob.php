<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SyncFolder;
use App\Services\Rclone\RcloneConfigGenerator;
use App\Services\Rclone\RcloneRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

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
 * Low concurrency / tpslimit keep CPU, disk and API use small.
 */
class SyncChangesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800;

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
        '--transfers=2', '--checkers=4', '--tpslimit=6', '--ignore-errors',
        '--update', '--modify-window', '1s',
    ];

    // Skip the OneDrive Personal Vault and Trash on the recursive PUSH
    // scan (rclone can't traverse the Vault → would abort the run).
    // These must NOT be combined with --files-from: rclone treats
    // "--files-from + any other filter" as a fatal config error, which
    // would silently kill the pull entirely.
    private const PUSH_EXCLUDES = [
        '--exclude', 'Cofre Pessoal/**', '--exclude', 'Personal Vault/**',
        '--exclude', '.Trash-1000/**',
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

        $ok = true;

        // 1) Push local creations/edits (real files only). NOTE the
        // explicit "1b": rclone reads a unitless --min-size as KiB, so
        // bare "1" silently skipped every file < 1 KiB. "1b" = 1 byte,
        // so only our true 0-byte placeholders are excluded.
        $push = $rclone->run(
            ['copy', $local, $remote, '--min-size', '1b', ...self::GENTLE, ...self::PUSH_EXCLUDES],
            ['timeout' => 1700],
        );
        $ok = $ok && $push->successful();

        // 2) Pull updates for kept-offline files only. --files-from
        // must be used WITHOUT any --exclude (rclone rejects the
        // combination outright), so GENTLE here carries no excludes.
        $real = [];
        foreach (File::allFiles($local) as $f) {
            if ($f->getSize() > 0) {
                $real[] = ltrim(str_replace($local, '', $f->getPathname()), '/');
            }
        }

        if ($real !== []) {
            $listFile = tempnam(sys_get_temp_dir(), 'rnv-ff-');
            File::put($listFile, implode("\n", $real));

            $pull = $rclone->run(
                ['copy', $remote, $local, '--files-from', $listFile, ...self::GENTLE],
                ['timeout' => 1700],
            );
            $ok = $ok && $pull->successful();

            @unlink($listFile);
        }

        $folder->update([
            'last_synced_at' => Carbon::now(),
            'last_sync_status' => $ok ? 'success' : 'error',
        ]);
    }
}
