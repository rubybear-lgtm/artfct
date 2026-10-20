<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthKit\AuthKitProfile;
use App\Services\AuthKit\FakeAuthKitClient;
use App\Support\StaffAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Dev/test-only stand-in for WorkOS's hosted login screen. Registered in every
 * environment (the login page imports its generated action) but answers 404 in
 * production whatever the flag says (see routes/auth.php) — this is what
 * `registration_and_org_creation_flow` and the feature tests click/post
 * through instead of a real WorkOS redirect.
 */
class AuthKitDevLoginController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_if(app()->environment('production'), 404);
        abort_unless(config('services.authkit.dev_login_enabled'), 404);

        // A mis-set APP_ENV on a deployed host must not turn this into a login for
        // any email. Off a loopback or .test host the stand-in answers only when a
        // domain allowlist is configured (staging), and only with the flag explicitly on
        // (RUB-460). The allowlist check below then limits which emails may sign in.
        $allowedDomains = array_filter((array) config('services.authkit.dev_login_domains'));
        abort_unless(StaffAccess::isDevelopmentHost($request) || $allowedDomains !== [], 404);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'name' => ['nullable', 'string'],
            'provider' => ['required', 'string', Rule::in(['GoogleOAuth', 'Passkey', 'MicrosoftOAuth', 'AppleOAuth', 'MagicAuth'])],
        ]);

        $email = strtolower($validated['email']);

        // On a reachable non-production host (staging) only allow listed email
        // domains, so the passwordless stand-in cannot be used to sign in as
        // or create arbitrary accounts.
        if ($allowedDomains !== [] && ! in_array(Str::after($email, '@'), $allowedDomains, true)) {
            abort(403, 'This email domain is not allowed for dev login.');
        }
        $name = $validated['name'] ?? explode('@', $email)[0];

        $profile = new AuthKitProfile(
            externalId: hash('sha256', $validated['provider'].'|'.$email),
            provider: $validated['provider'],
            email: $email,
            emailVerified: true,
            firstName: $name,
            lastName: null,
            avatar: null,
        );

        return redirect()->route('authenticate', ['code' => FakeAuthKitClient::codeFor($profile)]);
    }
}
