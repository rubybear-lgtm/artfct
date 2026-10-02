<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use WorkOS\UserManagement;

class LogoutController extends Controller
{
    /**
     * End the local session and, when the user signed in through WorkOS, the
     * WorkOS session as well, so the back button and a fresh /login do not
     * silently sign them in again.
     */
    public function __invoke(Request $request): Response
    {
        $workosSessionId = $request->session()->get('workos_session_id');

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (is_string($workosSessionId) && $workosSessionId !== '') {
            return Inertia::location((new UserManagement)->getLogoutUrl($workosSessionId, route('home')));
        }

        return redirect()->route('home');
    }
}
