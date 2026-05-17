<?php

namespace App\Http\Controllers;

use App\Services\System\RequirementsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * WordPress-style environment preflight screen.
 *
 * Deliberately stateless (no DB, no session) so it renders even when the
 * SQLite driver is missing. "Re-check" is just a GET back to this page.
 */
class RequirementsController extends Controller
{
    public function index(RequirementsService $requirements): View|RedirectResponse
    {
        if ($requirements->allCriticalMet()) {
            return redirect('/');
        }

        return view('requirements', [
            'checks' => $requirements->checks(),
            'bootstrap' => $requirements->bootstrapCommand(),
        ]);
    }
}
