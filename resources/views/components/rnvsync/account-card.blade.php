@props(['account', 'quotaOk' => true])

@php
    use App\Support\Bytes;
    $percent = $account->quotaPercentUsed();
    $statusColor = match ($account->status) {
        'active' => 'emerald',
        'disconnected' => 'amber',
        default => 'rose',
    };
    $barColor = $percent !== null && $percent >= 90 ? 'bg-rose-600'
        : ($percent !== null && $percent >= 80 ? 'bg-amber-500' : 'bg-sky-600');
@endphp

<div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 flex flex-col gap-4">
    <div class="flex items-start justify-between">
        <div class="min-w-0">
            <h3 class="font-semibold truncate">{{ $account->name }}</h3>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 truncate">{{ $account->email ?? '—' }}</p>
        </div>
        <flux:badge :color="$statusColor" size="sm">{{ __('accounts.status_'.$account->status) }}</flux:badge>
    </div>

    <div>
        @if ($quotaOk && $percent !== null)
            <div class="flex justify-between text-sm mb-1">
                <span class="text-zinc-600 dark:text-zinc-400">
                    {{ Bytes::human($account->quota_used_bytes) }} / {{ Bytes::human($account->quota_total_bytes) }}
                </span>
                <span class="font-medium">{{ $percent }}%</span>
            </div>
            <div class="h-2 rounded-full bg-zinc-200 dark:bg-zinc-800 overflow-hidden">
                <div class="h-full {{ $barColor }}" style="width: {{ min($percent, 100) }}%"></div>
            </div>
        @else
            <p class="text-sm text-amber-600 dark:text-amber-500 flex items-center gap-1.5">
                <flux:icon.exclamation-triangle class="size-4" />
                {{ __('dashboard.quota_unavailable') }}
            </p>
        @endif
    </div>

    <div class="flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
        <span>
            {{ __('dashboard.last_sync') }}:
            {{ $account->last_synced_at?->diffForHumans() ?? __('dashboard.never') }}
        </span>
        <div class="flex gap-1">
            <flux:button :href="route('accounts.folders', $account)" variant="primary" size="xs" icon="folder-plus">
                {{ __('sync.select_folders') }}
            </flux:button>
            <flux:button :href="route('accounts.activity', $account)" variant="ghost" size="xs" icon="arrow-path">
                {{ __('sync.synced_title') }}
            </flux:button>
        </div>
    </div>
</div>
