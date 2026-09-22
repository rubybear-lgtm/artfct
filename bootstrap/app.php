<?php

use App\Http\Middleware\CacheControl;
use App\Http\Middleware\EnsureTermsAccepted;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NoIndexOutsideProduction;
use App\Http\Middleware\SetTeamUrlDefaults;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Deliberately broad, and it stays that way (RUB-372). X-Forwarded-Proto
        // is what keeps generated URLs https behind the platform's edge, and
        // narrowing this to a range would break the OAuth redirect URIs. The
        // cost is that $request->ip() returns the leftmost X-Forwarded-For
        // entry -- the value the caller wrote -- so throttling and the audit
        // log key on App\Support\ClientIp instead, which reads only an address
        // the platform or Cloudflare recorded. Do not key a security decision
        // on $request->ip() in this application.
        $middleware->trustProxies(at: '*');

        $middleware->preventRequestForgery(except: ['internal/worker-events', 'webhooks/stripe', 'webhooks/polis', 'oauth/register', 'oauth/token', 'oauth/revoke']);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            CacheControl::class,
            NoIndexOutsideProduction::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
            EnsureTermsAccepted::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
