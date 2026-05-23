<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Account;
use App\Services\Sync\RemoteFolderMirror;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Mirror an account's whole cloud drive as ☁ placeholders right after it is
 * linked — so all folders appear online automatically (in the file manager
 * and the web file browser) with no manual "select folders" step. Heavy
 * (recursive listing of the whole drive), so it runs in the background.
 */
class MirrorRemoteFoldersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public function __construct(public int $accountId) {}

    public function uniqueId(): string
    {
        return 'mirror-'.$this->accountId;
    }

    public function handle(RemoteFolderMirror $mirror): void
    {
        $account = Account::find($this->accountId);

        if (! $account) {
            return;
        }

        $mirror->discover($account);

        // Register this (possibly brand-new) account's base directory with
        // the file-manager extension so its mirrored folders get ☁/✓ emblems
        // right away — otherwise a newly connected account would show no
        // status in the file manager until the next install/update run.
        // Best-effort: a desktop without the extension must not fail the job.
        try {
            Artisan::call('rnvsync:nautilus-config');
        } catch (\Throwable) {
            // No file-manager integration on this machine — ignore.
        }
    }
}
