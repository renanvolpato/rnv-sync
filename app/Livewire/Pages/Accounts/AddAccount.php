<?php

namespace App\Livewire\Pages\Accounts;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Add Account screen (SPEC F1.4 / Key Screen 4).
 *
 * v0.1.0 supports only OneDrive Personal. Business/SharePoint provider
 * tiles are shown disabled (arrive in v0.4.0).
 */
#[Layout('components.layouts.app')]
class AddAccount extends Component
{
    public string $provider = 'onedrive_personal';

    public string $documentLibraryUrl = '';

    public function render()
    {
        return view('livewire.pages.accounts.add-account');
    }
}
