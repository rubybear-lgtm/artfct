<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Services\FeatureFlags;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureIsEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $team = $request->route('team') ?? $request->route('current_team');
        abort_unless($team instanceof Team, 404);
        app(FeatureFlags::class)->ensureEnabled($feature, $team);

        return $next($request);
    }
}
