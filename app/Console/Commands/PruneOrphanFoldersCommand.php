<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SyncFolder;
use App\Services\Files\LocalFiles;
use App\Services\Rclone\RcloneConfigGenerator;
use App\Services\Rclone\RcloneRunner;
use Illuminate\Console\Command;

/**
 * Deactivates active sync folders whose remote counterpart no longer
 * exists (renamed/deleted on the cloud). Without this, the DB keeps
 * piling up "11 selected" entries pointing at vanished paths and the
 * file manager keeps showing empty placeholder shells.
 *
 * Conservative: only prunes on a DEFINITIVE "not found" reply from
 * rclone — a transient network/throttling failure never causes a
 * deactivation. Scheduled daily; can also be triggered manually.
 */
class PruneOrphanFoldersCommand extends Command
{
    protected $signature = 'rnvsync:prune-orphan-folders';

    protected $description = 'Deactivate active SyncFolders whose remote path no longer exists';

    public function handle(RcloneRunner $rclone, RcloneConfigGenerator $config, LocalFiles $files): int
    {
        $config->regenerate();
        $pruned = 0;

        foreach (SyncFolder::with('account')->where('is_active', true)->get() as $f) {
            if (! $f->account) {
                continue;
            }

            $remote = $f->account->remote_name.':'.ltrim($f->remote_path, '/');
            $r = $rclone->run(
                ['lsjson', '--no-mimetype', '--max-depth', '1', $remote],
                ['timeout' => 30],
            );
            if ($r->successful()) {
                continue;
            }

            $err = strtolower($r->stderr);
            $gone = str_contains($err, 'directory not found')
                || str_contains($err, 'object not found')
                || str_contains($err, "doesn't support listing");
            if (! $gone) {
                // Network / throttling / other transient — leave alone.
                continue;
            }

            $f->update(['is_active' => false]);
            $files->tryRemoveEmptyShell($f->local_path);
            $this->info("Pruned: #{$f->id} {$f->remote_path}");
            $pruned++;
        }

        $this->info("Done. {$pruned} folder(s) pruned.");

        return self::SUCCESS;
    }
}
