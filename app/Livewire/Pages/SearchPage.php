<?php

namespace App\Livewire\Pages;

use App\Models\Account;
use App\Services\Rclone\RcloneRunner;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Search across remote files (SPEC F5.4). Uses `rclone lsjson -R`
 * filtered by name; results capped for responsiveness.
 */
#[Layout('components.layouts.app')]
class SearchPage extends Component
{
    #[Url]
    public string $q = '';

    public function render(RcloneRunner $rclone)
    {
        $results = [];

        if (strlen(trim($this->q)) >= 2) {
            foreach (Account::where('status', Account::STATUS_ACTIVE)->get() as $account) {
                try {
                    $r = $rclone->run(
                        ['lsjson', '-R', '--no-modtime', $account->remote_name.':'],
                        ['timeout' => 60],
                    );
                    foreach ($r->json() ?? [] as $entry) {
                        if (stripos((string) ($entry['Path'] ?? ''), $this->q) !== false) {
                            $results[] = [
                                'account' => $account->name,
                                'path' => $entry['Path'] ?? '',
                                'is_dir' => (bool) ($entry['IsDir'] ?? false),
                                'size' => (int) ($entry['Size'] ?? 0),
                            ];
                        }
                        if (count($results) >= 100) {
                            break 2;
                        }
                    }
                } catch (\Throwable) {
                    // Skip accounts whose remote can't be listed right now.
                }
            }
        }

        return view('livewire.pages.search', ['results' => $results]);
    }
}
