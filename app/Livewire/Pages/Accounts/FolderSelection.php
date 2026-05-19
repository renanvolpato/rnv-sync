<?php

namespace App\Livewire\Pages\Accounts;

use App\Jobs\DownloadPathJob;
use App\Jobs\FreeOnlineJob;
use App\Jobs\MaterializePlaceholdersJob;
use App\Jobs\SyncChangesJob;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Models\SyncHistory;
use App\Services\Accounts\AccountsService;
use App\Services\Files\LocalFiles;
use App\Services\Files\PathErrors;
use App\Services\Files\PendingOps;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Cache;
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
        PathErrors::clear($local); // retry clears prior error
        PendingOps::mark($local);
        DownloadPathJob::dispatch($this->account->id, $full);
        $this->dispatch('toast', type: 'success', message: __('cache.pinning'));
    }

    /** Keep online: upload-if-needed then drop local (background). */
    public function freeOnline(string $name): void
    {
        $full = trim($this->path.'/'.$name, '/');
        $local = app(LocalFiles::class)->localPathFor($this->account, $full);
        PathErrors::clear($local);
        PendingOps::mark($local);
        FreeOnlineJob::dispatch($this->account->id, $full);
        $this->dispatch('toast', type: 'success', message: __('cache.freeing'));
    }

    /**
     * True when this path is itself an active sync folder or lives
     * inside one — i.e. it is really being synced (and therefore
     * shown in the file manager with status/actions).
     *
     * @param  list<string>  $active  active folder remote paths
     */
    public function isSynced(string $path, array $active): bool
    {
        $path = ltrim($path, '/');
        foreach ($active as $f) {
            $f = ltrim((string) $f, '/');
            if ($f !== '' && ($path === $f || str_starts_with($path, $f.'/'))) {
                return true;
            }
        }

        return false;
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

            // Mirror the whole folder as ☁ placeholders so it shows up
            // in the file manager immediately (Vault/Trash excluded so
            // the recursive listing doesn't abort), then run the
            // lightweight two-way change sync.
            MaterializePlaceholdersJob::withChain([
                new SyncChangesJob($folder->id),
            ])->dispatch($folder->id);
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
            // The remote listing is a network call to OneDrive — far
            // too slow/heavy to repeat on every wire:poll tick. Cache
            // it briefly: the poll then only recomputes the cheap
            // disk-based status so emblems still refresh every ~5s.
            // Navigating to another folder uses a different key →
            // always fresh.
            $entries = Cache::remember(
                "rnvsync.listremote.{$this->account->id}.".md5($this->path),
                45,
                fn () => $accounts->listRemote($this->account, $this->path),
            );
        } catch (\Throwable) {
            // Listing failure handled by the empty state in the view.
        }

        $active = $this->account->syncFolders()
            ->where('is_active', true)->pluck('remote_path')->all();

        $local = app(LocalFiles::class);

        foreach ($entries as &$e) {
            $e['synced'] = $this->isSynced($e['path'], $active);

            if (! $e['synced']) {
                // Not selected for sync: no on-disk placeholder, no
                // status/actions — it must NOT appear in the file
                // manager until the user syncs it explicitly.
                $e['status'] = 'unsynced';
                $e['errmsg'] = null;

                continue;
            }

            // Synced: lazily fill placeholders while browsing so the
            // tree stays visible, and show the real per-item state.
            $local->ensurePlaceholders($this->account, [$e]);
            $e['status'] = $local->status($this->account, $e['path']);
            $e['errmsg'] = $e['status'] === 'error'
                ? $local->errorFor($this->account, $e['path']) : null;
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
