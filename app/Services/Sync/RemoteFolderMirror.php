<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Console\Commands\DiscoverRemoteFoldersCommand;
use App\Jobs\MirrorRemoteFoldersJob;
use App\Jobs\SyncChangesJob;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Services\Files\LocalFiles;
use App\Services\Rclone\RcloneConfigGenerator;
use App\Services\Rclone\RcloneRunner;
use App\Services\Settings\SettingsRepository;

/**
 * Whole-drive mirror (OneDrive-style): the moment an account is linked,
 * EVERY top-level cloud folder is registered and mirrored locally as ☁
 * placeholders (online by default). The user keeps an item OFFLINE only by
 * choosing "keep local" — there is no manual "select folders" step.
 *
 * Respects the user's choices: a folder they explicitly removed keeps its
 * SyncFolder row (is_active = false) and is NEVER resurrected. Only folders
 * with no row at all (genuinely new on the cloud) are adopted. The Personal
 * Vault and Trash are skipped — rclone can't traverse the Vault.
 *
 * Shared by {@see MirrorRemoteFoldersJob} (runs on connect) and
 * {@see DiscoverRemoteFoldersCommand} (catches folders
 * created on the OneDrive website afterwards).
 */
class RemoteFolderMirror
{
    /** Never mirror these top-level remote entries. */
    public const SKIP = ['Cofre Pessoal', 'Personal Vault', '.Trash-1000'];

    public function __construct(
        private readonly RcloneRunner $rclone,
        private readonly RcloneConfigGenerator $config,
        private readonly SettingsRepository $settings,
        private readonly LocalFiles $files,
    ) {}

    /**
     * Mirror every NEW top-level cloud folder of $account as ☁ placeholders.
     * Returns the number of folders newly adopted.
     */
    public function discover(Account $account): int
    {
        $this->config->regenerate();

        $result = $this->rclone->run(
            ['lsjson', $account->remote_name.':', '--dirs-only'],
            ['timeout' => 120],
        );

        if (! $result->successful()) {
            return 0; // transient: try again next tick, never guess
        }

        $mountBase = rtrim($this->settings->mountBase(), '/').'/'.$account->name;

        // Every folder we've ever known (active OR removed) — so a folder the
        // user deselected is not silently resurrected.
        $known = SyncFolder::where('account_id', $account->id)
            ->pluck('remote_path')
            ->map(fn ($p) => ltrim((string) $p, '/'))
            ->all();

        $added = 0;

        foreach ($result->json() ?? [] as $entry) {
            $name = trim((string) ($entry['Name'] ?? ''), '/');

            if ($name === '' || in_array($name, self::SKIP, true) || in_array($name, $known, true)) {
                continue;
            }

            $folder = SyncFolder::create([
                'account_id' => $account->id,
                'remote_path' => $name,
                'local_path' => $mountBase.'/'.$name,
                'sync_mode' => 'on_demand',
                'is_active' => true,
            ]);

            // Mirror placeholders INLINE — not via the queue. The transfer
            // queue can be tied up for many minutes by a huge folder, which
            // used to leave brand-new cloud folders invisible on this machine
            // for hours. A freshly-created folder is small, so create its ☁
            // placeholders right here (appears immediately) and queue only the
            // data transfer.
            $this->files->materializeCloudPlaceholders($account, $name);
            SyncChangesJob::dispatch($folder->id);

            $added++;
        }

        return $added;
    }
}
