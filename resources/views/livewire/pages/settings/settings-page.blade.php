<div class="space-y-8 max-w-2xl">
    <div>
        <flux:heading size="xl">{{ __('settings.title') }}</flux:heading>
        <flux:subheading>{{ __('settings.subtitle') }}</flux:subheading>
    </div>

    {{-- General --}}
    <flux:card>
        <flux:heading size="lg">{{ __('settings.section_general') }}</flux:heading>
        <form wire:submit="saveGeneral" class="mt-4 space-y-4">
            <flux:select wire:model="language" :label="__('settings.language')">
                <option value="en">English</option>
                <option value="pt-BR">Português (Brasil)</option>
            </flux:select>
            <flux:input wire:model="mount_base" :label="__('settings.mount_base')" :description="__('settings.mount_base_hint')" class="font-mono" />
            <flux:button type="submit" variant="primary">{{ __('common.save') }}</flux:button>
        </form>
    </flux:card>

    {{-- Network --}}
    <flux:card>
        <flux:heading size="lg">{{ __('settings.section_network') }}</flux:heading>
        <form wire:submit="saveNetwork" class="mt-4 space-y-4">
            <flux:input type="number" wire:model="bandwidth_limit_kbps"
                :label="__('settings.bandwidth_limit')"
                :description="__('settings.bandwidth_hint')" min="0" />
            <flux:button type="submit" variant="primary">{{ __('common.save') }}</flux:button>
        </form>
    </flux:card>

    {{-- Change password --}}
    <flux:card>
        <flux:heading size="lg">{{ __('settings.section_password') }}</flux:heading>
        <form wire:submit="changePassword" class="mt-4 space-y-4">
            <flux:input wire:model="current_password" type="password" :label="__('settings.current_password')" viewable />
            <flux:input wire:model="new_password" type="password" :label="__('settings.new_password')" :description="__('wizard.password_hint')" viewable />
            <flux:input wire:model="new_password_confirmation" type="password" :label="__('wizard.password_confirm')" viewable />
            <flux:button type="submit" variant="primary">{{ __('settings.change_password') }}</flux:button>
        </form>
    </flux:card>

    {{-- About --}}
    <flux:card>
        <flux:heading size="lg">{{ __('settings.section_about') }}</flux:heading>
        <dl class="mt-4 text-sm divide-y divide-zinc-100 dark:divide-zinc-800">
            <div class="flex justify-between py-2">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ config('rnvsync.name') }}</dt>
                <dd class="font-mono">{{ $appVersion }}</dd>
            </div>
            <div class="flex justify-between py-2">
                <dt class="text-zinc-500 dark:text-zinc-400">rclone</dt>
                <dd class="font-mono">{{ $rcloneVersion }}</dd>
            </div>
        </dl>
        <p class="mt-4 text-xs text-zinc-500 dark:text-zinc-400">{{ __('common.powered_by_rclone') }}</p>
    </flux:card>
</div>
