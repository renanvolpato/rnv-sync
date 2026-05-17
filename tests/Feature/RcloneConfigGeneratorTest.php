<?php

use App\Models\Account;
use App\Services\Rclone\RcloneConfigGenerator;

it('builds an rclone remote block per active account', function () {
    Account::factory()->create([
        'remote_name' => 'onedrive_jane',
        'oauth_token' => json_encode(['access_token' => 'abc']),
        'status' => Account::STATUS_ACTIVE,
    ]);
    Account::factory()->disconnected()->create(['remote_name' => 'onedrive_off']);

    $ini = app(RcloneConfigGenerator::class)->build();

    expect($ini)->toContain('[onedrive_jane]')
        ->toContain('type = onedrive')
        ->toContain('drive_type = personal')
        ->not->toContain('[onedrive_off]'); // inactive excluded
});
