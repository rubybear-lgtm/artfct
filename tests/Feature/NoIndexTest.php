<?php

test('non_production_environments_send_noindex', function () {
    test()->get('/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('production_does_not_send_noindex', function () {
    app()->detectEnvironment(fn () => 'production');

    test()->get('/')->assertHeaderMissing('X-Robots-Tag');
});
