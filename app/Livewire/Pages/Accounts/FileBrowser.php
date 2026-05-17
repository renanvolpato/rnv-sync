<?php

namespace App\Livewire\Pages\Accounts;

use App\Models\Account;
use App\Services\Accounts\AccountsService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Read-only remote file tree (SPEC F1.5 / Key Screen 6, listing only).
 *
 * Listing comes from `rclone lsjson`. Browsing is path-based and the
 * current path is reflected in the URL so it is shareable.
 */
#[Layout('components.layouts.app')]
class FileBrowser extends Component
{
    public Account $account;

    #[Url(as: 'path')]
    public string $path = '';

    public bool $rcloneUnavailable = false;

    public function mount(Account $account): void
    {
        $this->account = $account;
    }

    public function open(string $name): void
    {
        $this->path = trim($this->path.'/'.$name, '/');
    }

    public function goTo(string $path): void
    {
        $this->path = trim($path, '/');
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

    public function render(AccountsService $accounts)
    {
        $entries = [];

        try {
            $entries = $accounts->listRemote($this->account, $this->path);
        } catch (\Throwable) {
            $this->rcloneUnavailable = true;
        }

        return view('livewire.pages.accounts.file-browser', [
            'entries' => $entries,
        ]);
    }
}
