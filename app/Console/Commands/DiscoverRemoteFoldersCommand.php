<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\MaterializePlaceholdersJob;
use App\Jobs\SyncChangesJob;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Services\Rclone\RcloneConfigGenerator;
use App\Services\Rclone\RcloneRunner;
use App\Services\Settings\SettingsRepository;
use Illuminate\Console\Command;

/**
 * Whole-drive feel (like OneDrive): any NEW top-level folder that shows
 * up in the cloud root is registered and mirrored locally as ☁
 * placeholders automatically — no manual "select folders" step.
 *
 * Respects the user's choices: a folder they explicitly removed keeps its
 * SyncFolder row (is_active = false) and is NEVER re-added. Only folders
 * with no row at all (genuinely new on the cloud) are adopted. The
 * Personal Vault and Trash are skipped — rclone can't traverse the Vault.
 */
class DiscoverRemoteFoldersCommand extends Command
{
    protected $signature = 'rnvsync:discover-remote-folders';

    protected $description = 'Auto-add new top-level cloud folders as ☁ placeholders (whole-drive mirror)';

    /** Never mirror these top-level remote entries. */
    private const SKIP = ['Cofre Pessoal', 'Personal Vault', '.Trash-1000'];

    public function handle(RcloneRunner $rclone, RcloneConfigGenerator $config, SettingsRepository $settings): int
    {
        $config->regenerate();
        $added = 0;

        foreach (Account::all() as $account) {
            $result = $rclone->run(
                ['lsjson', $account->remote_name.':', '--dirs-only'],
                ['timeout' => 120],
            );
            if (! $result->successful()) {
                continue; // transient: try again next tick, never guess
            }

            $mountBase = rtrim($settings->mountBase(), '/').'/'.$account->name;

            // Every folder we've ever known (active OR removed) — so a
            // folder the user deselected is not silently resurrected.
            $known = SyncFolder::where('account_id', $account->id)
                ->pluck('remote_path')
                ->map(fn ($p) => ltrim((string) $p, '/'))
                ->all();

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

                // Same chain the folder-selection screen uses: mirror as
                // placeholders, then run a normal change-sync.
                MaterializePlaceholdersJob::withChain([
                    new SyncChangesJob($folder->id),
                ])->dispatch($folder->id);

                $this->info("Discovered cloud folder: {$name}");
                $added++;
            }
        }

        $this->info("Done. {$added} new cloud folder(s) added.");

        return self::SUCCESS;
    }
}
