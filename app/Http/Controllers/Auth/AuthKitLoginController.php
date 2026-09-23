<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthKit\AuthKitClientContract;
use App\Services\AuthKit\FakeAuthKitClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use WorkOS\UserManagement;
use WorkOS\WorkOS as WorkOSSdk;

class AuthKitLoginController extends Controller
{
    /**
     * Show the login screen. When AuthKit is configured with real
     * credentials, this redirects straight to WorkOS's hosted UI (Google,
     * Microsoft, Apple, passkeys, Magic Auth). Otherwise it renders a
     * local dev/test login screen backed by FakeAuthKitClient.
     */
    public function __invoke(Request $request, AuthKitClientContract $client): Response|SymfonyResponse
    {
        if ($client instanceof FakeAuthKitClient) {
            return Inertia::render('auth/login');
        }

        $clientId = config('services.workos.client_id');

        if (! $clientId || ! config('services.workos.secret') || ! config('services.workos.redirect_url')) {
            throw new RuntimeException('WorkOS AuthKit is not configured.');
        }

        WorkOSSdk::setClientId($clientId);
        WorkOSSdk::setApiKey(config('services.workos.secret'));

        $state = ['state' => Str::random(20)];
        // The SDK JSON-encodes the state it sends, and WorkOS returns it verbatim,
        // so the session must hold the encoded form for the callback to match.
        $request->session()->put('authkit_state', json_encode($state));

        $url = (new UserManagement)->getAuthorizationUrl(
            config('services.workos.redirect_url'),
            $state,
            'authkit',
        );

        return Inertia::location($url);
    }
}
