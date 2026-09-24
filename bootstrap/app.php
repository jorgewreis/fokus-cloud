<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\SearchVisibility::class);
        $middleware->append(\App\Http\Middleware\SecurityResponseHeaders::class);
        $middleware->web(append: [
            \App\Http\Middleware\ExpireIdleDatabaseSession::class,
            \App\Http\Middleware\EnsureSupportSession::class,
        ]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/?acesso=cliente');
        // The API shares Laravel's encrypted, HttpOnly session cookie. Webhooks
        // are authenticated by their provider signature, not by a browser CSRF token.
        $middleware->validateCsrfTokens(except: ['api/webhooks/mercado-pago', 'api/integrations/usage']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (AuthenticationException $exception, Request $request) =>
            $request->is('api/*') ? response()->json(['message' => 'Unauthenticated.'], 401) : null
        );
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
