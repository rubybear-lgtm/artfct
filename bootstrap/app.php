<?php

use App\Http\Middleware\CacheControl;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureTermsAccepted;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NegotiateMarkdown;
use App\Http\Middleware\NoIndexOutsideProduction;
use App\Http\Middleware\NoIndexPrivateSurfaces;
use App\Http\Middleware\PinRequestScheme;
use App\Http\Middleware\RequireOrgScope;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\Support\StaffAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\TokenMismatchException;

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

        // Runs first so the session, auth and terms gates see the configured
        // https scheme when they store the current URL (see PinRequestScheme).
        $middleware->prepend(PinRequestScheme::class);

        $middleware->preventRequestForgery(except: ['internal/worker-events', 'webhooks/stripe', 'webhooks/polis', 'oauth/register', 'oauth/token', 'oauth/revoke']);

        // Agents asking for text/markdown get the Markdown version of a public
        // page before the session and Inertia run (RUB-503).
        $middleware->web(prepend: [NegotiateMarkdown::class]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            CacheControl::class,
            NoIndexOutsideProduction::class,
            NoIndexPrivateSurfaces::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
            EnsureTermsAccepted::class,
            EnsureUserIsActive::class,
        ]);

        // `/ops` refusals must be the ordinary 404 and nothing else. By default
        // route model binding (a missing tenant) and the throttle (a 429 after
        // enough probes) would answer first for a visitor who is not staff, and
        // neither happens for an unknown URL. The staff guard runs before both.
        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: EnsureStaff::class,
        );

        $middleware->alias([
            'org.scope' => RequireOrgScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // OAuth clients and MCP clients do not send `Accept: application/json`
        // on every call, and show a bare `Unauthorized` when a 429 or error
        // arrives as an HTML page. `oauth/authorize` stays HTML: it is a page.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'oauth/register', 'oauth/token', 'oauth/revoke', 'mcp'),
        );

        // An expired or missing token on an `/ops` URL would answer 419 and
        // tell a signed-out visitor the route exists. Mapped before the
        // handler turns it into a 419, so it renders as the ordinary 404 and
        // every other route keeps its 419.
        $exceptions->map(
            fn (TokenMismatchException $exception) => request()->is('ops', 'ops/*')
                ? StaffAccess::notFound(request(), $exception)
                : $exception,
        );
    })->create();
