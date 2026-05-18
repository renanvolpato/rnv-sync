<?php

namespace App\Livewire\Pages\Accounts;

use App\Jobs\DownloadPathJob;
use App\Jobs\MaterializePlaceholdersJob;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Models\SyncHistory;
use App\Services\Accounts\AccountsService;
use App\Services\Files\LocalFiles;
use App\Services\Files\PendingOps;
use App\Services\Settings\SettingsRepository;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Folder selection (SPEC F2.1 / Key Screen 5).
 *
 * Navigable: drill into folders and tick any folder/subfolder at any
 * level. Selecting a folder starts syncing it immediately (the whole
 * folder is downloaded as real files — physical model).
 */
#[Layout('components.layouts.app')]
class FolderSelection extends Component
{
    public Account $account;

    /** Current folder being browsed (for picking subfolders). */
    #[Url(as: 'path')]
    public string $path = '';

    /** @var list<string> full remote paths chosen to sync */
    public array $selected = [];

    public function mount(Account $account): void
    {
        SyncHistory::sweepStale();

        $this->account = $account;
        $this->selected = $account->syncFolders()
            ->where('is_active', true)
            ->pluck('remote_path')
            ->all();
    }

    public function open(string $name): void
    {
        $this->path = trim($this->path.'/'.$name, '/');
    }

    public function goTo(string $path): void
    {
        $this->path = trim($path, '/');
    }

    /** Up one folder level (used by the back button inside a subfolder). */
    public function goUp(): void
    {
        $parent = trim((string) dirname($this->path), '/.');
        $this->path = $parent === '' ? '' : $parent;
    }

    /** Keep an item on this device (download in background). */
    public function keepOffline(string $name): void
    {
        $full = trim($this->path.'/'.$name, '/');
        $local = app(LocalFiles::class)->localPathFor($this->account, $full);
        PendingOps::mark($local);
        DownloadPathJob::dispatch($this->account->id, $full);
        $this->dispatch('toast', type: 'success', message: __('cache.pinning'));
    }

    /** Free up space (becomes online-only). */
    public function freeOnline(string $name): void
    {
        app(LocalFiles::class)->free(
            $this->account,
            trim($this->path.'/'.$name, '/'),
        );
        $this->dispatch('toast', type: 'success', message: __('cache.freed'));
    }

    /**
     * @return list<array{label:string,path:string}>
     */
    public function breadcrumbs(): array
    {
        $crumbs = [['label' => $this->account->name, 'path' => '']];
        $acc = '';

        foreach (array_filter(explode('/', $this->path)) as $segment) {
            $acc = trim($acc.'/'.$segment, '/');
            $crumbs[] = ['label' => $segment, 'path' => $acc];
        }

        return $crumbs;
    }

    public function save(SettingsRepository $settings): void
    {
        $mountBase = rtrim($settings->mountBase(), '/').'/'.$this->account->name;
        $chosen = array_values(array_unique(array_map(
            fn ($p) => ltrim((string) $p, '/'),
            $this->selected,
        )));

        foreach ($chosen as $relative) {
            if ($relative === '') {
                continue;
            }

            $folder = SyncFolder::updateOrCreate(
                ['account_id' => $this->account->id, 'remote_path' => $relative],
                [
                    'local_path' => $mountBase.'/'.$relative,
                    // On-demand: show everything as cloud placeholders,
                    // download nothing until the user keeps it offline.
                    'sync_mode' => 'on_demand',
                    'is_active' => true,
                ],
            );

            MaterializePlaceholdersJob::dispatch($folder->id);
        }

        // Folders the user unchecked → stop syncing (kept files stay).
        SyncFolder::where('account_id', $this->account->id)
            ->whereNotIn('remote_path', $chosen)
            ->update(['is_active' => false]);

        session()->flash('status', __('sync.folders_saved'));
        $this->redirectRoute('accounts.activity', $this->account, navigate: true);
    }

    public function render(AccountsService $accounts)
    {
        $entries = [];

        try {
            $entries = $accounts->listRemote($this->account, $this->path);
        } catch (\Throwable) {
            // Listing failure handled by the empty state in the view.
        }

        $local = app(LocalFiles::class);
        foreach ($entries as &$e) {
            $e['status'] = $local->status($this->account, $e['path']);
        }
        unset($e);

        $running = SyncHistory::where('account_id', $this->account->id)
            ->where('status', 'running')->exists();

        return view('livewire.pages.accounts.folder-selection', [
            'folders' => $entries,
            'running' => $running,
        ]);
    }
}
