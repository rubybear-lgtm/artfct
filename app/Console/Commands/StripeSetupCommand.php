<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * One-time Stripe (test mode) provisioning: Pro/Team prices with lookup_keys
 * and the webhook endpoint. The webhook signing secret is only shown by
 * Stripe at creation, so it is written to a private file, never printed.
 */
#[Signature('billing:stripe-setup {--url= : Public base URL of this app} {--out= : File to write STRIPE_WEBHOOK_SECRET to}')]
#[Description('Create Stripe Pro/Team prices (lookup_keys) and the webhook endpoint for this environment')]
class StripeSetupCommand extends Command
{
    private const BASE = 'https://api.stripe.com/v1';

    public function handle(): int
    {
        $secret = config('services.stripe.secret');
        $baseUrl = rtrim((string) $this->option('url'), '/');
        $out = $this->option('out');

        if (! $secret || $baseUrl === '' || ! $out) {
            $this->error('STRIPE_SECRET_KEY, --url and --out are required.');

            return self::FAILURE;
        }

        if (str_starts_with($secret, 'sk_live_')) {
            $this->error('Refusing to run against a live Stripe key. Use a test-mode key.');

            return self::FAILURE;
        }

        $http = Http::withToken($secret)->acceptJson()->asForm()->timeout(30);

        $pro = $http->post(self::BASE.'/products', ['name' => 'artfct Pro'])->throw()->json('id');
        $team = $http->post(self::BASE.'/products', ['name' => 'artfct Team'])->throw()->json('id');

        foreach ([
            ['product' => $pro, 'amount' => 500, 'interval' => 'month', 'lookup' => 'artfct_pro_monthly'],
            ['product' => $pro, 'amount' => 5000, 'interval' => 'year', 'lookup' => 'artfct_pro_yearly'],
            ['product' => $team, 'amount' => 4900, 'interval' => 'month', 'lookup' => 'artfct_team_monthly'],
            ['product' => $team, 'amount' => 49000, 'interval' => 'year', 'lookup' => 'artfct_team_yearly'],
        ] as $price) {
            $http->post(self::BASE.'/prices', [
                'product' => $price['product'],
                'currency' => 'usd',
                'unit_amount' => $price['amount'],
                'recurring' => ['interval' => $price['interval']],
                'lookup_key' => $price['lookup'],
            ])->throw();
        }

        $webhook = $http->post(self::BASE.'/webhook_endpoints', [
            'url' => $baseUrl.'/webhooks/stripe',
            'enabled_events' => [
                'checkout.session.completed',
                'invoice.payment_failed',
                'invoice.payment_succeeded',
                'customer.subscription.deleted',
                'customer.subscription.updated',
                'customer.subscription.trial_will_end',
            ],
        ])->throw();

        file_put_contents($out, 'STRIPE_WEBHOOK_SECRET='.$webhook->json('secret')."\n");
        chmod($out, 0600);

        $this->info("Created Pro/Team prices and webhook {$webhook->json('id')}. Secret written to {$out}.");

        return self::SUCCESS;
    }
}
