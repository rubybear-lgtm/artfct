<?php

namespace App\Services\Readiness;

/**
 * Which external integrations are configured on this environment. Reports the
 * names of missing config keys, never their values. Shared by the
 * `app:readiness` command and the operator console's health page.
 */
final class IntegrationReadiness
{
    public const READY = 'ready';

    public const NOT_CONFIGURED = 'not configured';

    public const INCOMPLETE = 'incomplete';

    /**
     * @return list<array{name: string, state: 'ready'|'not configured'|'incomplete', missing: list<string>}>
     */
    public function check(): array
    {
        $integrations = [
            'WorkOS sign-in' => ['services.workos.client_id', 'services.workos.secret', 'services.workos.redirect_url'],
            'Stripe payments' => ['services.stripe.secret', 'services.stripe.webhook_secret', 'services.stripe.team_price_id'],
            'Invitation email' => $this->emailKeys(),
            'Worker and org-token keys' => [
                'services.org_jwt.worker_base_url',
                'services.org_jwt.jwks_write_secret',
                'services.org_jwt.revocation_write_secret',
                'services.org_jwt.private_key',
                'services.org_jwt.kid',
                'services.org_jwt.issuer',
                'services.org_jwt.audience',
            ],
        ];

        $results = [];

        foreach ($integrations as $name => $keys) {
            $missing = array_values(array_filter($keys, fn (string $key): bool => blank(config($key))));

            $results[] = [
                'name' => $name,
                'state' => match (true) {
                    $missing === [] => self::READY,
                    count($missing) === count($keys) => self::NOT_CONFIGURED,
                    default => self::INCOMPLETE,
                },
                'missing' => $missing,
            ];
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function emailKeys(): array
    {
        return config('mail.default') === 'cloudflare'
            ? ['services.cloudflare_email.account_id', 'services.cloudflare_email.api_token', 'mail.from.address']
            : (in_array(config('mail.default'), ['log', 'array'], true) ? ['mail.unconfigured'] : ['mail.from.address']);
    }
}
