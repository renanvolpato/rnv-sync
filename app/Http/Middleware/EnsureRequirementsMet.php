<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\System\RequirementsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WordPress-style preflight gate. While a critical environment
 * requirement is unmet, every request is redirected to the requirements
 * screen. Runs as global middleware (before the web group) so it works
 * even when the SQLite driver is missing and the DB cannot be touched.
 */
class EnsureRequirementsMet
{
    /** Paths that must stay reachable while requirements are unmet. */
    private const ALLOWED = ['requirements', 'requirements/recheck', 'up'];

    public function __construct(private readonly RequirementsService $requirements) {}

    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->path(), '/');

        if (in_array($path, self::ALLOWED, true)) {
            return $next($request);
        }

        if (! $this->requirements->allCriticalMet()) {
            return redirect('/requirements');
        }

        return $next($request);
    }
}
