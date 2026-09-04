<?php

namespace App\Providers;

use App\Contracts\ArtifactDirectory;
use App\Listeners\CreatePersonalTeam;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Artifacts\HttpArtifactDirectory;
use App\Services\AuthKit\AuthKitClientContract;
use App\Services\AuthKit\FakeAuthKitClient;
use App\Services\AuthKit\RealAuthKitClient;
use App\Services\Governance\ArtifactGovernanceContract;
use App\Services\Governance\FakeArtifactGovernance;
use App\Services\Governance\RealArtifactGovernance;
use App\Services\Identity\DnsResolverContract;
use App\Services\Identity\FakeDnsResolver;
use App\Services\Identity\RealDnsResolver;
use App\Services\Polis\FakePolisClient;
use App\Services\Polis\PolisClientContract;
use App\Services\Polis\RealPolisClient;
use App\Services\Tenancy\FakeTenantProvisioner;
use App\Services\Tenancy\RealTenantProvisioner;
use App\Services\Tenancy\TenantProvisionerContract;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * WorkOS AuthKit and DNS TXT lookups are bound to injectable fakes
     * outside production, the same "mock external services" policy
     * documented for ARTFCT_ORG_TOKEN in DOCUMENTATION.md — real
     * credentials/DNS become a config change, not a rewrite.
     */
    public function register(): void
    {
        $workosConfigured = ! app()->environment('testing') && config('services.workos.client_id');

        $this->app->singleton(
            AuthKitClientContract::class,
            $workosConfigured ? RealAuthKitClient::class : FakeAuthKitClient::class,
        );
        $this->app->singleton(
            DnsResolverContract::class,
            app()->environment('testing') ? FakeDnsResolver::class : RealDnsResolver::class,
        );
        $this->app->singleton(
            ArtifactDirectory::class,
            app()->environment('testing') ? FakeArtifactDirectory::class : HttpArtifactDirectory::class,
        );
        $this->app->singleton(
            TenantProvisionerContract::class,
            app()->environment('testing') ? FakeTenantProvisioner::class : RealTenantProvisioner::class,
        );
        $this->app->singleton(
            PolisClientContract::class,
            app()->environment('testing') ? FakePolisClient::class : RealPolisClient::class,
        );
        $this->app->singleton(
            ArtifactGovernanceContract::class,
            app()->environment('testing') ? FakeArtifactGovernance::class : RealArtifactGovernance::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Event::listen(Registered::class, CreatePersonalTeam::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
