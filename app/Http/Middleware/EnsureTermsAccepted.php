<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a signed-in user to the acceptance page until they have accepted the
 * current terms version. Off unless `legal.consent_required` is on. Public
 * pages, the legal pages themselves, sign-out and machine endpoints pass.
 */
class EnsureTermsAccepted
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! config('legal.consent_required') || $user === null) {
            return $next($request);
        }

        if ($user->terms_version === config('legal.terms_version') || $this->isExempt($request)) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('terms.accept.show');
    }

    private function isExempt(Request $request): bool
    {
        return $request->routeIs('terms', 'terms.*', 'privacy', 'logout', 'home', 'docs', 'blog', 'blog.show', 'sitemap', 'jwks')
            || $request->is('internal/*', 'webhooks/*', 'api/*', 'up');
    }
}
