<?php

namespace App\Livewire\Pages\Accounts;

use App\Jobs\StartSyncJob;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Services\Accounts\AccountsService;
use App\Services\Settings\SettingsRepository;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Folder selection (SPEC F2.1 / Key Screen 5): pick top-level remote
 * folders to sync. Activating a folder queues a sync immediately
 * (EARS: WHEN toggled active, initiate a sync within 30 seconds).
 */
#[Layout('components.layouts.app')]
class FolderSelection extends Component
{
    public Account $account;

    /** @var array<string,bool> remote_path => selected */
    public array $selected = [];

    public function mount(Account $account): void
    {
        $this->account = $account;
        $this->selected = $account->syncFolders()
            ->where('is_active', true)
            ->pluck('remote_path', 'remote_path')
            ->map(fn () => true)
            ->all();
    }

    public function save(SettingsRepository $settings): void
    {
        $mountBase = rtrim($settings->mountBase(), '/').'/'.$this->account->name;

        foreach ($this->selected as $path => $isOn) {
            if (! $isOn) {
                continue;
            }

            $folder = SyncFolder::updateOrCreate(
                ['account_id' => $this->account->id, 'remote_path' => $path],
                [
                    'local_path' => $mountBase.$path,
                    'sync_mode' => 'bisync',
                    'is_active' => true,
                ],
            );

            // EARS F2.1: queue a sync immediately on activation.
            StartSyncJob::dispatch($folder->id);
        }

        // Deactivate folders the user unchecked.
        SyncFolder::where('account_id', $this->account->id)
            ->whereNotIn('remote_path', array_keys(array_filter($this->selected)))
            ->update(['is_active' => false]);

        session()->flash('status', __('sync.folders_saved'));
        $this->redirectRoute('accounts.files', $this->account, navigate: true);
    }

    public function render(AccountsService $accounts)
    {
        $entries = [];

        try {
            $entries = array_filter(
                $accounts->listRemote($this->account, ''),
                fn (array $e) => $e['is_dir'],
            );
        } catch (\Throwable) {
            // Listing failure handled by empty state in the view.
        }

        return view('livewire.pages.accounts.folder-selection', [
            'folders' => $entries,
        ]);
    }
}
