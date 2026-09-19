<?php

namespace App\Providers;

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Listeners\CreatePersonalTeam;
use App\Services\Artifacts\FakeArtifactContentSource;
use App\Services\Artifacts\FakeArtifactDirectory;
use App\Services\Artifacts\HttpArtifactContentSource;
use App\Services\Artifacts\HttpArtifactDirectory;
use App\Services\AuthKit\AuthKitClientContract;
use App\Services\AuthKit\FakeAuthKitClient;
use App\Services\AuthKit\RealAuthKitClient;
use App\Services\Billing\BillingContract;
use App\Services\Billing\FakeBilling;
use App\Services\Billing\FakeUsage;
use App\Services\Billing\RealBilling;
use App\Services\Billing\RealUsage;
use App\Services\Billing\UsageContract;
use App\Services\Governance\ArtifactGovernanceContract;
use App\Services\Governance\FakeArtifactGovernance;
use App\Services\Governance\RealArtifactGovernance;
use App\Services\Identity\DnsResolverContract;
use App\Services\Identity\FakeDnsResolver;
use App\Services\Identity\RealDnsResolver;
use App\Services\Indexing\EmbeddingsContract;
use App\Services\Indexing\FakeEmbeddings;
use App\Services\Indexing\FakeRenderer;
use App\Services\Indexing\FakeVectorIndex;
use App\Services\Indexing\RealEmbeddings;
use App\Services\Indexing\RealRenderer;
use App\Services\Indexing\RealVectorIndex;
use App\Services\Indexing\RendererContract;
use App\Services\Indexing\VectorIndexContract;
use App\Services\Polis\FakePolisClient;
use App\Services\Polis\PolisClientContract;
use App\Services\Polis\RealPolisClient;
use App\Services\Slack\ArtifactSharingContract;
use App\Services\Slack\FakeArtifactSharing;
use App\Services\Slack\FakeSlackPost;
use App\Services\Slack\RealArtifactSharing;
use App\Services\Slack\RealSlackPost;
use App\Services\Slack\SlackPostContract;
use App\Services\Tenancy\FakeTenantProvisioner;
use App\Services\Tenancy\RealTenantProvisioner;
use App\Services\Tenancy\TenantProvisionerContract;
use App\Services\WorkerEvents\ArtifactCreatedHandler;
use App\Services\WorkerEvents\WorkerEventHandlers;
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
        $this->app->singleton(WorkerEventHandlers::class);

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
            ArtifactContentSource::class,
            app()->environment('testing') ? FakeArtifactContentSource::class : HttpArtifactContentSource::class,
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
        $this->app->singleton(
            RendererContract::class,
            app()->environment('testing') ? FakeRenderer::class : RealRenderer::class,
        );
        $this->app->singleton(
            EmbeddingsContract::class,
            app()->environment('testing') ? FakeEmbeddings::class : RealEmbeddings::class,
        );
        $this->app->singleton(
            VectorIndexContract::class,
            app()->environment('testing') ? FakeVectorIndex::class : RealVectorIndex::class,
        );
        $this->app->singleton(
            UsageContract::class,
            app()->environment('testing') ? FakeUsage::class : RealUsage::class,
        );
        $this->app->singleton(
            BillingContract::class,
            app()->environment('testing') ? FakeBilling::class : RealBilling::class,
        );
        $this->app->singleton(
            ArtifactSharingContract::class,
            app()->environment('testing') ? FakeArtifactSharing::class : RealArtifactSharing::class,
        );
        $this->app->singleton(
            SlackPostContract::class,
            app()->environment('testing') ? FakeSlackPost::class : RealSlackPost::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Event::listen(Registered::class, CreatePersonalTeam::class);

        $this->app->make(WorkerEventHandlers::class)->register(
            'artifact.created',
            fn (array $event) => $this->app->make(ArtifactCreatedHandler::class)->handle($event),
        );
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
