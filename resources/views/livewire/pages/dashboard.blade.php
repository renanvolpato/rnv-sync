<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <flux:heading size="xl">{{ __('dashboard.title') }}</flux:heading>
            <flux:subheading>{{ __('dashboard.subtitle') }}</flux:subheading>
        </div>
        <flux:button :href="route('accounts.new')" variant="primary" icon="plus">
            {{ __('accounts.add_account') }}
        </flux:button>
    </div>

    @if ($accounts->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-700 p-12 text-center">
            <flux:icon.cloud-arrow-up class="size-12 mx-auto text-zinc-400" />
            <h3 class="mt-4 font-semibold">{{ __('dashboard.empty_title') }}</h3>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('dashboard.empty_body') }}</p>
            <flux:button :href="route('accounts.new')" variant="primary" class="mt-5" icon="plus">
                {{ __('dashboard.empty_action') }}
            </flux:button>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($accounts as $account)
                <x-rnvsync.account-card
                    :account="$account"
                    :quota-ok="$quotaStatus[$account->id] ?? true"
                />
            @endforeach
        </div>
    @endif
</div>
