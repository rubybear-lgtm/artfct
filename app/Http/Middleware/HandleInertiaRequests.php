<?php

namespace App\Http\Middleware;

use App\Enums\PaymentStatus;
use App\Enums\Plan;
use Illuminate\Http\Request;
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
