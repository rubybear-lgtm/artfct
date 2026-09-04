<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthKit\AuthKitClientContract;
use App\Services\Identity\IdentityResolver;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

class AuthKitCallbackController extends Controller
{
    /**
     * Exchange the authorization code for a profile, resolve it to a
     * `users` row via `external_identities` (never `workos_id`), and log
     * the user in.
     */
    public function __invoke(
        Request $request,
        AuthKitClientContract $client,
        IdentityResolver $resolver,
    ): RedirectResponse {
        $code = $request->query('code');

        abort_if(! is_string($code) || $code === '', 400);

        $profile = $client->authenticateWithCode($code);

        [$user, $wasCreated] = $resolver->resolve($profile);

        if ($wasCreated) {
            event(new Registered($user));
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        /** @var User $user */
        $user = $user->fresh();
        $currentTeam = $user->currentTeam ?? $user->personalTeam();

        if ($currentTeam && ! $user->current_team_id) {
            $user->switchTeam($currentTeam);
        }

        if ($currentTeam) {
            URL::defaults(['current_team' => $currentTeam->slug]);
        }

        return redirect()->intended(
            $currentTeam ? route('dashboard', ['current_team' => $currentTeam->slug]) : route('teams.index')
        );
    }
}
