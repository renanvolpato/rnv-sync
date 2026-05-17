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
 * Four steps: Welcome → Create panel account → Language → Mount location.
 * Guarded by EnsureSetupComplete so it is only reachable before a user
 * exists.
 */
#[Layout('components.layouts.guest')]
class Wizard extends Component
{
    public int $step = 1;

    public const LAST_STEP = 4;

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $language = 'en';

    public string $mount_base = '';

    public function mount(SettingsRepository $settings): void
    {
        $this->language = app()->getLocale();
        $this->mount_base = $settings->mountBase();
    }

    public function next(): void
    {
        if ($this->step === 2) {
            $this->validate([
                'email' => 'required|email',
                'password' => 'required|string|min:'.config('rnvsync.defaults.password_min_length').'|confirmed',
            ]);
        }

        if ($this->step === 3) {
            $this->validate(['language' => 'required|in:'.implode(',', config('rnvsync.available_locales'))]);
            app()->setLocale($this->language);
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

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.pages.setup.wizard');
    }
}
