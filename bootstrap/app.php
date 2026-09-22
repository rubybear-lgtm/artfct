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
        // Scoped to the platform's real ingress (RUB-372). Cloudflare fronts the
        // public domain and its ranges are published, so Cloudflare is trusted;
        // the Railway edge is neither enumerable nor trustworthy for a header a
        // caller can write.
        //
        // The consequence is that a request arriving on the direct Railway
        // service domain is not trusted for X-Forwarded-Proto, so URL generation
        // is pinned to the configured scheme in AppServiceProvider rather than
        // inferred from the request. Do not key a security decision on
        // $request->ip() in this application regardless: App\Support\ClientIp
        // resolves the address from what Cloudflare wrote, or the peer.
        // Trusted proxies are configured in AppServiceProvider, not here: the list
        // comes from config/trusted_ingress.php, and this closure runs before the
        // config repository is bound (RUB-372).

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
