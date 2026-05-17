<div>
    <flux:button :href="route('dashboard')" variant="ghost" size="sm" icon="arrow-left" class="mb-4">
        {{ __('common.back') }}
    </flux:button>

    <flux:heading size="xl">{{ __('accounts.add_account') }}</flux:heading>
    <flux:subheading class="mb-6">{{ __('accounts.add_subtitle') }}</flux:subheading>

    @if ($errors->has('oauth'))
        <flux:callout variant="danger" icon="x-circle" class="mb-6">
            {{ $errors->first('oauth') }}
            <flux:button :href="route('oauth.start')" variant="ghost" size="sm" class="mt-2">
                {{ __('common.retry') }}
            </flux:button>
        </flux:callout>
    @endif

    <div class="grid gap-3 sm:grid-cols-3 mb-6">
        <label class="rounded-xl border-2 border-sky-600 dark:border-sky-500 bg-white dark:bg-zinc-900 p-4 cursor-pointer">
            <div class="flex items-center gap-2 font-medium">
                <flux:icon.cloud class="size-5 text-sky-600 dark:text-sky-500" />
                OneDrive Personal
            </div>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('accounts.provider_personal_desc') }}</p>
        </label>

        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 p-4 opacity-50">
            <div class="flex items-center gap-2 font-medium">OneDrive Business</div>
            <p class="mt-1 text-xs text-zinc-500">{{ __('accounts.coming_later') }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 p-4 opacity-50">
            <div class="flex items-center gap-2 font-medium">SharePoint</div>
            <p class="mt-1 text-xs text-zinc-500">{{ __('accounts.coming_later') }}</p>
        </div>
    </div>

    <flux:button :href="route('oauth.start')" variant="primary" icon="arrow-top-right-on-square">
        {{ __('accounts.login_with_microsoft') }}
    </flux:button>

    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
        {{ __('accounts.oauth_redirect_note') }}
    </p>
</div>
