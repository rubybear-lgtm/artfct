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
        // while `php artisan inertia:start-ssr` is running.
        if (getenv('TEST_INERTIA_SSR') !== 'true') {
            Inertia::disableSsr();
        }
    }
}
