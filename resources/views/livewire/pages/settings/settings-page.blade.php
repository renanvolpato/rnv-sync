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

            <flux:checkbox wire:model.live="bw_schedule_enabled" :label="__('settings.bw_schedule_enable')" />
            <div class="grid grid-cols-3 gap-3" @if(! $bw_schedule_enabled) style="display:none" @endif>
                <flux:input type="time" wire:model="bw_schedule_start" :label="__('settings.bw_start')" />
                <flux:input type="time" wire:model="bw_schedule_end" :label="__('settings.bw_end')" />
                <flux:input type="number" wire:model="bw_schedule_kbps" :label="__('settings.bw_window_limit')" min="0" />
            </div>

            <flux:button type="submit" variant="primary">{{ __('common.save') }}</flux:button>
        </form>
    </flux:card>

    {{-- Cache --}}
    <flux:card>
        <flux:heading size="lg">{{ __('cache.section_cache') }}</flux:heading>
        <form wire:submit="saveCache" class="mt-4 space-y-4">
            <flux:input type="number" wire:model="cache_max_gb"
                :label="__('cache.max_size_gb')"
                :description="__('cache.max_size_hint')" min="1" />
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

    {{-- Config export / import (SPEC F5.9) --}}
    <flux:card>
        <flux:heading size="lg">{{ __('settings.section_config') }}</flux:heading>
        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('settings.config_hint') }}</p>
        <div class="mt-4 flex flex-wrap items-end gap-3">
            <flux:button :href="route('config.export')" variant="ghost" icon="arrow-down-tray">
                {{ __('settings.config_export') }}
            </flux:button>
            <form wire:submit="importConfig" class="flex items-end gap-2">
                <flux:input type="file" wire:model="configFile" :label="__('settings.config_import')" accept="application/json" />
                <flux:button type="submit" variant="primary">{{ __('settings.config_import_btn') }}</flux:button>
            </form>
        </div>
    </flux:card>
</div>
