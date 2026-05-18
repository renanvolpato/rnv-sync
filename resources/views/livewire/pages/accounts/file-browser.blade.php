<div>
    @php
        use App\Support\Bytes;
        $isDownloaded = fn ($s) => in_array($s, ['downloaded', 'cached', 'pinned'], true);
    @endphp

    {{-- Cache panel only in FUSE/mount mode --}}
    @unless ($physical)
        <div class="mb-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 flex items-center justify-between gap-4">
            <div class="flex-1">
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-zinc-600 dark:text-zinc-400">
                        {{ __('cache.usage') }}: {{ Bytes::human($cacheStats['usage']) }} / {{ Bytes::human($cacheStats['limit']) }}
                        · {{ $cacheStats['files'] }} {{ __('cache.files') }}
                    </span>
                    <span class="font-medium">{{ $cacheStats['percent'] }}%</span>
                </div>
                <div class="h-2 rounded-full bg-zinc-200 dark:bg-zinc-800 overflow-hidden">
                    <div class="h-full bg-sky-600" style="width: {{ min($cacheStats['percent'], 100) }}%"></div>
                </div>
            </div>
            <flux:button wire:click="freeAll" wire:confirm="{{ __('cache.free_all_confirm') }}"
                variant="ghost" size="sm" icon="trash">{{ __('cache.free_all') }}</flux:button>
        </div>
    @endunless

    <div class="flex items-center gap-2 mb-4 text-sm flex-wrap">
        @foreach ($this->breadcrumbs() as $crumb)
            @if (! $loop->first)
                <flux:icon.chevron-right class="size-3.5 text-zinc-400" />
            @endif
            <button wire:click="goTo('{{ $crumb['path'] }}')"
                @class(['hover:underline', 'font-semibold' => $loop->last,
                    'text-zinc-500 dark:text-zinc-400' => ! $loop->last])
            >{{ $crumb['label'] }}</button>
        @endforeach
    </div>

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden" wire:poll.5s>
        @if ($rcloneUnavailable)
            <div class="p-10 text-center">
                <flux:icon.exclamation-triangle class="size-10 mx-auto text-amber-500" />
                <p class="mt-3 font-medium">{{ __('errors.rclone_unavailable_title') }}</p>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('errors.rclone_unavailable_body') }}</p>
            </div>
        @elseif (empty($entries))
            <div class="p-10 text-center">
                <flux:icon.folder class="size-10 mx-auto text-zinc-400" />
                <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">{{ __('accounts.empty_folder') }}</p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead class="border-b border-zinc-200 dark:border-zinc-800 text-left text-zinc-500 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-2 font-medium">{{ __('accounts.col_name') }}</th>
                        <th class="px-4 py-2 font-medium">{{ __('cache.col_status') }}</th>
                        <th class="px-4 py-2 font-medium text-right">{{ __('accounts.col_size') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        @php
                            $syncing = $entry['status'] === 'syncing';
                            $err = ($entry['status'] ?? '') === 'error';
                            $down = $isDownloaded($entry['status']);
                        @endphp
                        <tr class="border-b border-zinc-100 dark:border-zinc-800/60 last:border-0 hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <td class="px-4 py-2.5">
                                @if ($entry['is_dir'])
                                    <button wire:click="open('{{ addslashes($entry['name']) }}')" class="flex items-center gap-2 hover:underline">
                                        <flux:icon.folder class="size-4 text-sky-600 dark:text-sky-500" />
                                        {{ $entry['name'] }}
                                    </button>
                                @else
                                    <span class="flex items-center gap-2">
                                        <flux:icon.document class="size-4 text-zinc-400" />
                                        {{ $entry['name'] }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                @if ($syncing)
                                    <span class="inline-flex items-center gap-1.5 text-sky-600 dark:text-sky-500"
                                        title="{{ __('cache.tip_syncing') }}">
                                        <flux:icon.arrow-path class="size-4 animate-spin" />
                                        <span class="text-xs">{{ __('cache.status_syncing') }}</span>
                                    </span>
                                @elseif ($err)
                                    <span class="inline-flex items-center gap-1.5 text-rose-600 dark:text-rose-500"
                                        title="{{ $entry['errmsg'] ?? __('cache.tip_error') }}">
                                        <flux:icon.exclamation-triangle class="size-4" />
                                        <span class="text-xs">{{ __('cache.status_error') }}</span>
                                    </span>
                                @elseif ($down)
                                    <span class="inline-flex items-center gap-1.5 text-emerald-600 dark:text-emerald-500"
                                        title="{{ __('cache.tip_downloaded') }}">
                                        <flux:icon.check-circle variant="solid" class="size-4" />
                                        <span class="text-xs">{{ __('cache.status_downloaded') }}</span>
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 text-sky-600 dark:text-sky-500"
                                        title="{{ __('cache.tip_cloud') }}">
                                        <flux:icon.cloud variant="solid" class="size-4" />
                                        <span class="text-xs">{{ __('cache.status_cloud') }}</span>
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right text-zinc-500 dark:text-zinc-400 font-mono text-xs">
                                {{ $entry['is_dir'] ? '—' : Bytes::human($entry['size']) }}
                            </td>
                            <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                @if ($syncing)
                                    <span class="text-xs text-zinc-400">{{ __('common.loading') }}</span>
                                @elseif ($err)
                                    <span title="{{ $entry['errmsg'] ?? __('cache.tip_error') }}">
                                        <flux:button wire:click="download('{{ addslashes($entry['name']) }}', {{ $entry['is_dir'] ? 'true' : 'false' }}, {{ $entry['size'] }})" size="xs" variant="ghost" icon="arrow-path">
                                            {{ __('common.retry') }}
                                        </flux:button>
                                    </span>
                                @elseif ($down)
                                    <span title="{{ __('cache.tip_free_action') }}">
                                        <flux:button wire:click="free('{{ addslashes($entry['name']) }}')" size="xs" variant="ghost" icon="cloud">
                                            {{ __('cache.free') }}
                                        </flux:button>
                                    </span>
                                @else
                                    <span title="{{ __('cache.tip_download_action') }}">
                                        <flux:button wire:click="download('{{ addslashes($entry['name']) }}', {{ $entry['is_dir'] ? 'true' : 'false' }}, {{ $entry['size'] }})" size="xs" variant="ghost" icon="arrow-down-tray">
                                            {{ __('cache.download') }}
                                        </flux:button>
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
        {{ $physical ? __('cache.physical_note') : __('cache.fod_note') }}
    </p>
</div>
