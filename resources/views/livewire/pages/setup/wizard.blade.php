<div>
    {{-- Progress dots --}}
    <div class="flex items-center justify-center gap-2 mb-6">
        @for ($i = 1; $i <= \App\Livewire\Pages\Setup\Wizard::LAST_STEP; $i++)
            <span @class([
                'size-2.5 rounded-full transition-colors',
                'bg-sky-600 dark:bg-sky-500' => $i <= $step,
                'bg-zinc-300 dark:bg-zinc-700' => $i > $step,
            ])></span>
        @endfor
    </div>

    <flux:card>
        {{-- Step 1: Welcome --}}
        @if ($step === 1)
            <flux:heading size="lg">{{ __('wizard.welcome_title') }}</flux:heading>
            <flux:subheading class="mt-2">{{ __('wizard.welcome_body') }}</flux:subheading>
            <ul class="mt-4 space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
                <li class="flex gap-2"><flux:icon.check class="size-4 text-emerald-600 shrink-0" /> {{ __('wizard.welcome_point_1') }}</li>
                <li class="flex gap-2"><flux:icon.check class="size-4 text-emerald-600 shrink-0" /> {{ __('wizard.welcome_point_2') }}</li>
                <li class="flex gap-2"><flux:icon.check class="size-4 text-emerald-600 shrink-0" /> {{ __('wizard.welcome_point_3') }}</li>
            </ul>
        @endif

        {{-- Step 2: Create panel account --}}
        @if ($step === 2)
            <flux:heading size="lg">{{ __('wizard.account_title') }}</flux:heading>
            <flux:subheading class="mb-4">{{ __('wizard.account_subtitle') }}</flux:subheading>
            <div class="space-y-4">
                <flux:input wire:model="email" type="email" :label="__('auth.email')" autocomplete="username" />
                <flux:input wire:model="password" type="password" :label="__('auth.password_label')" :description="__('wizard.password_hint')" viewable />
                <flux:input wire:model="password_confirmation" type="password" :label="__('wizard.password_confirm')" viewable />
            </div>
        @endif

        {{-- Step 3: Language --}}
        @if ($step === 3)
            <flux:heading size="lg">{{ __('wizard.language_title') }}</flux:heading>
            <flux:subheading class="mb-4">{{ __('wizard.language_subtitle') }}</flux:subheading>
            <flux:select wire:model="language" :label="__('settings.language')">
                <option value="en">English</option>
                <option value="pt-BR">Português (Brasil)</option>
            </flux:select>
        @endif

        {{-- Step 4: Mount location --}}
        @if ($step === 4)
            <flux:heading size="lg">{{ __('wizard.mount_title') }}</flux:heading>
            <flux:subheading class="mb-4">{{ __('wizard.mount_subtitle') }}</flux:subheading>
            <flux:input wire:model="mount_base" :label="__('settings.mount_base')" :description="__('wizard.mount_hint')" class="font-mono" />
        @endif

        <div class="mt-6 flex items-center justify-between">
            <flux:button wire:click="back" variant="ghost" :disabled="$step === 1">
                {{ __('common.back') }}
            </flux:button>

            @if ($step < \App\Livewire\Pages\Setup\Wizard::LAST_STEP)
                <flux:button wire:click="next" variant="primary">{{ __('common.next') }}</flux:button>
            @else
                <flux:button wire:click="finish" variant="primary">
                    <span wire:loading.remove wire:target="finish">{{ __('wizard.finish') }}</span>
                    <span wire:loading wire:target="finish">{{ __('common.loading') }}</span>
                </flux:button>
            @endif
        </div>
    </flux:card>
</div>
