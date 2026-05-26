<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Settings\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SPEC F1.2 / EARS: "WHEN no panel password is set, THE SYSTEM SHALL
 * redirect all routes to the setup wizard."
 *
 * Conversely, once setup is complete the wizard is no longer reachable.
 */
class EnsureSetupComplete
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The requirements preflight owns the request until the
        // environment is ready; never bounce away from it. The tray
        // status poll must also answer regardless of setup state
        // (it's localhost-only and exposes no secrets).
        if ($request->routeIs('requirements*')
            || $request->routeIs('sync-state')
            || $request->routeIs('sync-pause')) {
            return $next($request);
        }

        // Never redirect Livewire's own AJAX endpoint: it powers the
        // setup wizard itself, and a redirect here would be swallowed by
        // the Livewire client (the wizard would silently never advance).
        // Component-level access control still applies.
        if ($request->routeIs('livewire.*') || $request->hasHeader('X-Livewire')) {
            return $next($request);
        }

        $complete = $this->settings->setupComplete();
        $onWizard = $request->routeIs('setup.*');

        if (! $complete && ! $onWizard) {
            return redirect()->route('setup.index');
        }

        if ($complete && $onWizard) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
