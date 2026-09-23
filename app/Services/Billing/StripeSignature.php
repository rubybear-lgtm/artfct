<?php

namespace App\Services\Billing;

/**
 * Verifies Stripe's `Stripe-Signature` header: `t=<unix>,v1=<hmac>` where the
 * HMAC-SHA256 is over `<t>.<raw body>` with the endpoint's signing secret.
 * Any failure, or an unset secret, is a rejection.
 */
final class StripeSignature
{
    public const TOLERANCE_SECONDS = 300;

    public static function verify(?string $header, string $payload, ?string $secret, int $now): bool
    {
        if ($secret === null || $secret === '' || $header === null || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || abs($now - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public static function header(string $payload, string $secret, int $timestamp): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }
}
