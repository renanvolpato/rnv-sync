<?php

use App\Console\Commands\CaptureUsageCommand;
use App\Livewire\Pages\Dashboard;
use App\Livewire\Pages\SearchPage;
use App\Livewire\Pages\Settings\SettingsPage;
use App\Models\Account;
use App\Models\SyncFolder;
use App\Models\UsageSnapshot;
use App\Models\User;
use App\Services\Rclone\RcloneResult;
use App\Services\Rclone\RcloneRunner;
use App\Services\Settings\ConfigService;
use App\Services\Settings\SettingsRepository;
use App\Services\Sync\BandwidthScheduler;
use App\Services\Sync\SyncService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

it('applies the scheduled bandwidth limit only inside the window (F5.2)', function () {
    $s = app(SettingsRepository::class);
    $s->set('bandwidth_limit_kbps', null);
    $s->set('bandwidth_schedule_enabled', true);
    $s->set('bandwidth_schedule_start', '09:00');
    $s->set('bandwidth_schedule_end', '18:00');
    $s->set('bandwidth_schedule_kbps', 200);

    $sched = app(BandwidthScheduler::class);

    expect($sched->effectiveLimitKbps(Carbon::parse('2026-05-17 10:00')))->toBe(200)
        ->and($sched->effectiveLimitKbps(Carbon::parse('2026-05-17 22:00')))->toBeNull();
});

it('honours an overnight window', function () {
    $s = app(SettingsRepository::class);
    $s->set('bandwidth_schedule_enabled', true);
    $s->set('bandwidth_schedule_start', '22:00');
    $s->set('bandwidth_schedule_end', '06:00');
    $s->set('bandwidth_schedule_kbps', 50);

    $sched = app(BandwidthScheduler::class);

    expect($sched->effectiveLimitKbps(Carbon::parse('2026-05-17 23:30')))->toBe(50)
        ->and($sched->effectiveLimitKbps(Carbon::parse('2026-05-17 12:00')))->toBeNull();
});

it('uses per-folder advanced rclone overrides (F5.3)', function () {
    $folder = SyncFolder::factory()->create([
        'transfers' => 12, 'checkers' => 16, 'chunk_size' => '250M',
    ]);

    $args = app(SyncService::class)->bisyncArgs($folder);

    expect($args)->toContain('--transfers=12')
        ->toContain('--checkers=16')
        ->toContain('--onedrive-chunk-size=250M');
});

it('exports config without tokens and re-imports it (F5.9)', function () {
    $account = Account::factory()->create(['name' => 'Mine']);
    SyncFolder::factory()->create(['account_id' => $account->id, 'remote_path' => '/Docs']);
    app(SettingsRepository::class)->set('ui_language', 'pt-BR');

    $json = app(ConfigService::class)->toJson();

    expect($json)->not->toContain('access_token')
        ->and($json)->toContain('Mine');

    Account::query()->delete();

    $created = app(ConfigService::class)->import($json);

    expect($created)->toBe(1);
    $imported = Account::first();
    expect($imported->status)->toBe(Account::STATUS_DISCONNECTED)
        ->and($imported->syncFolders)->toHaveCount(1)
        ->and(app(SettingsRepository::class)->language())->toBe('pt-BR');
});

it('searches across remote files (F5.4)', function () {
    $this->actingAs(User::factory()->create());
    Account::factory()->create(['remote_name' => 'od1']);

    $this->mock(RcloneRunner::class)->shouldReceive('run')->andReturn(
        new RcloneResult(0, json_encode([
            ['Path' => 'Docs/budget-2026.xlsx', 'IsDir' => false, 'Size' => 10],
            ['Path' => 'Photos/cat.jpg', 'IsDir' => false, 'Size' => 20],
        ]), '')
    );

    Livewire::test(SearchPage::class)
        ->set('q', 'budget')
        ->assertSee('budget-2026.xlsx')
        ->assertDontSee('cat.jpg');
});

it('captures a daily usage snapshot (F5.5)', function () {
    Account::factory()->create(['quota_used_bytes' => 500, 'quota_total_bytes' => 1000]);

    $this->artisan(CaptureUsageCommand::class)->assertSuccessful();

    expect(UsageSnapshot::count())->toBe(1)
        ->and(UsageSnapshot::first()->cloud_used_bytes)->toBe(500);
});

it('dismisses the onboarding tour (F5.1)', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Dashboard::class)
        ->assertSet('showOnboarding', true)
        ->call('dismissOnboarding')
        ->assertSet('showOnboarding', false);

    expect(app(SettingsRepository::class)->get('onboarding_done'))->toBeTrue();
});

it('imports a config file through the settings page (F5.9)', function () {
    $this->actingAs(User::factory()->create());

    $json = json_encode([
        'version' => 1,
        'settings' => ['ui_language' => 'pt-BR'],
        'accounts' => [['name' => 'Imported', 'provider' => 'onedrive_personal', 'folders' => []]],
    ]);

    Livewire::test(SettingsPage::class)
        ->set('configFile', UploadedFile::fake()->createWithContent('c.json', $json))
        ->call('importConfig')
        ->assertRedirect(route('settings'));

    expect(Account::where('name', 'Imported')->exists())->toBeTrue();
});
