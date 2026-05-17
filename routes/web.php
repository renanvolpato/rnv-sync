<?php

use App\Http\Controllers\OAuthController;
use App\Livewire\Pages\Accounts\AddAccount;
use App\Livewire\Pages\Accounts\FileBrowser;
use App\Livewire\Pages\Accounts\FolderSelection;
use App\Livewire\Pages\Accounts\SyncActivity;
use App\Livewire\Pages\Auth\Login;
use App\Livewire\Pages\ConflictsPage;
use App\Livewire\Pages\Dashboard;
use App\Livewire\Pages\Settings\SettingsPage;
use App\Livewire\Pages\Setup\Wizard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// First-run setup wizard (gated by EnsureSetupComplete middleware).
Route::get('/setup', Wizard::class)->name('setup.index');

// Guest authentication.
Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
});

Route::post('/logout', function () {
    Auth::logout();
    session()->invalidate();
    session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');

// Authenticated panel.
Route::middleware('auth')->group(function () {
    Route::get('/', Dashboard::class)->name('dashboard');

    Route::get('/accounts/new', AddAccount::class)->name('accounts.new');
    Route::get('/accounts/{account}/files', FileBrowser::class)->name('accounts.files');
    Route::get('/accounts/{account}/folders', FolderSelection::class)->name('accounts.folders');
    Route::get('/accounts/{account}/activity', SyncActivity::class)->name('accounts.activity');

    Route::get('/oauth/start', [OAuthController::class, 'start'])->name('oauth.start');
    Route::get('/oauth/callback', [OAuthController::class, 'callback'])->name('oauth.callback');

    Route::get('/conflicts', ConflictsPage::class)->name('conflicts');

    Route::get('/settings', SettingsPage::class)->name('settings');
});
