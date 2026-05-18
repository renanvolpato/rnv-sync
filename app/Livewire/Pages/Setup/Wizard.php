<?php

namespace App\Livewire\Pages\Setup;

use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * First-run setup wizard (SPEC F1.2 / Key Screen 1).
 *
 * Language is chosen on the very first screen and applied immediately,
 * so the whole wizard reads in the user's language. Steps:
 *   1. Welcome + language   2. Create panel account   3. Mount location
 * Guarded by EnsureSetupComplete; only reachable before a user exists.
 */
#[Layout('components.layouts.guest')]
class Wizard extends Component
{
    public int $step = 1;

    public const LAST_STEP = 3;

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $language = 'en';

    public string $mount_base = '';

    public function mount(SettingsRepository $settings): void
    {
        // Best guess from browser/Accept-Language; the user can change it
        // on the first screen and it switches live.
        $this->language = app()->getLocale();
        $this->mount_base = $settings->mountBase();
    }

    /** Live language switch (wire:model.live) — re-renders translated. */
    public function updatedLanguage(string $value): void
    {
        if (! in_array($value, config('rnvsync.available_locales'), true)) {
            $this->language = config('rnvsync.default_locale');
        }

        app()->setLocale($this->language);
        session()->put('locale_preview', $this->language);
    }

    public function next(): void
    {
        app()->setLocale($this->language);

        if ($this->step === 2) {
            $this->validate([
                'email' => 'required|email',
                'password' => 'required|string|min:'.config('rnvsync.defaults.password_min_length').'|confirmed',
            ]);
        }

        $this->step = min($this->step + 1, self::LAST_STEP);
    }

    public function back(): void
    {
        $this->step = max($this->step - 1, 1);
    }

    public function finish(SettingsRepository $settings): void
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:'.config('rnvsync.defaults.password_min_length').'|confirmed',
            'language' => 'required|in:'.implode(',', config('rnvsync.available_locales')),
            'mount_base' => 'required|string',
        ]);

        $user = User::create([
            'email' => $this->email,
            'password' => Hash::make($this->password),
        ]);

        $settings->set(SettingsRepository::KEY_LANGUAGE, $this->language);
        $settings->set(SettingsRepository::KEY_MOUNT_BASE, $this->mount_base);
        $settings->set(SettingsRepository::KEY_THEME, config('rnvsync.defaults.theme'));

        Auth::login($user);
        session()->regenerate();
        session()->forget('locale_preview');

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.pages.setup.wizard');
    }
}
