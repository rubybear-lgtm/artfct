<?php

namespace App\Services\Synthetic;

use App\Actions\Teams\CreateTeam;
use App\Enums\AuditEventType;
use App\Enums\TeamRole;
use App\Models\ArtifactIndexEntry;
use App\Models\ArtifactUsageEvent;
use App\Models\AuditEvent;
use App\Models\Collection;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Auth\OrgJwtService;
use App\Services\Billing\QuotaLimits;
use App\Services\Collections\CollectionService;
use App\Services\Collections\UsageEventLogger;
use App\Services\Governance\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds the synthetic orgs (RUB-325): Laravel data (users, roles, tokens,
 * invitations, domain, collections, usage, audit history) and the artifact
 * corpus deployed through the Worker. Idempotent: users/teams are looked up,
 * artifacts are tracked in a key -> id map and skipped when the Worker still
 * lists them. Every synthetic org slug starts with the `zz-` prefix and every
 * email is on the fictional domain.
 */
final class SyntheticSeeder
{
    /** Fixed anchor so backdated timestamps are reproducible run to run. */
    public const ANCHOR = '2026-09-01T00:00:00Z';

    /** @var list<string> */
    private array $notes = [];

    /** @var string|null Admin org token, set only when freshly minted. */
    public ?string $adminToken = null;

    public function __construct(
        private readonly CreateTeam $createTeam,
        private readonly AuditLogger $audit,
        private readonly CollectionService $collections,
        private readonly UsageEventLogger $usage,
    ) {}

