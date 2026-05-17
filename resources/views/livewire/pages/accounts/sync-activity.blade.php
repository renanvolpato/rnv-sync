<div
    x-data="{
        progress: null,
        init() {
            if (!window.Echo) return;
            window.Echo.channel('rnvsync')
                .listen('.sync.progress', e => {
                    this.progress = e;
                    if (e.percent >= 100) setTimeout(() => this.progress = null, 1500);
                })
                .listen('.sync.status', () => $wire.$refresh());
        }
    }"
>
    <div class="flex items-center justify-between mb-6">
        <div>
            <flux:heading size="xl">{{ $account->name }}</flux:heading>
            <flux:subheading>{{ __('sync.activity_subtitle') }}</flux:subheading>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('accounts.folders', $account)" variant="ghost" icon="folder-plus">
                {{ __('sync.select_folders') }}
            </flux:button>
            <flux:button wire:click="togglePause" variant="{{ $paused ? 'primary' : 'ghost' }}"
                icon="{{ $paused ? 'play' : 'pause' }}">
                {{ $paused ? __('sync.resume') : __('sync.pause') }}
            </flux:button>
        </div>
    </div>

    {{-- Real-time progress (SPEC F2.7) --}}
    <template x-if="progress">
        <div class="mb-6 rounded-xl border border-sky-200 dark:border-sky-900 bg-sky-50 dark:bg-sky-950/40 p-4">
            <div class="flex justify-between text-sm mb-2">
                <span class="font-medium" x-text="progress.currentFile || '{{ __('sync.syncing') }}'"></span>
                <span x-text="Math.round(progress.percent) + '%'"></span>
            </div>
            <div class="h-2 rounded-full bg-sky-200 dark:bg-sky-900 overflow-hidden">
                <div class="h-full bg-sky-600 transition-all" :style="`width: ${progress.percent}%`"></div>
            </div>
        </div>
    </template>

    {{-- Folders --}}
    <flux:card class="mb-8">
        <flux:heading size="lg">{{ __('sync.folders') }}</flux:heading>
        <div class="mt-3 divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse ($folders as $folder)
                <div class="flex items-center justify-between py-3">
                    <div class="min-w-0">
                        <p class="font-mono text-sm truncate">{{ $folder->remote_path }}</p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ __('dashboard.last_sync') }}:
                            {{ $folder->last_synced_at?->diffForHumans() ?? __('dashboard.never') }}
                            @if ($folder->last_sync_status)
                                · {{ __('sync.status_'.$folder->last_sync_status) }}
                            @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button wire:click="syncNow({{ $folder->id }})" size="sm" variant="ghost" icon="arrow-path">
                            {{ __('sync.sync_now') }}
                        </flux:button>
                        <flux:button wire:click="toggleFolder({{ $folder->id }})" size="sm"
                            variant="{{ $folder->is_active ? 'primary' : 'ghost' }}">
                            {{ $folder->is_active ? __('sync.active') : __('sync.inactive') }}
                        </flux:button>
                    </div>
                </div>
            @empty
                <p class="py-6 text-sm text-zinc-500 dark:text-zinc-400">{{ __('sync.no_folders') }}</p>
            @endforelse
        </div>
    </flux:card>

    {{-- History --}}
    <flux:card>
        <flux:heading size="lg">{{ __('sync.history') }}</flux:heading>
        <div class="mt-3 overflow-x-auto" wire:poll.10s>
            <table class="w-full text-sm">
                <thead class="text-left text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="py-2 pr-4 font-medium">{{ __('sync.col_when') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('sync.col_status') }}</th>
                        <th class="py-2 pr-4 font-medium text-right">{{ __('sync.col_files') }}</th>
                        <th class="py-2 font-medium text-right">{{ __('sync.col_bytes') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($history as $run)
                        <tr class="border-b border-zinc-100 dark:border-zinc-800/60 last:border-0">
                            <td class="py-2 pr-4">{{ $run->started_at?->diffForHumans() }}</td>
                            <td class="py-2 pr-4">
                                <flux:badge size="sm" :color="match($run->status) {
                                    'success' => 'emerald', 'running' => 'sky',
                                    'cancelled' => 'amber', default => 'rose' }">
                                    {{ __('sync.run_'.$run->status) }}
                                </flux:badge>
                            </td>
                            <td class="py-2 pr-4 text-right">{{ $run->files_transferred }}</td>
                            <td class="py-2 text-right font-mono text-xs">
                                {{ \App\Support\Bytes::human($run->bytes_transferred) }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-zinc-500 dark:text-zinc-400">
                            {{ __('sync.no_history') }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="mt-4">{{ $history->links() }}</div>
        </div>
    </flux:card>
</div>
