<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LegalController extends Controller
{
    public function terms(): Response
    {
        return Inertia::render('legal/terms', ['version' => config('legal.terms_version')]);
    }

    public function privacy(): Response
    {
        return Inertia::render('legal/privacy', ['version' => config('legal.terms_version')]);
    }

    public function showAcceptance(Request $request): Response|RedirectResponse
    {
        if ($request->user()->terms_version === config('legal.terms_version')) {
            return redirect()->intended(route('teams.index'));
        }

        return Inertia::render('legal/accept', ['version' => config('legal.terms_version')]);
    }

    public function accept(Request $request): RedirectResponse
    {
        $request->validate(['accepted' => ['accepted']]);

        $request->user()->forceFill([
            'terms_version' => config('legal.terms_version'),
            'terms_accepted_at' => now(),
        ])->save();

        return redirect()->intended(route('teams.index'));
    }
}
