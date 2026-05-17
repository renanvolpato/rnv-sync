<?php

namespace App\Livewire\Pages;

use App\Models\UsageSnapshot;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Storage usage trends (SPEC F5.5). */
#[Layout('components.layouts.app')]
class TrendsPage extends Component
{
    public function render()
    {
        $rows = UsageSnapshot::query()
            ->selectRaw('captured_on, SUM(cloud_used_bytes) as cloud, MAX(cache_used_bytes) as cache')
            ->groupBy('captured_on')
            ->orderBy('captured_on')
            ->limit(30)
            ->get();

        $max = max(1, (int) $rows->max(fn ($r) => max($r->cloud, $r->cache)));

        return view('livewire.pages.trends', [
            'rows' => $rows,
            'max' => $max,
        ]);
    }
}
