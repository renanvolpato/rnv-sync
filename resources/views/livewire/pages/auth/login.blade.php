<div>
    <flux:card>
        <flux:heading size="lg">{{ __('auth.login_title') }}</flux:heading>
        <flux:subheading class="mb-6">{{ __('auth.login_subtitle') }}</flux:subheading>

        <form wire:submit="login" class="space-y-5">
            <flux:input
                wire:model="email"
                type="email"
                :label="__('auth.email')"
                autocomplete="username"
                autofocus
                required
            />

            <flux:input
                wire:model="password"
                type="password"
                :label="__('auth.password_label')"
                autocomplete="current-password"
                viewable
                required
            />

            <flux:checkbox wire:model="remember" :label="__('auth.remember_me')" />

            <flux:button type="submit" variant="primary" class="w-full">
                <span wire:loading.remove wire:target="login">{{ __('auth.sign_in') }}</span>
                <span wire:loading wire:target="login">{{ __('common.loading') }}</span>
            </flux:button>
        </form>
    </flux:card>

    <p class="mt-4 text-center text-xs text-zinc-500 dark:text-zinc-400">
        {{ __('auth.forgot_hint') }}
    </p>
</div>
