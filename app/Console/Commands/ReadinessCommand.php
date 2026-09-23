<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Reports which external integrations are configured on this environment
 * (never their values) and exits non-zero when one is half-configured or,
 * with --strict, missing.
 */
#[Signature('app:readiness {--strict : Fail when an integration is not configured at all}')]
#[Description('Report which sign-in, payment and email integrations are configured')]
class ReadinessCommand extends Command
{
    public function handle(): int
    {
        $integrations = [
            'WorkOS sign-in' => ['services.workos.client_id', 'services.workos.secret', 'services.workos.redirect_url'],
            'Stripe payments' => ['services.stripe.secret', 'services.stripe.webhook_secret', 'services.stripe.team_price_id'],
            'Invitation email' => $this->emailKeys(),
        ];

        $rows = [];
        $failed = false;

        foreach ($integrations as $name => $keys) {
            $missing = array_values(array_filter($keys, fn (string $key): bool => blank(config($key))));
            $state = match (true) {
                $missing === [] => 'ready',
                count($missing) === count($keys) => 'not configured',
                default => 'incomplete',
            };

            $failed = $failed || $state === 'incomplete' || ($state === 'not configured' && $this->option('strict'));
            $rows[] = [$name, $state, implode(', ', $missing) ?: '-'];
        }

        $this->table(['Integration', 'State', 'Missing config'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
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
