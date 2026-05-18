<?php

namespace App\Http\Controllers;

use App\Exceptions\OAuthException;
use App\Models\Account;
use App\Services\Accounts\AccountsService;
use App\Services\Graph\RcloneAuthorize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

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

    /**
     * Zero-config sign-in (default): rclone's own OAuth — no Microsoft
     * app registration required. Opens a waiting page that sends the
     * user to Microsoft and polls until the token is captured.
     */
    public function easyStart(RcloneAuthorize $authorize): View|RedirectResponse
    {
        try {
            $session = $authorize->start();
        } catch (OAuthException $e) {
            Log::channel('rnvsync-app')->warning('Easy OAuth start failed', ['reason' => $e->getMessage()]);

            return redirect()->route('accounts.new')->withErrors(['oauth' => __($e->userMessageKey)]);
        }

        session(['easy_oauth_session' => $session['session']]);

        return view('oauth.waiting', [
            'authUrl' => $session['auth_url'],
            'statusUrl' => route('oauth.easy.status'),
            'cancelUrl' => route('accounts.new'),
        ]);
    }

    /** Polled by the waiting page until rclone returns the token. */
    public function easyStatus(Request $request, RcloneAuthorize $authorize): JsonResponse
    {
        $session = (string) session('easy_oauth_session');

        if ($session === '') {
            return response()->json(['state' => 'error', 'message' => __('errors.oauth_state_mismatch')]);
        }

        $status = $authorize->status($session);

        if ($status['state'] === 'ready') {
            $account = $this->accounts->completeFromToken($status['token']);
            session()->forget('easy_oauth_session');

            return response()->json([
                'state' => 'ready',
                'redirect' => route('dashboard'),
                'message' => __('accounts.added_success', ['name' => $account->name]),
            ]);
        }

        return response()->json($status);
    }

    /** Kick off the flow: store CSRF state in the session, redirect to MS. */
    public function start(Request $request): RedirectResponse
    {
        $provider = $request->string('provider')->value() ?: Account::PROVIDER_PERSONAL;

        [$url, $state] = $this->accounts->initiateOAuth();

        session(['oauth_state' => $state, 'oauth_provider' => $provider]);

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
            $account = $this->accounts->completeOAuth(
                $request->string('code')->value(),
                session('oauth_provider', Account::PROVIDER_PERSONAL),
            );
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
