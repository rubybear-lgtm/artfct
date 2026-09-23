<?php

namespace App\Services\AuthKit;

/**
 * A normalized WorkOS AuthKit identity, decoupled from the vendor SDK's
 * response shape so both the real and fake clients can produce it.
 */
final class AuthKitProfile
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $provider,
        public readonly string $email,
        public readonly bool $emailVerified,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $avatar,
        public readonly ?string $sessionId = null,
    ) {}

    public function fullName(): string
    {
        return trim(($this->firstName ?? '').' '.($this->lastName ?? '')) ?: $this->email;
    }
}
