<?php

namespace App\Livewire\Pages\Settings;

use App\Services\Rclone\RcloneBinary;
use App\Services\Settings\ConfigService;
use App\Services\Settings\SettingsRepository;
use App\Services\Update\UpdateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Settings (SPEC F1.8 / Key Screen 8). General, Network, Cache, Password,
 * About and config import/export (F5.9).
 */
#[Layout('components.layouts.app')]
class SettingsPage extends Component
{
    use WithFileUploads;

    public string $language = 'en';

    public string $storage_mode = 'physical';

    public string $mount_base = '';

    public ?int $bandwidth_limit_kbps = null;

    public ?int $cache_max_gb = null;

    public bool $bw_schedule_enabled = false;

    public string $bw_schedule_start = '09:00';

    public string $bw_schedule_end = '18:00';

    public ?int $bw_schedule_kbps = null;

    public $configFile;

    public string $current_password = '';

    public string $new_password = '';

    public string $new_password_confirmation = '';

    /** @var array<string,mixed>|null cached update status for the view */
    public ?array $updateStatus = null;

    public bool $updating = false;

    public function mount(SettingsRepository $settings): void
    {
        $this->language = $settings->language();
        $this->storage_mode = $settings->storageMode();
        $this->mount_base = $settings->mountBase();
        $this->bandwidth_limit_kbps = $settings->get('bandwidth_limit_kbps');
        $this->cache_max_gb = $settings->get('cache_max_gb');
        $this->bw_schedule_enabled = (bool) $settings->get('bandwidth_schedule_enabled', false);
        $this->bw_schedule_start = (string) $settings->get('bandwidth_schedule_start', '09:00');
        $this->bw_schedule_end = (string) $settings->get('bandwidth_schedule_end', '18:00');
        $this->bw_schedule_kbps = $settings->get('bandwidth_schedule_kbps');
    }

    public function saveCache(SettingsRepository $settings): void
    {
        $this->validate(['cache_max_gb' => 'nullable|integer|min:1']);

        $settings->set('cache_max_gb', $this->cache_max_gb ?: null);

        session()->flash('status', __('settings.saved'));
        $this->redirectRoute('settings', navigate: true);
    }

    public function saveNetwork(SettingsRepository $settings): void
    {
        $this->validate(['bandwidth_limit_kbps' => 'nullable|integer|min:0']);

        $settings->set('bandwidth_limit_kbps', $this->bandwidth_limit_kbps ?: null);
        $settings->set('bandwidth_schedule_enabled', $this->bw_schedule_enabled);
        $settings->set('bandwidth_schedule_start', $this->bw_schedule_start);
        $settings->set('bandwidth_schedule_end', $this->bw_schedule_end);
        $settings->set('bandwidth_schedule_kbps', $this->bw_schedule_kbps ?: null);

        session()->flash('status', __('settings.saved'));
        $this->redirectRoute('settings', navigate: true);
    }

    public function saveGeneral(SettingsRepository $settings): void
    {
        $this->validate([
            'language' => 'required|in:'.implode(',', config('rnvsync.available_locales')),
            'storage_mode' => 'required|in:physical,mount',
            'mount_base' => 'required|string',
        ]);

        $settings->set(SettingsRepository::KEY_LANGUAGE, $this->language);
        $settings->set(SettingsRepository::KEY_STORAGE_MODE, $this->storage_mode);
        $settings->set(SettingsRepository::KEY_MOUNT_BASE, $this->mount_base);

        app()->setLocale($this->language);

        session()->flash('status', __('settings.saved'));
        $this->redirectRoute('settings', navigate: true);
    }

    public function importConfig(ConfigService $config): void
    {
        $this->validate(['configFile' => 'required|file|max:1024']);

        $config->import($this->configFile->get());

        session()->flash('status', __('settings.config_imported'));
        $this->redirectRoute('settings', navigate: true);
    }

    public function changePassword(): void
    {
        $this->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:'.config('rnvsync.defaults.password_min_length').'|confirmed',
        ]);

        if (! Hash::check($this->current_password, Auth::user()->password)) {
            $this->addError('current_password', __('settings.wrong_current_password'));

            return;
        }

        Auth::user()->update(['password' => Hash::make($this->new_password)]);

        $this->reset('current_password', 'new_password', 'new_password_confirmation');

        session()->flash('status', __('settings.password_changed'));
        $this->redirectRoute('settings', navigate: true);
    }

    /** Explicit "check for updates" (hits the network, then caches). */
    public function checkUpdates(UpdateService $updater): void
    {
        $this->updateStatus = $updater->checkForUpdates(force: true);

        $msg = match (true) {
            $this->updateStatus['error'] === 'not_git' => __('settings.update_not_git'),
            $this->updateStatus['error'] !== null => __('settings.update_check_failed'),
            $this->updateStatus['available'] => __('settings.update_available', ['n' => $this->updateStatus['behind']]),
            default => __('settings.update_up_to_date'),
        };
        $this->dispatch('toast', type: $this->updateStatus['error'] ? 'error' : 'success', message: $msg);
    }

    /** Apply updates: launch the detached updater and tell the user. */
    public function applyUpdate(UpdateService $updater): void
    {
        if (! $updater->isGitInstall()) {
            $this->dispatch('toast', type: 'error', message: __('settings.update_not_git'));

            return;
        }

        $updater->runUpdate();
        $this->updating = true;
        $this->updateStatus = null;
        $this->dispatch('toast', type: 'success', message: __('settings.update_started'));
    }

    public function render(RcloneBinary $binary, UpdateService $updater)
    {
        // Never hit the network on render — only the last cached check.
        if ($this->updateStatus === null) {
            $this->updateStatus = $updater->cachedStatus();
        }

        return view('livewire.pages.settings.settings-page', [
            'appVersion' => 'v1.0.0',
            'appRef' => $updater->currentRef(),
            'isGitInstall' => $updater->isGitInstall(),
            'rcloneVersion' => $binary->version() ?? __('settings.rclone_not_bundled'),
        ]);
    }
}
