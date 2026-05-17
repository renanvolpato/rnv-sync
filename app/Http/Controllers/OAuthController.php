<?php

namespace App\Http\Controllers;

use App\Exceptions\OAuthException;
use App\Services\Accounts\AccountsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Microsoft OAuth round-trip for adding a OneDrive account (SPEC F1.4).
 *
 * EARS:
 *  - WHEN the user clicks "Add OneDrive Account", initiate the Microsoft
 *    OAuth flow and redirect to Microsoft's login page.
 *  - WHEN OAuth completes successfully, store the token encrypted and
 *    display the account in the dashboard.
 *  - WHEN OAuth fails, display a clear, localized error and offer retry.
 */
class OAuthController extends Controller
{
    public function __construct(private readonly AccountsService $accounts) {}

    /** Kick off the flow: store CSRF state in the session, redirect to MS. */
    public function start(): RedirectResponse
    {
        [$url, $state] = $this->accounts->initiateOAuth();

        session(['oauth_state' => $state]);

        return redirect()->away($url);
    }

    /** Handle Microsoft's redirect back to the app. */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->fail(
                $request->string('error') === 'access_denied'
                    ? __('errors.oauth_denied')
                    : __('errors.oauth_failed')
            );
        }

        if (! $request->filled('code')
            || $request->string('state')->value() !== session('oauth_state')) {
            return $this->fail(__('errors.oauth_state_mismatch'));
        }

        session()->forget('oauth_state');

        try {
            $account = $this->accounts->completeOAuth($request->string('code')->value());
        } catch (OAuthException $e) {
            Log::channel('rnvsync-app')->warning('OAuth failed', ['reason' => $e->getMessage()]);

            return $this->fail(__($e->userMessageKey));
        }

        return redirect()
            ->route('dashboard')
            ->with('status', __('accounts.added_success', ['name' => $account->name]));
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()
            ->route('accounts.new')
            ->withErrors(['oauth' => $message]);
    }
}
