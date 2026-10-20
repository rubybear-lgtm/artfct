<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Teams\InvitationLandingController;
use App\Models\User;
use App\Services\AuthKit\AuthKitClientContract;
use App\Services\AuthKit\FakeAuthKitClient;
use App\Services\Identity\IdentityResolver;
use App\Support\TeamSignIn;
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

        // The hosted WorkOS flow round-trips a `state` we stored in the session
        // before redirecting; without checking it, an attacker could log a
        // victim in as the attacker's account (login CSRF). The dev stand-in
        // has no redirect leg, and its codes are already signed.
        if (! $client instanceof FakeAuthKitClient) {
            $expected = $request->session()->pull('authkit_state');
            $received = $request->query('state');

            abort_unless(
                is_string($expected) && $expected !== '' && is_string($received) && hash_equals($expected, $received),
                403,
                'Invalid sign-in state. Start again from the sign-in page.',
            );
        }

        try {
            $profile = $client->authenticateWithCode($code);
        } catch (\RuntimeException) {
            abort(403, 'Invalid or expired sign-in code.');
        }

        [$user, $wasCreated] = $resolver->resolve($profile);

        abort_if($user->deactivated_at !== null, 403, 'This account has been deactivated.');

        if ($wasCreated) {
            event(new Registered($user));
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        if ($profile->sessionId !== null) {
            $request->session()->put('workos_session_id', $profile->sessionId);
        }

        // A staff session is one that came through the staff WorkOS
        // organization on the real client (spec 17). The flag is cleared first
        // so no other sign-in can inherit one, then set after the session
        // regeneration above so it belongs to this session id.
        $request->session()->forget('staff_session');

        // An AuthKit sign-in never proves a Polis sign-in, so it cannot admit a `polis` team.
        TeamSignIn::forgetPolisSignIns($request);

        if (! $client instanceof FakeAuthKitClient && $this->isStaffOrganization($profile->organizationId)) {
            $request->session()->put('staff_session', ['user_id' => $user->id, 'at' => now()->timestamp]);
        }

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
            InvitationLandingController::pendingJoinUrl($request)
                ?? ($currentTeam ? route('dashboard', ['current_team' => $currentTeam->slug]) : route('teams.index'))
        );
    }

    /**
     * Whether the organization the user signed in through is the configured
     * staff organization. An unset or empty configured id never matches.
     */
    private function isStaffOrganization(?string $organizationId): bool
    {
        $staffOrganizationId = config('services.workos.staff_organization_id');

        if (! is_string($staffOrganizationId) || $staffOrganizationId === '') {
            return false;
        }

        return is_string($organizationId) && $organizationId !== '' && hash_equals($staffOrganizationId, $organizationId);
    }
}