    public static function assertSafe(string $slug): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Refusing to run synthetic data commands in production.');
        }

        $prefix = (string) config('synthetic.prefix', 'zz-');
        if (! str_starts_with($slug, $prefix)) {
            throw new RuntimeException("Synthetic org slugs must start with \"{$prefix}\".");
        }
    }

    /**
     * @return array{team: Team, ids: array<string, string>, refused: array<string, string>, notes: list<string>}
     */
    public function seedOrg(string $slug, string $name, ?WorkerTarget $worker, int $count, int $seed, bool $primary = true): array
    {
        self::assertSafe($slug);

        $team = $this->ensureTeam($slug, $name, $primary);
        $ceiling = QuotaLimits::default()->bundleSizeCeilingBytes;
        $specs = $primary
            ? SyntheticCorpus::generate($seed, $count, $ceiling)
            : SyntheticCorpus::generateOrgB($seed, $count);

        // No Worker: seed only the Laravel data (e.g. on a deployed staging app).
        [$ids, $refused] = $worker === null
            ? [[], []]
            : $this->deployCorpus($slug, new WorkerDeployer($worker), $specs);

        if ($primary && $ids !== []) {
            $this->seedCollectionsAndUsage($team, $ids);
        }

        return ['team' => $team, 'ids' => $ids, 'refused' => $refused, 'notes' => $this->notes];
    }

    private function ensureTeam(string $slug, string $name, bool $primary): Team
    {
        $domain = (string) config('synthetic.email_domain');
        $suffix = $primary ? '' : '-b';
        $admin = $this->user("admin{$suffix}", 'Alex Admin', $domain);

        $team = Team::query()->where('slug', $slug)->first()
            ?? $this->createTeam->handle($admin, $name, false, $slug);
        $admin->switchTeam($team);

        if (! $primary) {
            return $team;
        }

        $roles = [
            ['member1', 'Morgan Member', TeamRole::Member],
            ['member2', 'Sam Member', TeamRole::Member],
            ['member3', 'Riley Member', TeamRole::Member],
            ['member4', 'Casey Member', TeamRole::Member],
            ['viewer1', 'Vic Viewer', TeamRole::Viewer],
            ['deactivated1', 'Dana Deactivated', TeamRole::Member],
        ];

        $fresh = ! AuditEvent::query()->where('team_id', $team->id)->exists();
        foreach ($roles as [$local, $display, $role]) {
            $user = $this->user($local, $display, $domain);
            $membership = $team->memberships()->firstOrCreate(['user_id' => $user->id], ['role' => $role]);
            $user->switchTeam($team);
            if ($local === 'deactivated1' && $user->deactivated_at === null) {
                $user->forceFill(['deactivated_at' => now()])->save();
            }
            if ($fresh && $membership->wasRecentlyCreated) {
                $this->audit->record(AuditEventType::MemberAdded, $team, (string) $admin->id, "user:{$user->id}", '127.0.0.1', 'synthetic:seed');
            }
        }

        if ($fresh) {
            // A real role change: promote member4 to admin, then record it.
            $promoted = User::query()->where('email', "member4@{$domain}")->firstOrFail();
            $team->memberships()->where('user_id', $promoted->id)->update(['role' => TeamRole::Admin]);
            $this->audit->record(AuditEventType::RoleChanged, $team, (string) $admin->id, "user:{$promoted->id} -> admin", '127.0.0.1', 'synthetic:seed');
        }

        foreach (['pending1', 'pending2'] as $local) {
            TeamInvitation::query()->firstOrCreate(
                ['team_id' => $team->id, 'email' => "{$local}@{$domain}"],
                ['role' => TeamRole::Member, 'invited_by' => $admin->id, 'expires_at' => now()->addDays(7)],
            );
        }

        $team->domains()->firstOrCreate(['domain' => $domain], ['verification_token' => Str::random(24), 'verified_at' => now()]);

        $this->ensureTokens($team, $admin, $fresh);

        return $team;
    }

    private function ensureTokens(Team $team, User $admin, bool $fresh): void
    {
        foreach (TeamRole::cases() as $role) {
            $name = "synthetic-{$role->value}";
            if (OrgToken::query()->where('team_id', $team->id)->where('name', $name)->exists()) {
                continue;
            }
            $minted = $this->signer()->mint($team, $admin, $role, 31536000);
            $token = OrgToken::query()->create([
                'team_id' => $team->id,
                'user_id' => $admin->id,
                'name' => $name,
                'jti' => $minted['jti'],
                'role' => $role,
                'last_four' => Str::substr($minted['token'], -4),
                'expires_at' => $minted['expires_at'],
            ]);
            $this->audit->record(AuditEventType::TokenCreated, $team, (string) $admin->id, "token:{$token->id}", '127.0.0.1', 'synthetic:seed');
            if ($role === TeamRole::Admin) {
                $this->adminToken = $minted['token'];
            }
        }

        if ($fresh) {
            $extra = $this->signer()->mint($team, $admin, TeamRole::Member, 3600);
            $revoked = OrgToken::query()->create([
                'team_id' => $team->id, 'user_id' => $admin->id, 'name' => 'synthetic-revoked', 'jti' => $extra['jti'],
                'role' => TeamRole::Member, 'last_four' => Str::substr($extra['token'], -4), 'expires_at' => $extra['expires_at'],
            ]);
            $revoked->forceFill(['revoked_at' => now()])->save();
            $this->audit->record(AuditEventType::TokenRevoked, $team, (string) $admin->id, "token:{$revoked->id}", '127.0.0.1', 'synthetic:seed');
        }
    }

    /**
     * The configured signer, or an ephemeral key so tokens can be minted
     * locally. An ephemeral-key token only verifies if its JWKS is published.
     */
    private function signer(): OrgJwtService
    {
        static $ephemeral = null;

        try {
            return OrgJwtService::default();
        } catch (RuntimeException) {
            if ($ephemeral === null) {
                $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
                openssl_pkey_export($key, $pem);
                $ephemeral = new OrgJwtService($pem, 'synthetic-ephemeral');
            }

            return $ephemeral;
        }
    }

    private function user(string $local, string $name, string $domain): User
    {
        return User::query()->firstOrCreate(
            ['email' => "{$local}@{$domain}"],
            ['name' => $name, 'password' => Hash::make(Str::random(40)), 'email_verified_at' => now()],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $specs
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function deployCorpus(string $slug, WorkerDeployer $deployer, array $specs): array
    {
        $mapPath = self::mapPath($slug);
        $ids = File::exists($mapPath) ? (json_decode(File::get($mapPath), true) ?: []) : [];
        $onWorker = array_column($deployer->listArtifacts(), 'id');
        $refused = [];
        $newlyDeployed = [];

        foreach ($specs as $spec) {
            $key = $spec['key'];
            if (isset($ids[$key]) && in_array($ids[$key], $onWorker, true)) {
                continue;
            }
            $result = $deployer->deploy($spec);
            if ($result['id'] === null) {
                $refused[$key] = (string) $result['error'];

                continue;
            }
            $ids[$key] = $result['id'];
            $newlyDeployed[$key] = $spec;
        }

        $anchor = Carbon::parse(self::ANCHOR);
        $changes = [];
        foreach ($newlyDeployed as $key => $spec) {
            $created = $anchor->copy()->subDays((int) $spec['backdate_days']);
            $expires = ($spec['state'] ?? null) === 'expired' ? $anchor->copy()->subDays(1) : null;
            $changes[$ids[$key]] = ['created_at' => $created, 'expires_at' => $expires];
        }
        $deployer->backdate($changes);

        foreach ($newlyDeployed as $key => $spec) {
            match ($spec['state'] ?? null) {
                'revoked' => $deployer->revoke($ids[$key]),
                'legal_hold' => $deployer->placeLegalHold($ids[$key]),
                default => null,
            };
        }

        File::ensureDirectoryExists(dirname($mapPath));
        File::put($mapPath, json_encode($ids, JSON_PRETTY_PRINT));

        return [$ids, $refused];
    }

    /**
     * @param  array<string, string>  $ids
     */
    private function seedCollectionsAndUsage(Team $team, array $ids): void
    {
        if (Collection::query()->where('team_id', $team->id)->exists()) {
            return;
        }

        $admin = User::query()->where('email', 'admin@'.config('synthetic.email_domain'))->firstOrFail();
        $viewers = User::query()->whereIn('email', array_map(fn (string $local): string => $local.'@'.config('synthetic.email_domain'), ['member1', 'member2', 'member3']))->get();

        $sets = [
            'Billing and revenue' => ['billing-dashboard-aug', 'billing-dashboard-jul', 'invoice-reconciliation-tool', 'pricing-experiment-results'],
            'On-call and runbooks' => ['oncall-handoff-runbook', 'runbook-database-failover', 'incident-2026-07-postmortem'],
            'Growth' => ['signup-funnel-dashboard', 'churn-cohort-analysis', 'customer-health-scorecard'],
        ];
        foreach ($sets as $name => $keys) {
            $collection = $this->collections->create($team, $admin, $name);
            foreach ($keys as $key) {
                if (isset($ids[$key])) {
                    $this->collections->addArtifact($collection, $ids[$key]);
                }
            }
            if ($name === 'Billing and revenue') {
                $this->collections->pinCanonical($collection, $admin);
            }
        }

        foreach (['billing-dashboard-aug' => 6, 'oncall-handoff-runbook' => 4, 'signup-funnel-dashboard' => 2] as $key => $views) {
            foreach ($viewers->take($views) as $viewer) {
                if (isset($ids[$key])) {
                    $this->usage->recordView($team, $ids[$key], $viewer->id);
                }
            }
        }
        if (isset($ids['billing-dashboard-aug'])) {
            $this->usage->recordSlackShare($team, $ids['billing-dashboard-aug'], $admin->id);
        }
        if (isset($ids['lineage-v1'], $ids['lineage-v2'])) {
            $this->usage->recordSuperseded($team, $ids['lineage-v1'], $ids['lineage-v2']);
        }
    }

    public static function mapPath(string $slug): string
    {
        return storage_path("app/synthetic/{$slug}.json");
    }

    /**
     * Removes every synthetic org (prefix `zz-`) and only those: Laravel
     * rows, and each org's artifacts on its Worker.
     *
     * @param  array<string, WorkerTarget>  $workers  keyed by org slug
     * @return array{teams: int, artifacts: int}
     */
    public function purge(array $workers): array
    {
        $prefix = (string) config('synthetic.prefix', 'zz-');
        $artifacts = 0;

        foreach ($workers as $slug => $worker) {
            self::assertSafe($slug);
            $deployer = new WorkerDeployer($worker);
            foreach ($deployer->listArtifacts() as $artifact) {
                // A held artifact must be released before the Worker allows deletion.
                try {
                    $deployer->releaseLegalHold($artifact['id']);
                } catch (\Throwable) {
                    // not held or already gone
                }
                $deployer->hardDelete($artifact['id']);
                $artifacts++;
            }
            File::delete(self::mapPath($slug));
        }

        $teams = Team::query()->where('slug', 'like', $prefix.'%')->get();
        foreach ($teams as $team) {
            // Audit rows are append-only in the app; this synthetic-only purge
            // bypasses the model guard on the query builder, prefix-scoped above.
            AuditEvent::query()->where('team_id', $team->id)->toBase()->delete();
            ArtifactUsageEvent::query()->where('team_id', $team->id)->delete();
            ArtifactIndexEntry::query()->where('team_id', $team->id)->delete();
            Collection::query()->where('team_id', $team->id)->delete();
            OrgToken::query()->where('team_id', $team->id)->delete();
            TeamInvitation::query()->where('team_id', $team->id)->delete();
            $team->domains()->delete();
            $team->memberships()->delete();
            $team->forceDelete();
        }
        User::query()->where('email', 'like', '%@'.config('synthetic.email_domain'))->delete();

        return ['teams' => $teams->count(), 'artifacts' => $artifacts];
    }
}
