<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Services\Identity\ScimProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * `POST /webhooks/polis`: directory-sync (SCIM) events from Polis. The body is
 * signed as `HMAC-SHA256(secret, "{timestamp_ms}.{raw body}")` in the
 * `BoxyHQ-Signature: t=..,s=..` header, and may be one event or a batch. A
 * created or re-activated user is provisioned into the org named by the
 * event's tenant; a deactivated or deleted one is deprovisioned (row kept,
 * tokens revoked). Both are idempotent, since Polis retries.
 */
class PolisWebhookController extends Controller
{
    private const TOLERANCE_SECONDS = 300;

    public function __invoke(Request $request, ScimProvisioningService $scim): JsonResponse
    {
        $payload = $request->getContent();

        if (! $this->signatureIsValid($request->header('BoxyHQ-Signature'), $payload)) {
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        $decoded = json_decode($payload, true);
        $events = is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];

        foreach ($events as $event) {
            if (is_array($event)) {
                $this->handle($event, $scim);
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handle(array $event, ScimProvisioningService $scim): void
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $scimId = $data['id'] ?? null;
        $type = $event['event'] ?? null;

        if (! is_string($scimId) || $scimId === '') {
            return;
        }

        $active = (bool) ($data['active'] ?? true);

        if ($type === 'user.deleted' || (in_array($type, ['user.created', 'user.updated'], true) && ! $active)) {
            $scim->deprovision($scimId);

            return;
        }

        if (in_array($type, ['user.created', 'user.updated'], true)) {
            $team = Team::query()->where('slug', (string) ($event['tenant'] ?? ''))->first();
            $email = $data['email'] ?? null;

            if ($team === null || ! is_string($email) || $email === '') {
                Log::warning('Polis directory event for an unknown org or without an email.', ['tenant' => $event['tenant'] ?? null]);

                return;
            }

            $name = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));
            $scim->provision($team, $scimId, strtolower($email), $name !== '' ? $name : null);
        }
    }

    private function signatureIsValid(?string $header, string $payload): bool
    {
        $secret = config('services.polis.webhook_secret');

        if (! $secret || ! $header) {
            return false;
        }

        parse_str(str_replace(',', '&', $header), $parts);
        $timestamp = $parts['t'] ?? null;
        $signature = $parts['s'] ?? null;

        if (! is_string($timestamp) || ! ctype_digit($timestamp) || ! is_string($signature)) {
            return false;
        }

        if (abs(now()->getTimestamp() - intdiv((int) $timestamp, 1000)) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$payload, $secret), $signature);
    }
}
