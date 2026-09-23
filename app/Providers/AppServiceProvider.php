<?php

namespace App\Providers;

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Listeners\CreatePersonalTeam;
use App\Mail\CloudflareEmailTransport;
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
use App\Services\Billing\OrgLimitsWriter;
use App\Services\Billing\RealBilling;
use App\Services\Billing\RealUsage;
use App\Services\Billing\UsageContract;
use App\Services\Governance\ArtifactGovernanceContract;
use App\Services\Governance\FakeArtifactGovernance;
use App\Services\Governance\HttpArtifactGovernance;
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
use App\Services\WorkerEvents\ArtifactViewedHandler;
use App\Services\WorkerEvents\WorkerEventHandlers;
use App\Support\ClientIp;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Registered;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Mcp\Events\SessionInitialized;

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
        $this->app->bind(OrgLimitsWriter::class, fn (): OrgLimitsWriter => OrgLimitsWriter::default());

        $workosConfigured = ! app()->environment('testing')
            && config('services.workos.client_id')
            && config('services.workos.secret')
            && config('services.workos.redirect_url');

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
            app()->environment('testing') ? FakeArtifactDirectory::class : fn (): HttpArtifactDirectory => HttpArtifactDirectory::default(),
        );
        $this->app->singleton(
            ArtifactContentSource::class,
            app()->environment('testing') ? FakeArtifactContentSource::class : fn (): HttpArtifactContentSource => HttpArtifactContentSource::default(),
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
            app()->environment('testing') ? FakeArtifactGovernance::class : HttpArtifactGovernance::class,
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

        Mail::extend('cloudflare', fn (): CloudflareEmailTransport => new CloudflareEmailTransport(
            (string) config('services.cloudflare_email.account_id'),
            (string) config('services.cloudflare_email.api_token'),
        ));

        Event::listen(Registered::class, CreatePersonalTeam::class);
        Event::listen(SessionInitialized::class, function (SessionInitialized $event): void {
            $claims = request()->attributes->get('org_jwt_claims');

            if (! is_array($claims) || ! is_string($claims['jti'] ?? null) || ! is_string($claims['org_id'] ?? null)) {
                return;
            }

            Cache::put(
                'mcp-session:'.$event->sessionId,
                [
                    'jti' => $claims['jti'],
                    'org_id' => $claims['org_id'],
                    'protocol_version' => $event->protocolVersion,
                ],
                now()->addMinutes((int) config('auth.mcp_session_ttl_minutes', 30)),
            );
        });

        $this->app->make(WorkerEventHandlers::class)->register(
            'artifact.created',
            fn (array $event) => $this->app->make(ArtifactCreatedHandler::class)->handle($event),
        );
        $this->app->make(WorkerEventHandlers::class)->register(
            'artifact.viewed',
            fn (array $event) => $this->app->make(ArtifactViewedHandler::class)->handle($event),
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Trusted proxies are scoped to Cloudflare (RUB-372), so a request
        // arriving on the direct Railway path is not trusted to tell us its
        // scheme. Pin it to the configured application URL rather than inferring
        // it per request, so redirect URIs and signed links stay https wherever
        // the request came from.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Scoped to the platform's real ingress (RUB-372): Cloudflare fronts the
        // public domain and publishes its ranges, so Cloudflare is trusted while
        // the Railway edge -- neither enumerable nor a trustworthy source for a
        // header a caller can write -- is not.
        TrustProxies::at(array_values((array) config('trusted_ingress.edge_ranges', [])));

        // Sign-in and invitation endpoints: per IP, generous for people, tight for scripts.
        RateLimiter::for('invitations', fn (Request $request) => [
            Limit::perHour((int) config('auth.invitations_per_hour', 30))->by('user:'.$request->user()?->id),
            Limit::perHour((int) config('auth.invitations_per_hour', 30))->by('team:'.$request->route('team')),
        ]);
        RateLimiter::for('team-creation', fn (Request $request) => Limit::perHour(10)->by('user:'.$request->user()?->id));
        // RUB-372: a truthful client address is not available in this topology.
        // The transport peer is the platform's edge, and every header that could
        // carry the client is written by the caller, so any limit keyed on a
        // client address is either forgeable or coarser than it looks. The limit
        // is therefore expressed on an address the caller cannot set, and a
        // second key is added wherever the request itself names the account
        // being attacked. Per-client granularity is given up deliberately: the
        // cost is that callers arriving through one edge address share this
        // bucket, and the per-account key is what keeps that from being the only
        // control.
        RateLimiter::for('auth', function (Request $request): array {
            $perMinute = (int) config('auth.throttle_per_minute', 20);
            $limits = [Limit::perMinute($perMinute)->by(ClientIp::for($request))];

            $team = $request->route('team');
            $teamKey = is_object($team) ? ($team->slug ?? null) : $team;

            if (is_string($teamKey) && $teamKey !== '') {
                $limits[] = Limit::perMinute($perMinute)->by('auth-team:'.$teamKey);
            }

            return $limits;
        });
        RateLimiter::for('oauth-registration', function (Request $request): array {
            $perHour = (int) config('auth.oauth_registration_per_hour', 10);
            $limits = [Limit::perHour($perHour)->by('oauth-registration:'.ClientIp::for($request))];

            // Registration has no account to key on, but it does name the client
            // it is creating, so one name cannot be hammered even from many
            // addresses.
            $name = mb_strtolower(trim((string) $request->input('client_name')));

            if ($name !== '') {
                $limits[] = Limit::perHour($perHour)->by('oauth-registration-name:'.$name);
            }

            return $limits;
        });
        RateLimiter::for('mcp', function (Request $request): array {
            $limit = (int) config('auth.mcp_throttle_per_minute', 120);
            $claims = $request->attributes->get('org_jwt_claims');
            $team = $request->attributes->get('org_jwt_team');

            return [
                Limit::perMinute($limit)->by('mcp-org:'.($team?->slug ?? ClientIp::for($request))),
                Limit::perMinute($limit)->by('mcp:'.($claims['jti'] ?? ClientIp::for($request))),
            ];
        });

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
