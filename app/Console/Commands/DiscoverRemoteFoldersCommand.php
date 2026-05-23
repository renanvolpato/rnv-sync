<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\MirrorRemoteFoldersJob;
use App\Models\Account;
use App\Services\Sync\RemoteFolderMirror;
use Illuminate\Console\Command;

/**
 * Whole-drive feel (like OneDrive): any NEW top-level folder that shows
 * up in the cloud root is registered and mirrored locally as ☁
 * placeholders automatically — no manual "select folders" step. The
 * initial mirror happens on connect ({@see MirrorRemoteFoldersJob});
 * this scheduled pass catches folders created on the website afterwards.
 *
 * The actual logic lives in {@see RemoteFolderMirror} so the on-connect job
 * and this command stay in lockstep.
 */
class DiscoverRemoteFoldersCommand extends Command
{
    protected $signature = 'rnvsync:discover-remote-folders';

    protected $description = 'Auto-add new top-level cloud folders as ☁ placeholders (whole-drive mirror)';

    public function handle(RemoteFolderMirror $mirror): int
    {
        $added = 0;

        foreach (Account::all() as $account) {
            $added += $mirror->discover($account);
        }

        $this->info("Done. {$added} new cloud folder(s) added.");

        return self::SUCCESS;
    }
}
