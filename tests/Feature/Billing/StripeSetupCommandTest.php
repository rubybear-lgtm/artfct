<?php

use Illuminate\Support\Facades\Http;

test('setup_creates_price_and_webhook_and_writes_the_secret_to_a_private_file', function () {
    config(['services.stripe.secret' => 'sk_test_x']);
    Http::fake([
        'api.stripe.com/v1/products' => Http::response(['id' => 'prod_1']),
        'api.stripe.com/v1/prices' => Http::response(['id' => 'price_1']),
        'api.stripe.com/v1/webhook_endpoints' => Http::response(['id' => 'we_1', 'secret' => 'whsec_abc']),
    ]);
    $out = tempnam(sys_get_temp_dir(), 'stripe');

    test()->artisan('billing:stripe-setup', ['--url' => 'https://staging.test', '--out' => $out])
        ->doesntExpectOutputToContain('whsec_abc')
        ->assertSuccessful();

    expect(file_get_contents($out))->toBe("STRIPE_TEAM_PRICE_ID=price_1\nSTRIPE_WEBHOOK_SECRET=whsec_abc\n");
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/webhook_endpoints')
        && $request['url'] === 'https://staging.test/webhooks/stripe');
    unlink($out);
});

test('setup_refuses_a_live_key', function () {
    config(['services.stripe.secret' => 'sk_live_x']);
    Http::fake();

    test()->artisan('billing:stripe-setup', ['--url' => 'https://x.test', '--out' => '/tmp/none'])->assertFailed();

    Http::assertNothingSent();
});
