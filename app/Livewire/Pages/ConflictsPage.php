<?php

namespace App\Livewire\Pages;

use App\Models\Account;
use App\Models\Conflict;
use App\Services\Conflicts\ConflictsService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Conflict resolution (SPEC F4.5/F4.6 / Key Screen 7).
 */
#[Layout('components.layouts.app')]
class ConflictsPage extends Component
{
    public function resolve(int $conflictId, string $choice, ConflictsService $conflicts): void
    {
        $conflicts->resolve(Conflict::findOrFail($conflictId), $choice);
        $this->dispatch('toast', type: 'success', message: __('conflicts.resolved'));
    }

    public function resolveAll(int $accountId, string $choice, ConflictsService $conflicts): void
    {
        $conflicts->resolveAll(Account::findOrFail($accountId), $choice);
        $this->dispatch('toast', type: 'success', message: __('conflicts.resolved_all'));
    }

    public function render()
    {
        return view('livewire.pages.conflicts', [
            'conflicts' => Conflict::with('account')
                ->where('status', 'pending')
                ->orderByDesc('detected_at')
                ->get()
                ->groupBy('account_id'),
        ]);
    }
}
