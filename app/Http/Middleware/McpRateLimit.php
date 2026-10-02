<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class McpRateLimit
{
    /**
     * Enforce the named MCP limiter while preserving a JSON-RPC error shape.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $limiter = RateLimiter::limiter('mcp');

        /** @var array<int, Limit> $limits */
        $limits = $limiter === null ? [] : $limiter($request);

        foreach ($limits as $limit) {
            if (RateLimiter::tooManyAttempts($limit->key, $limit->maxAttempts)) {
                return $this->rateLimitedResponse($request, $limit);
            }
        }

        foreach ($limits as $limit) {
            RateLimiter::hit($limit->key, $limit->decaySeconds);
        }

        $response = $next($request);

        return $this->withRateLimitHeaders($response, $limits);
    }

    /**
     * @param  array<int, Limit>  $limits
     */
    private function withRateLimitHeaders(Response $response, array $limits): Response
    {
        if ($limits === []) {
            return $response;
        }

        $limit = min(array_map(
            static fn (Limit $limit): int => $limit->maxAttempts,
            $limits,
        ));
        $remaining = min(array_map(
            static fn (Limit $limit): int => RateLimiter::remaining($limit->key, $limit->maxAttempts),
            $limits,
        ));

        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $remaining));

        return $response;
    }

    private function rateLimitedResponse(Request $request, Limit $limit): Response
    {
        $retryAfter = max(1, RateLimiter::availableIn($limit->key));

        if (! $request->is('mcp')) {
            return response()->json([
                'error' => 'MCP rate limit exceeded. Retry after the indicated delay.',
                'errorCode' => 'rate_limit',
                'retryable' => true,
                'retryAfterSeconds' => $retryAfter,
                'nextAction' => 'retry_after',
            ], 429)
                ->header('Retry-After', (string) $retryAfter)
                ->header('X-RateLimit-Limit', (string) $limit->maxAttempts)
                ->header('X-RateLimit-Remaining', '0');
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $request->input('id'),
            'error' => [
                'code' => -32029,
                'message' => 'MCP rate limit exceeded. Retry after the indicated delay.',
                'data' => [
                    'artfct' => [
                        'errorCode' => 'rate_limit',
                        'retryable' => true,
                        'retryAfterSeconds' => $retryAfter,
                        'nextAction' => 'retry_after',
                    ],
                ],
            ],
        ], 429)
            ->header('Retry-After', (string) $retryAfter)
            ->header('X-RateLimit-Limit', (string) $limit->maxAttempts)
            ->header('X-RateLimit-Remaining', '0');
    }
}
