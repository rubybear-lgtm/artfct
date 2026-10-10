<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Polis\PolisClientContract;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts an enterprise SSO sign-in for one org: remembers a one-time `state`
 * in the session (checked by the callback against login CSRF) and sends the
 * browser to Polis, which forwards it to the org's identity provider.
 */
class PolisLoginController extends Controller
{
    public function __invoke(Request $request, string $team, PolisClientContract $client): Response
    {
        $organization = Team::query()->where('slug', $team)->firstOrFail();

        $state = Str::random(32);
        $request->session()->put('polis_state', $state);

        return Inertia::location($client->authorizationUrl(
            $organization->slug,
            'artfct',
            route('sso.authenticate', ['team' => $organization->slug]),
            $state,
        ));
    }
}
