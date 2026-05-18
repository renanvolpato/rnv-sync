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

    private const GENTLE = ['--transfers=2', '--checkers=4', '--tpslimit=6'];

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

        // 1) Push local creations/edits (real files only).
        $rclone->run(
            ['copy', $local, $remote, '--min-size', '1', ...self::GENTLE],
            ['timeout' => 1700],
        );

        // 2) Pull updates for kept-offline files only.
        $real = [];
        foreach (File::allFiles($local) as $f) {
            if ($f->getSize() > 0) {
                $real[] = ltrim(str_replace($local, '', $f->getPathname()), '/');
            }
        }

        if ($real !== []) {
            $listFile = tempnam(sys_get_temp_dir(), 'rnv-ff-');
            File::put($listFile, implode("\n", $real));

            $rclone->run(
                ['copy', $remote, $local, '--files-from', $listFile, ...self::GENTLE],
                ['timeout' => 1700],
            );

            @unlink($listFile);
        }

        $folder->update(['last_synced_at' => Carbon::now(), 'last_sync_status' => 'success']);
    }
}
