<?php

namespace App\Http\Middleware;

use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Services\Billing\QuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'teams' => fn () => $request->user()?->toUserTeams(includeCurrent: true) ?? [],
            // A cached read of the current team's quota so every page can show a
            // warning or over-quota banner; null when the Worker is unreachable.
            'quota' => function () use ($request): ?array {
                $team = $request->user()?->currentTeam;

                if ($team === null) {
                    return null;
                }

                try {
                    return Cache::remember("quota-banner:{$team->id}", 60, function () use ($team): array {
                        $status = app(QuotaService::class)->status($team);

                        return [
                            'warning' => $status->anyWarning(),
                            'exceeded' => $status->anyExceeded(),
                        ];
                    });
                } catch (\Throwable) {
                    return null;
                }
            },
            'currentTeam' => function () use ($request) {
                $team = $request->user()?->currentTeam;

                return $team === null ? null : [
                    'slug' => $team->slug,
                    'name' => $team->name,
                    'plan' => ($team->plan ?? Plan::Free)->value,
                    'paymentStatus' => ($team->payment_status ?? PaymentStatus::Active)->value,
                    'isOwner' => $team->owner_user_id !== null && $team->owner_user_id === $request->user()->id,
                ];
            },
        ];
    }
}
