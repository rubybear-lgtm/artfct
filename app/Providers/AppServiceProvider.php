<?php

namespace App\Providers;

use App\Contracts\ArtifactContentSource;
use App\Contracts\ArtifactDirectory;
use App\Listeners\CreatePersonalTeam;
use App\Mail\CloudflareEmailTransport;
use App\Models\McpConnection;
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
use App\Services\Indexing\FakeReranker;
use App\Services\Indexing\FakeVectorIndex;
use App\Services\Indexing\PgVectorIndex;
use App\Services\Indexing\RealEmbeddings;
use App\Services\Indexing\RealRenderer;
use App\Services\Indexing\RealReranker;
use App\Services\Indexing\RendererContract;
use App\Services\Indexing\RerankerContract;
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
use App\Services\WorkerEvents\ArtifactDeletedHandler;
use App\Services\WorkerEvents\ArtifactViewedHandler;
use App\Services\WorkerEvents\WorkerEventHandlers;
use App\Support\ClientIp;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Registered;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
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
use Inertia\ExceptionResponse;
use Inertia\Inertia;
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
            app()->environment('testing') ? FakeVectorIndex::class : PgVectorIndex::class,
        );
        $this->app->singleton(
            RerankerContract::class,
            app()->environment('testing') ? FakeReranker::class : RealReranker::class,
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

        // A browser that hits a refused or missing page lands on one branded,
        // plain-language screen instead of Laravel's bare "404 | Not Found".
        // JSON clients keep the machine-readable body they parse, and local
        // debug keeps Laravel's stack trace.
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response): ?ExceptionResponse {
            $request = $response->request;

            // A dead invitation code is a page, not a generic 404. Rendering it
            // here (rather than from Route::missing, which runs inside
            // SubstituteBindings and so before the appended web middleware) is
            // what gives it the shared props the layout and page expect. It has
            // to precede the debug check below so the page renders in local
            // development too.
            if ($response->statusCode() === 404
                && $request->route()?->getName() === 'invitations.show'
                && ! $request->expectsJson()) {
                try {
                    return $response
                        ->render('invitations/show', [
                            'state' => 'invalid',
                            'invitation' => null,
                            'signedInAs' => $request->user()?->email,
                        ])
                        ->withSharedData();
                } catch (\Throwable $e) {
                    report($e);

                    return null;
                }
            }

            if (! in_array($response->statusCode(), [403, 404, 419, 429, 500, 503], true)) {
                return null;
            }

            if (config('app.debug')) {
                return null;
            }

            // Never replace a JSON error body. The path list mirrors the
            // shouldRenderJsonWhen patterns in bootstrap/app.php; checking the
            // rendered response too means a divergence there cannot break a
            // client contract.
            if ($request->expectsJson()
                || $request->is('api/*', 'oauth/register', 'oauth/token', 'oauth/revoke', 'mcp')
                || $response->response instanceof JsonResponse) {
                return null;
            }

            $props = ['status' => $response->statusCode()];

            if ($response->statusCode() === 429) {
                $retryAfter = $response->response->headers->get('Retry-After');

                // A non-positive hint is no hint: "Wait 0 seconds" is worse
                // than saying nothing.
                if (is_numeric($retryAfter) && (int) $retryAfter > 0) {
                    $props['retryAfter'] = (int) $retryAfter;
                }
            }

            try {
                return $response->render('error', $props)->withSharedData();
            } catch (\Throwable $e) {
                // A throwing share closure must not turn a handled error into a
                // bare framework error page.
                report($e);

                return null;
            }
        });

        Mail::extend('cloudflare', fn (): CloudflareEmailTransport => new CloudflareEmailTransport(
            (string) config('services.cloudflare_email.account_id'),
            (string) config('services.cloudflare_email.api_token'),
        ));

        Event::listen(Registered::class, CreatePersonalTeam::class);
        Event::listen(SessionInitialized::class, function (SessionInitialized $event): void {
            $claims = request()->attributes->get('org_jwt_claims');
            $connection = request()->attributes->get('mcp_connection');

            if (! is_array($claims)
                || ! is_string($claims['org_id'] ?? null)
                || ! $connection instanceof McpConnection) {
                return;
            }

            Cache::put(
                'mcp-session:'.$event->sessionId,
                [
                    'connection_public_id' => $connection->public_id,
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
            'artifact.deleted',
            fn (array $event) => $this->app->make(ArtifactDeletedHandler::class)->handle($event),
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
        // Registration is open to anyone, so this only guards the endpoint
        // against bursts. The budget for creating new clients is spent in the
        // controller, where a repeat of an existing registration costs nothing.
        // Neither key uses the client name: every copy of a client such as
        // Claude Code sends the same name, so a name-keyed bucket let one
        // caller lock out every user of that client.
        RateLimiter::for('oauth-registration', function (Request $request): Limit {
            $perMinute = (int) config('auth.oauth_registration_per_minute', 120);

            return Limit::perMinute($perMinute)->by('oauth-registration-burst:'.ClientIp::for($request));
        });
        RateLimiter::for('mcp', function (Request $request): array {
            $limit = (int) config('auth.mcp_throttle_per_minute', 120);
            $claims = $request->attributes->get('org_jwt_claims');
            $team = $request->attributes->get('org_jwt_team');

            return [
                Limit::perMinute($limit)->by('mcp-org:'.($team->slug ?? ClientIp::for($request))),
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
