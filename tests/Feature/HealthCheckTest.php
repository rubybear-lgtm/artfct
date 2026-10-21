<?php

use Illuminate\Support\Facades\File;

it('uses the framework health route for Railway checks without starting a session', function () {
    foreach (['staging', 'production'] as $environment) {
        expect(File::get(base_path(".railway/{$environment}.ts")))->toContain('healthcheck: "/up"');
    }

    $this->get('/up')
        ->assertSuccessful()
        ->assertCookieMissing(config('session.cookie'));
});
