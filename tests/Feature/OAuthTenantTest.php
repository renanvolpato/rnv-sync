<?php

use App\Services\Graph\OneDriveOAuth;

it('uses the common tenant endpoint by default', function () {
    $url = app(OneDriveOAuth::class)->authorizeUrl('state123');

    expect($url)->toStartWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize');
});

it('targets the consumers endpoint for personal accounts when configured', function () {
    config(['rnvsync.oauth.tenant' => 'consumers']);

    $url = app(OneDriveOAuth::class)->authorizeUrl('state123');

    expect($url)->toStartWith('https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize');
});

it('falls back to common when the tenant is blank', function () {
    config(['rnvsync.oauth.tenant' => '']);

    expect(app(OneDriveOAuth::class)->authorizeUrl('s'))
        ->toContain('/common/oauth2/v2.0/authorize');
});
