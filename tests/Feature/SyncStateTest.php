<?php

use App\Services\Files\PendingOps;
use App\Services\Rclone\RcloneRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

it('returns the tray state contract as localhost JSON, no auth/CSRF', function () {
    $r = $this->get('/sync-state');

    $r->assertOk()->assertHeader('content-type', 'application/json');
    $json = $r->json();

    expect($json)->toHaveKeys(['syncing', 'pending'])
        ->and($json['syncing'])->toBeBool()
        ->and($json['pending'])->toBeInt();
});

it('flips to syncing while an item is pending', function () {
    $probe = '/tmp/__state_test_'.uniqid();
    PendingOps::mark($probe);

    $this->get('/sync-state')
        ->assertOk()
        ->assertJson(['syncing' => true]);

    expect($this->get('/sync-state')->json('pending'))->toBeGreaterThan(0);

    PendingOps::clear($probe);
});

it('lists in-flight items (files) for the tray menu', function () {
    $probe = '/tmp/__tray_item_'.uniqid().'/Relatorio.docx';
    PendingOps::mark($probe);

    $json = $this->get('/sync-state')->assertOk()->json();

    expect($json['items'])->toBeArray()
        ->and(collect($json['items'])->pluck('name'))->toContain('Relatorio.docx')
        ->and($json['count'])->toBeGreaterThan(0);

    PendingOps::clear($probe);
});

it('surfaces rclone live per-file transfers with percent and direction', function () {
    // Pretend a transfer is running and advertising its rc port.
    $state = RcloneRunner::rcStateFile();
    File::ensureDirectoryExists(dirname($state));
    File::put($state, (string) json_encode(['port' => 5599, 'verb' => 'copy', 'started_at' => time()]));

    Http::fake(['127.0.0.1:5599/*' => Http::response([
        'transferring' => [
            ['name' => 'Fotos/foto.jpg', 'percentage' => 73,
                'dstFs' => 'onedrive_onedrive:Fotos', 'srcFs' => '/home/x/Fotos'],
            ['name' => 'video.mp4', 'percentage' => 12,
                'dstFs' => '/home/x/Downloads', 'srcFs' => 'onedrive_onedrive:Downloads'],
        ],
        'transfers' => 3,
        'totalTransfers' => 9,
        'speed' => 2_500_000,
    ])]);

    $json = $this->get('/sync-state')->assertOk()->json();

    expect($json['transfer'])->toMatchArray(['done' => 3, 'total' => 9])
        ->and($json['items'][0])->toMatchArray(['name' => 'foto.jpg', 'pct' => 73, 'dir' => 'up'])
        ->and($json['items'][1])->toMatchArray(['name' => 'video.mp4', 'pct' => 12, 'dir' => 'down']);

    File::delete($state);
});

it('reports no live transfer when nothing is running (transfer = null)', function () {
    File::delete(RcloneRunner::rcStateFile());
    Http::fake(); // any accidental HTTP call would fail the expectation below

    $json = $this->get('/sync-state')->assertOk()->json();

    expect($json['transfer'])->toBeNull();
    Http::assertNothingSent();
});
