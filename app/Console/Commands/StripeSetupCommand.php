<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * One-time Stripe (test mode) provisioning: the per-seat Team price and the
 * webhook endpoint. The webhook signing secret is only shown by Stripe at
 * creation, so it is written to a private file, never printed.
 */
#[Signature('billing:stripe-setup {--url= : Public base URL of this app} {--amount=1200 : Price per seat per month, in cents} {--out= : File to write STRIPE_TEAM_PRICE_ID and STRIPE_WEBHOOK_SECRET to}')]
#[Description('Create the Stripe per-seat price and webhook endpoint for this environment')]
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

        $product = $http->post(self::BASE.'/products', ['name' => 'artfct Team (per seat)'])->throw()->json('id');

        $price = $http->post(self::BASE.'/prices', [
            'product' => $product,
            'currency' => 'usd',
            'unit_amount' => (int) $this->option('amount'),
            'recurring' => ['interval' => 'month'],
        ])->throw()->json('id');

        $webhook = $http->post(self::BASE.'/webhook_endpoints', [
            'url' => $baseUrl.'/webhooks/stripe',
            'enabled_events' => [
                'checkout.session.completed',
                'invoice.payment_failed',
                'invoice.payment_succeeded',
                'customer.subscription.deleted',
            ],
        ])->throw();

        file_put_contents($out, 'STRIPE_TEAM_PRICE_ID='.$price."\nSTRIPE_WEBHOOK_SECRET=".$webhook->json('secret')."\n");
        chmod($out, 0600);

        $this->info("Created price {$price} and webhook {$webhook->json('id')}. Values written to {$out}.");

        return self::SUCCESS;
    }
}
