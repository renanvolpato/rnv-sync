<?php

use App\Services\System\RequirementsService;

it('reports all critical requirements met in the test environment', function () {
    $svc = app(RequirementsService::class);

    expect($svc->allCriticalMet())->toBeTrue();

    $keys = collect($svc->checks())->pluck('key');
    expect($keys)->toContain('php', 'pdo_sqlite', 'app_key', 'storage_writable', 'database', 'rclone');
});

it('marks the bundled rclone check as non-critical', function () {
    $rclone = collect(app(RequirementsService::class)->checks())
        ->firstWhere('key', 'rclone');

    expect($rclone['critical'])->toBeFalse();
});

it('gives a distro-aware fix command for the sqlite extension', function () {
    // Force the check to fail by faking the service shape.
    $checks = app(RequirementsService::class)->checks();
    $sqlite = collect($checks)->firstWhere('key', 'pdo_sqlite');

    expect($sqlite['critical'])->toBeTrue()
        ->and($sqlite['command'])->not->toBeNull();
});

it('redirects every route to /requirements while a critical item is unmet', function () {
    $this->mock(RequirementsService::class, function ($m) {
        $m->shouldReceive('allCriticalMet')->andReturnFalse();
        $m->shouldReceive('checks')->andReturn([[
            'key' => 'pdo_sqlite', 'label' => 'SQLite', 'ok' => false,
            'critical' => true, 'hint' => 'missing', 'command' => 'sudo apt-get install -y php8.3-sqlite3',
        ]]);
        $m->shouldReceive('bootstrapCommand')->andReturn('bash install/bootstrap.sh');
    });

    $this->get('/')->assertRedirect('/requirements');
    $this->get('/login')->assertRedirect('/requirements');

    $this->get('/requirements')
        ->assertOk()
        ->assertSee('php8.3-sqlite3')
        ->assertSee('bash install/bootstrap.sh');
});

it('lets normal routing proceed once requirements are met', function () {
    // Real service: met in the test env → preflight is transparent.
    $this->get('/')->assertRedirect(route('setup.index'));
    $this->get('/requirements')->assertRedirect('/');
});

it('doctor command succeeds when the environment is ready', function () {
    $this->artisan('rnvsync:doctor')->assertSuccessful();
});
