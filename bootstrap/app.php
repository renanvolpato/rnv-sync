<?php

use App\Http\Middleware\EnsureSetupComplete;
use App\Http\Middleware\SetLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
        //
    })->create();
