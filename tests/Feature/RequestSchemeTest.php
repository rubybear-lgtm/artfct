<?php

use Illuminate\Support\Facades\URL;

test('a_guest_bounced_to_sign_in_is_remembered_with_an_https_return_url', function () {
    config(['app.url' => 'https://staging.artfct.dev']);
    URL::forceRootUrl('https://staging.artfct.dev');
    URL::forceScheme('https');

    // The Railway edge reaches the app over plain http and is not a trusted proxy.
    test()->get('http://staging.artfct.dev/settings/account')->assertRedirect();

    expect(session('url.intended'))->toBe('https://staging.artfct.dev/settings/account');
});

test('the_request_scheme_is_left_alone_when_the_app_url_is_http', function () {
    config(['app.url' => 'http://artfct.test']);

    test()->get('http://artfct.test/settings/account')->assertRedirect();

    expect(session('url.intended'))->toBe('http://artfct.test/settings/account');
});
