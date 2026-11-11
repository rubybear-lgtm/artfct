<?php

namespace App\Providers;

use App\Services\FeatureFlags;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\ServiceProvider;

class FeatureFlagServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->app->make(FeatureFlags::class)->register();
        $this->app['events']->listen(RequestHandled::class, fn () => $this->app->make(FeatureFlags::class)->flush());
    }
}
