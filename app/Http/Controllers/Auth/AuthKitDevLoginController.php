<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthKit\AuthKitProfile;
use App\Services\AuthKit\FakeAuthKitClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Dev/test-only stand-in for WorkOS's hosted login screen. Never registered
 * in production (see routes/auth.php) — this is what
 * `registration_and_org_creation_flow` and the feature tests click/post
 * through instead of a real WorkOS redirect.
 */
class AuthKitDevLoginController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'name' => ['nullable', 'string'],
            'provider' => ['required', 'string', Rule::in(['GoogleOAuth', 'Passkey', 'MicrosoftOAuth', 'AppleOAuth', 'MagicAuth'])],
        ]);

        $email = strtolower($validated['email']);
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
