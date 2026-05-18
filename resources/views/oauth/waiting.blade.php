<x-layouts.app :title="__('accounts.connecting')">
    <div class="max-w-md mx-auto"
        x-data="{
            authUrl: @js($authUrl),
            statusUrl: @js($statusUrl),
            cancelUrl: @js($cancelUrl),
            state: 'pending',
            message: '',
            win: null,
            init() { this.openWindow(); this.poll(); },
            openWindow() {
                this.win = window.open(this.authUrl, 'rnvsync_oauth', 'width=600,height=720');
            },
            async poll() {
                try {
                    const r = await fetch(this.statusUrl, { headers: { 'Accept': 'application/json' } });
                    const d = await r.json();
                    if (d.state === 'ready') { if (this.win) this.win.close(); window.location = d.redirect; return; }
                    if (d.state === 'error') { this.state = 'error'; this.message = d.message || 'Error'; return; }
                } catch (e) { /* transient — keep polling */ }
                setTimeout(() => this.poll(), 2500);
            },
        }"
    >
        <flux:card class="text-center">
            <flux:icon.cloud-arrow-up class="size-12 mx-auto text-sky-600 dark:text-sky-500" />
            <flux:heading size="lg" class="mt-4">{{ __('accounts.connecting') }}</flux:heading>

            <template x-if="state==='pending'">
                <div>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('accounts.connecting_hint') }}
                    </p>
                    <div class="mt-5">
                        <flux:button variant="primary" x-on:click="openWindow()">
                            {{ __('accounts.open_microsoft') }}
                        </flux:button>
                        <p class="mt-3 text-xs text-zinc-500">
                            {{ __('accounts.popup_blocked') }}
                            <a :href="authUrl" target="_blank" class="text-sky-600 hover:underline">{{ __('accounts.open_link') }}</a>
                        </p>
                    </div>
                    <div class="mt-5 flex items-center justify-center gap-2 text-sm text-zinc-500">
                        <flux:icon.arrow-path class="size-4 animate-spin" />
                        {{ __('accounts.waiting_auth') }}
                    </div>
                </div>
            </template>

            <template x-if="state==='error'">
                <div>
                    <p class="mt-4 text-sm text-rose-600" x-text="message"></p>
                    <flux:button class="mt-4" variant="ghost" :href="route('accounts.new')">
                        {{ __('common.retry') }}
                    </flux:button>
                </div>
            </template>
        </flux:card>
    </div>
</x-layouts.app>
