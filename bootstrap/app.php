<?php

use App\Exceptions\OAuthException;
use App\Exceptions\RcloneException;
use App\Http\Middleware\EnsureSetupComplete;
use App\Http\Middleware\SetLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
            EnsureSetupComplete::class,
        ]);

        // The setup-wizard redirect must take precedence over auth so that
        // "no panel password set ⇒ redirect to wizard" holds for every
        // route (SPEC F1.2 EARS), including auth-guarded ones.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, SetLocale::class);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureSetupComplete::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // SPEC F5.8: surface actionable, localized messages instead of
        // raw stack traces for our domain exceptions.
        $exceptions->render(function (OAuthException $e, Request $request) {
            return back()->withErrors(['oauth' => __($e->userMessageKey)]);
        });

        $exceptions->render(function (RcloneException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['errors' => [__('errors.rclone_unavailable_body')]], 503);
            }

            return back()->with('status', __('errors.rclone_unavailable_body'));
        });
    })->create();
