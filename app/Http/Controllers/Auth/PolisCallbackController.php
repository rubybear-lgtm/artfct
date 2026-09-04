<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Identity\EnterpriseIdentityResolver;
use App\Services\Polis\PolisClientContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Spec 10: exchanges a Polis (SAML/OIDC) authorization code for a
 * profile, resolves it against ONE org's members via
 * {@see EnterpriseIdentityResolver} (never creating a new user — see that
 * class for why), and logs the resolved user in. An unlinked login
 * redirects to the org's admin-linking screen instead of failing or
 * silently creating an account.
 */
class PolisCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        string $team,
        PolisClientContract $client,
        EnterpriseIdentityResolver $resolver,
    ): RedirectResponse {
        $code = $request->query('code');
        abort_if(! is_string($code) || $code === '', 400);

        $organization = Team::query()->where('slug', $team)->firstOrFail();
        $profile = $client->authenticateWithCode($code, $organization->slug, 'artfct');

        $result = $resolver->resolveOrgLogin($organization, $profile);

        if ($result->status === 'unlinked') {
            return redirect()
                ->route('teams.edit', ['team' => $organization->slug])
                ->with('toast', ['type' => 'error', 'message' => __('No account matched this SSO identity. An admin must link it manually.')]);
        }

        $user = $result->user;
        abort_if($user->deactivated_at !== null, 403, 'This account has been deactivated.');

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        if (! $user->current_team_id) {
            $user->switchTeam($organization);
        }

        return redirect()->intended(route('dashboard', ['current_team' => $organization->slug]));
    }
}
