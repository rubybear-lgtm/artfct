<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Inertia\Inertia;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Server-side rendering is off in tests. A test that needs the real
        // server render (a hydration check) opts in with TEST_INERTIA_SSR=true
        // while `php artisan inertia:start-ssr` is running. Throw on any SSR
        // failure then, so a dead daemon errors instead of silently falling
        // back to a client-only render that would pass without hydrating.
        if (getenv('TEST_INERTIA_SSR') !== 'true') {
            Inertia::disableSsr();
        } else {
            config(['inertia.ssr.throw_on_error' => true]);
        }
    }
}
