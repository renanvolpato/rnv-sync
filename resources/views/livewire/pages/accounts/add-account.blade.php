<div>
    <flux:button :href="route('dashboard')" variant="ghost" size="sm" icon="arrow-left" class="mb-4">
        {{ __('common.back') }}
    </flux:button>

    <flux:heading size="xl">{{ __('accounts.add_account') }}</flux:heading>
    <flux:subheading class="mb-6">{{ __('accounts.add_subtitle') }}</flux:subheading>

    @if ($errors->has('oauth'))
        <flux:callout variant="danger" icon="x-circle" class="mb-6">
            {{ $errors->first('oauth') }}
        </flux:callout>
    @endif

    {{-- Easy mode (default): zero configuration --}}
    <flux:card>
        <div class="flex items-center gap-2">
            <flux:icon.cloud class="size-6 text-sky-600 dark:text-sky-500" />
            <flux:heading size="lg">OneDrive</flux:heading>
        </div>
        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
            {{ __('accounts.easy_hint') }}
        </p>
        <flux:button :href="route('oauth.easy.start')" variant="primary" icon="arrow-top-right-on-square" class="mt-4">
            {{ __('accounts.login_with_microsoft') }}
        </flux:button>
        <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
            {{ __('accounts.no_registration_note') }}
        </p>
    </flux:card>

    {{-- Advanced: bring your own Microsoft Entra app --}}
    <div x-data="{ open: false }" class="mt-4">
        <flux:button variant="ghost" size="sm" x-on:click="open = !open" icon="cog-6-tooth">
            {{ __('accounts.advanced_toggle') }}
        </flux:button>

        <div x-show="open" x-cloak class="mt-3">
            <flux:card>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('accounts.advanced_hint') }}
                </p>

                <div class="grid gap-3 sm:grid-cols-3 my-4">
                    @foreach ([
                        'onedrive_personal' => 'OneDrive Personal',
                        'onedrive_business' => 'OneDrive Business',
                        'sharepoint' => 'SharePoint',
                    ] as $value => $label)
                        <button type="button" wire:click="$set('provider', '{{ $value }}')"
                            @class([
                                'rounded-xl border-2 p-4 text-left transition-colors',
                                'border-sky-600 dark:border-sky-500 bg-white dark:bg-zinc-900' => $provider === $value,
                                'border-zinc-200 dark:border-zinc-800' => $provider !== $value,
                            ])>
                            <span class="font-medium">{{ $label }}</span>
                            <span class="mt-1 block text-xs text-zinc-500 dark:text-zinc-400">
                                {{ __('accounts.provider_'.\Illuminate\Support\Str::after($value, 'onedrive_').'_desc') }}
                            </span>
                        </button>
                    @endforeach
                </div>

                @if ($provider === 'sharepoint')
                    <flux:input wire:model="documentLibraryUrl" :label="__('accounts.sharepoint_url')"
                        :description="__('accounts.sharepoint_url_hint')" class="mb-4 font-mono" />
                @endif

                <flux:button :href="route('oauth.start', ['provider' => $provider])" variant="filled" icon="arrow-top-right-on-square">
                    {{ __('accounts.login_with_microsoft') }}
                </flux:button>
                <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('accounts.advanced_oauth_note') }}
                </p>
            </flux:card>
        </div>
    </div>
</div>
