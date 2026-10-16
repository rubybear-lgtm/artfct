<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Services\Operator\PlatformHealth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform health for artfct staff (spec 17): queue, indexing and webhook
 * counters and the integration readiness. Platform-wide, never per tenant.
 */
class HealthController extends Controller
{
    public function __invoke(PlatformHealth $health): Response
    {
        return Inertia::render('ops/health', $health->snapshot());
    }
}
