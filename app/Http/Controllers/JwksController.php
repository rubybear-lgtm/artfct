<?php

namespace App\Http\Controllers;

use App\Services\Auth\OrgJwtService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * `GET /.well-known/jwks.json`: the public half of the org-token signing
 * key (RFC 7517). 404 when no key is configured, never an error page.
 */
class JwksController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            $jwk = OrgJwtService::default()->jwk();
        } catch (RuntimeException) {
            return response()->json(['error' => 'not_configured'], 404);
        }

        return response()->json(['keys' => [$jwk]])->header('Cache-Control', 'public, max-age=300');
    }
}
