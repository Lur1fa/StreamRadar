<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // An https:// APP_URL is authoritative for every URL the app builds.
        $middleware->prepend(\App\Http\Middleware\UseConfiguredOrigin::class);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        // Spaces and line breaks around the text of a notification template
        // are part of the message.
        $middleware->trimStrings(except: ['notification_template.*']);

        // Invalidate every other session of a user when their password changes.
        $middleware->authenticateSessions();

        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));
        $middleware->redirectUsersTo('/');

        // Only trust X-Forwarded-* headers from explicitly configured proxies.
        // Needed for the real client IP (login throttling, logs) behind a proxy.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Inertia form submissions that hit an authorization failure get a
        // readable flash message instead of a bare error page.
        $exceptions->respond(function ($response, \Throwable $e, Request $request) {
            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 403 && $request->header('X-Inertia') && ! $request->isMethod('GET')) {
                return back()->with('error', 'You do not have permission to perform this action.');
            }

            return $response;
        });
    })->create();
