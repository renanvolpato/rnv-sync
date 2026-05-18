<?php

use App\Http\Middleware\EnsureSetupComplete;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\Request;

/**
 * Regression: while setup is incomplete, EnsureSetupComplete must NOT
 * redirect Livewire's AJAX endpoint — otherwise the setup wizard's
 * "Next" silently does nothing (the client swallows the redirect).
 */
it('does not redirect Livewire AJAX requests during setup', function () {
    expect(app(SettingsRepository::class)->setupComplete())->toBeFalse();

    $mw = app(EnsureSetupComplete::class);

    $request = Request::create('/livewire/update', 'POST');
    $request->headers->set('X-Livewire', '1');

    $passed = false;
    $response = $mw->handle($request, function () use (&$passed) {
        $passed = true;

        return response('ok');
    });

    expect($passed)->toBeTrue()
        ->and($response->getStatusCode())->toBe(200);
});

it('still redirects normal full-page routes to the wizard during setup', function () {
    $mw = app(EnsureSetupComplete::class);

    $request = Request::create('/settings', 'GET');

    $response = $mw->handle($request, fn () => response('should not reach'));

    expect($response->isRedirect(route('setup.index')))->toBeTrue();
});
