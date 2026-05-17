<?php

namespace App\Livewire\Pages;

use App\Models\Account;
use App\Services\Accounts\AccountsService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Account dashboard (SPEC F1.6 / Key Screen 2).
 *
 * On each load it attempts to refresh quota per account. Per the EARS
 * criterion, a failed quota fetch is non-fatal: the card shows
 * "Quota unavailable" and the next load retries.
 */
#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    /** @var array<int,bool> account_id => quota fetch ok */
    public array $quotaStatus = [];

    public bool $showOnboarding = false;

    public function mount(AccountsService $accounts, SettingsRepository $settings): void
    {
        $this->showOnboarding = ! $settings->get('onboarding_done', false);

        foreach (Account::all() as $account) {
            try {
                $this->quotaStatus[$account->id] = $accounts->refreshQuota($account);
            } catch (\Throwable) {
                $this->quotaStatus[$account->id] = false;
            }
        }
    }

    public function dismissOnboarding(SettingsRepository $settings): void
    {
        $settings->set('onboarding_done', true);
        $this->showOnboarding = false;
    }

    /** @return Collection<int,Account> */
    public function getAccountsProperty(): Collection
    {
        return Account::query()->orderBy('name')->get();
    }

    public function render()
    {
        return view('livewire.pages.dashboard', [
            'accounts' => $this->accounts,
        ]);
    }
}
