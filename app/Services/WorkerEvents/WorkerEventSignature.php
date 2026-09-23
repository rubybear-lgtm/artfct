<?php

namespace App\Services\WorkerEvents;

/**
 * `X-Artfct-Signature` = hex HMAC-SHA256(secret, timestamp.rawBody), with
 * `X-Artfct-Timestamp` within 300 s of now. An unset secret fails closed.
 */
final class WorkerEventSignature
{
    public const TOLERANCE_SECONDS = 300;

    public function __construct(private readonly ?string $secret) {}

    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public function verify(?string $timestamp, ?string $signature, string $body, int $now): bool
    {
        if ($this->secret === null || $this->secret === '' || $timestamp === null || $signature === null) {
            return false;
        }

        if (! ctype_digit($timestamp) || abs($now - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(self::sign($this->secret, (int) $timestamp, $body), $signature);
    }
}
