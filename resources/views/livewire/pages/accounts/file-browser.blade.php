<div>
    @php use App\Support\Bytes; @endphp

    <div class="flex items-center gap-2 mb-4 text-sm flex-wrap">
        @foreach ($this->breadcrumbs() as $i => $crumb)
            @if (! $loop->first)
                <flux:icon.chevron-right class="size-3.5 text-zinc-400" />
            @endif
            <button
                wire:click="goTo('{{ $crumb['path'] }}')"
                @class([
                    'hover:underline',
                    'font-semibold' => $loop->last,
                    'text-zinc-500 dark:text-zinc-400' => ! $loop->last,
                ])
            >{{ $crumb['label'] }}</button>
        @endforeach
    </div>

    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden">
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
                        <th class="px-4 py-2 font-medium text-right">{{ __('accounts.col_size') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
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
                            <td class="px-4 py-2.5 text-right text-zinc-500 dark:text-zinc-400 font-mono text-xs">
                                {{ $entry['is_dir'] ? '—' : Bytes::human($entry['size']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">{{ __('accounts.read_only_note') }}</p>
</div>
