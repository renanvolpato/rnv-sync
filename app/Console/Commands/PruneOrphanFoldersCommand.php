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
 * exists (renamed/deleted on the cloud), so the DB stops tracking
 * vanished paths.
 *
 * SAFETY: it ONLY deactivates (stops tracking) — it NEVER deletes the local
 * files/placeholders. And it only acts on a DEFINITIVE "not found" confirmed
 * by TWO checks (a one-off — eventual consistency after a move, a brief glitch,
 * a transient error — must never stop syncing a live folder, since a
 * deactivated folder isn't re-added automatically). Scheduled daily; can also
 * be run manually.
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

            if (! $this->confirmedGone($rclone, $remote)) {
                continue;
            }

            // The remote is gone BUT there are real local files here — a
            // locally-created folder waiting to upload (see
            // AdoptLocalFoldersCommand), not an orphan. Never touch it.
            if ($files->hasAnyRealFile($f->local_path)) {
                continue;
            }

            // ONLY deactivate. Never delete the local placeholder shell: a
            // wrong prune (or a folder you still want locally) must not lose
            // data — the worst case here is "stops syncing", not "files gone".
            $f->update(['is_active' => false]);
            $this->info("Deactivated orphan: #{$f->id} {$f->remote_path}");
            $pruned++;
        }

        $this->info("Done. {$pruned} folder(s) deactivated.");

        return self::SUCCESS;
    }

    /**
     * True only if rclone gives a DEFINITIVE "not found" on TWO checks (a brief
     * pause apart). A transient/auth/other error, or a one-off that clears on
     * re-check, returns false so a live folder is never deactivated.
     */
    private function confirmedGone(RcloneRunner $rclone, string $remote): bool
    {
        foreach ([0, 1] as $attempt) {
            $r = $rclone->run(
                ['lsjson', '--no-mimetype', '--max-depth', '1', $remote],
                ['timeout' => 30],
            );
            if ($r->successful()) {
                return false;
            }

            $err = strtolower($r->stderr);
            $definitelyGone = str_contains($err, 'directory not found')
                || str_contains($err, 'object not found');
            if (! $definitelyGone) {
                return false; // transient / auth / other — never prune
            }

            if ($attempt === 0 && ! app()->runningUnitTests()) {
                sleep(2); // re-verify after a brief pause
            }
        }

        return true;
    }
}
