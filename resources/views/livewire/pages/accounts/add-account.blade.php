<div>
    <flux:button :href="route('dashboard')" variant="ghost" size="sm" icon="arrow-left" class="mb-4">
        {{ __('common.back') }}
    </flux:button>

    <flux:heading size="xl">{{ __('accounts.add_account') }}</flux:heading>
    <flux:subheading class="mb-6">{{ __('accounts.add_subtitle') }}</flux:subheading>

    @if ($errors->has('oauth'))
        <flux:callout variant="danger" icon="x-circle" class="mb-6">
            {{ $errors->first('oauth') }}
            <flux:button :href="route('oauth.start', ['provider' => $provider])" variant="ghost" size="sm" class="mt-2">
                {{ __('common.retry') }}
            </flux:button>
        </flux:callout>
    @endif

    <div class="grid gap-3 sm:grid-cols-3 mb-6">
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
                <div class="flex items-center gap-2 font-medium">
                    <flux:icon.cloud class="size-5 text-sky-600 dark:text-sky-500" />
                    {{ $label }}
                </div>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('accounts.provider_'.\Illuminate\Support\Str::after($value, 'onedrive_') . '_desc') }}
                </p>
            </button>
        @endforeach
    </div>

    @if ($provider === 'sharepoint')
        <flux:input wire:model="documentLibraryUrl" :label="__('accounts.sharepoint_url')"
            :description="__('accounts.sharepoint_url_hint')" class="mb-4 font-mono" />
    @endif

    <flux:button :href="route('oauth.start', ['provider' => $provider])" variant="primary" icon="arrow-top-right-on-square">
        {{ __('accounts.login_with_microsoft') }}
    </flux:button>

    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
        {{ __('accounts.oauth_redirect_note') }}
    </p>
</div>
