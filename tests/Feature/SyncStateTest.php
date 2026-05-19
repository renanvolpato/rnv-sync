<?php

use App\Services\Files\PendingOps;

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
