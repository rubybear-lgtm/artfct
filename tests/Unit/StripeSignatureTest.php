<?php

use App\Services\Billing\StripeSignature;

test('valid_signature_verifies', function () {
    $header = StripeSignature::header('{"id":"evt_1"}', 'whsec_test', 1_700_000_000);

    expect(StripeSignature::verify($header, '{"id":"evt_1"}', 'whsec_test', 1_700_000_100))->toBeTrue();
});

test('tampered_body_is_rejected', function () {
    $header = StripeSignature::header('{"id":"evt_1"}', 'whsec_test', 1_700_000_000);

    expect(StripeSignature::verify($header, '{"id":"evt_2"}', 'whsec_test', 1_700_000_000))->toBeFalse();
});

test('wrong_secret_is_rejected', function () {
    $header = StripeSignature::header('{}', 'whsec_other', 1_700_000_000);

    expect(StripeSignature::verify($header, '{}', 'whsec_test', 1_700_000_000))->toBeFalse();
});

test('stale_timestamp_is_rejected', function () {
    $header = StripeSignature::header('{}', 'whsec_test', 1_700_000_000);

    expect(StripeSignature::verify($header, '{}', 'whsec_test', 1_700_000_000 + 601))->toBeFalse();
});

test('unset_secret_or_missing_header_fails_closed', function () {
    $header = StripeSignature::header('{}', 'whsec_test', 1_700_000_000);

    expect(StripeSignature::verify($header, '{}', null, 1_700_000_000))->toBeFalse()
        ->and(StripeSignature::verify($header, '{}', '', 1_700_000_000))->toBeFalse()
        ->and(StripeSignature::verify(null, '{}', 'whsec_test', 1_700_000_000))->toBeFalse()
        ->and(StripeSignature::verify('garbage', '{}', 'whsec_test', 1_700_000_000))->toBeFalse();
});

test('any_matching_v1_signature_is_accepted', function () {
    $good = hash_hmac('sha256', '1700000000.{}', 'whsec_test');

    expect(StripeSignature::verify("t=1700000000,v1=deadbeef,v1={$good}", '{}', 'whsec_test', 1_700_000_000))->toBeTrue();
});
