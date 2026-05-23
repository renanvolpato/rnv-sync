<?php

use App\Jobs\MirrorRemoteFoldersJob;
use App\Models\Account;
use App\Models\User;
use App\Services\Graph\RcloneAuthorize;
use App\Services\Rclone\RcloneConfigGenerator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;

it('parses the rclone authorize URL from its output', function () {
    $out = <<<'TXT'
    2026/05/17 NOTICE: Config file not found
    2026/05/17 NOTICE: If your browser doesn't open automatically go to the following link: http://127.0.0.1:53682/auth?state=AbC123xyz
    2026/05/17 NOTICE: Log in and authorize rclone for access
    2026/05/17 NOTICE: Waiting for code...
    TXT;

    expect(app(RcloneAuthorize::class)->parseAuthUrl($out))
        ->toBe('http://127.0.0.1:53682/auth?state=AbC123xyz');
});

it('parses the token JSON rclone prints between markers', function () {
    $out = <<<'TXT'
    Got code
    Paste the following into your remote machine --->
    {"access_token":"AAA","token_type":"Bearer","refresh_token":"RRR","expiry":"2026-05-17T12:00:00Z"}
    <---End paste
    TXT;

    $token = app(RcloneAuthorize::class)->parseToken($out);

    expect($token['access_token'])->toBe('AAA')
        ->and($token['refresh_token'])->toBe('RRR');
});

it('returns null while no token is present yet', function () {
    expect(app(RcloneAuthorize::class)->parseToken('Waiting for code...'))->toBeNull();
});

it('omits client_id in the rclone config for bundled-client accounts', function () {
    Account::factory()->create([
        'remote_name' => 'easy1',
        'uses_bundled_client' => true,
        'oauth_token' => json_encode(['access_token' => 'x']),
    ]);

    $ini = app(RcloneConfigGenerator::class)->build();

    expect($ini)->toContain('[easy1]')
        ->toContain('type = onedrive')
        ->not->toContain('client_id =');
});

it('still pins client_id for advanced (own-app) accounts', function () {
    Account::factory()->create([
        'remote_name' => 'adv1',
        'uses_bundled_client' => false,
        'oauth_token' => json_encode(['access_token' => 'x']),
    ]);

    expect(app(RcloneConfigGenerator::class)->build())->toContain('client_id =');
});

it('completes easy onboarding from a token and flags bundled client', function () {
    $this->actingAs(User::factory()->create());
    Queue::fake();

    Http::fake([
        'graph.microsoft.com/v1.0/me' => Http::response(['displayName' => 'Me', 'mail' => 'me@live.com']),
        'graph.microsoft.com/v1.0/me/drive' => Http::response([
            'id' => 'D1', 'driveType' => 'personal', 'quota' => ['total' => 100, 'used' => 5],
        ]),
    ]);

    $this->mock(RcloneAuthorize::class, function (Mockery\MockInterface $m) {
        $m->shouldReceive('status')->andReturn([
            'state' => 'ready',
            'token' => ['access_token' => 'AAA', 'refresh_token' => 'RRR', 'expiry' => now()->toRfc3339String()],
        ]);
    });

    session(['easy_oauth_session' => 'sess123']);

    $this->get(route('oauth.easy.status'))
        ->assertOk()
        ->assertJson(['state' => 'ready']);

    $account = Account::first();
    expect($account)->not->toBeNull()
        ->and($account->uses_bundled_client)->toBeTrue()
        ->and($account->email)->toBe('me@live.com');

    // Online by default: connecting mirrors the whole drive automatically.
    Queue::assertPushed(MirrorRemoteFoldersJob::class, fn ($job) => $job->accountId === $account->id);
});

it('reports pending while rclone is still waiting', function () {
    $this->actingAs(User::factory()->create());

    $this->mock(RcloneAuthorize::class, function (Mockery\MockInterface $m) {
        $m->shouldReceive('status')->andReturn(['state' => 'pending']);
    });

    session(['easy_oauth_session' => 'sess123']);

    $this->get(route('oauth.easy.status'))
        ->assertOk()
        ->assertJson(['state' => 'pending']);

    expect(Account::count())->toBe(0);
});
