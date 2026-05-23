<?php

use App\Http\Controllers\ConfigController;
use App\Http\Controllers\OAuthController;
use App\Http\Controllers\RequirementsController;
use App\Http\Controllers\SyncStateController;
use App\Livewire\Pages\Accounts\AddAccount;
use App\Livewire\Pages\Accounts\FileBrowser;
use App\Livewire\Pages\Accounts\SyncActivity;
use App\Livewire\Pages\Auth\Login;
use App\Livewire\Pages\ConflictsPage;
use App\Livewire\Pages\Dashboard;
use App\Livewire\Pages\SearchPage;
use App\Livewire\Pages\Settings\SettingsPage;
use App\Livewire\Pages\Setup\Wizard;
use App\Livewire\Pages\TrendsPage;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Environment preflight (WordPress-style). Stateless: session and CSRF
// middleware are stripped so it renders even with no SQLite driver.
Route::get('/requirements', [RequirementsController::class, 'index'])
    ->name('requirements')
    ->withoutMiddleware([
        EncryptCookies::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
    ]);

// System-tray status poll. Localhost-only, no session/CSRF so the
// lightweight indicator can hit it every few seconds.
Route::get('/sync-state', SyncStateController::class)
    ->name('sync-state')
    ->withoutMiddleware([
        EncryptCookies::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
    ]);

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
    Route::get('/accounts/{account}/activity', SyncActivity::class)->name('accounts.activity');

    // Zero-config (default) — rclone's own OAuth, no app registration.
    Route::get('/oauth/easy', [OAuthController::class, 'easyStart'])->name('oauth.easy.start');
    Route::get('/oauth/easy/status', [OAuthController::class, 'easyStatus'])->name('oauth.easy.status');

    // Advanced — in-app flow with the user's own Microsoft Entra app.
    Route::get('/oauth/start', [OAuthController::class, 'start'])->name('oauth.start');
    Route::get('/oauth/callback', [OAuthController::class, 'callback'])->name('oauth.callback');

    Route::get('/conflicts', ConflictsPage::class)->name('conflicts');
    Route::get('/search', SearchPage::class)->name('search');
    Route::get('/trends', TrendsPage::class)->name('trends');
    Route::get('/config/export', [ConfigController::class, 'export'])->name('config.export');

    Route::get('/settings', SettingsPage::class)->name('settings');
});
