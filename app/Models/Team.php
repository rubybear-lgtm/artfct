<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueTeamSlugs;
use App\Enums\AuthMode;
use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_personal
 * @property AuthMode $auth_mode
 * @property Carbon|null $provisioned_at
 * @property string|null $provisioning_failed_step
 * @property string|null $release_version
 * @property int $schema_version
 * @property string|null $region
 * @property int|null $retention_days
 * @property Plan $plan
 * @property PaymentStatus $payment_status
 * @property string|null $stripe_subscription_id
 * @property int|null $seats_billed
 * @property string|null $custom_hostname
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, TeamInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, TeamDomain> $domains
 */
#[Fillable(['name', 'slug', 'is_personal', 'auth_mode', 'retention_days', 'custom_hostname'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = static::generateUniqueTeamSlug($team->name);
            }
        });

        static::updating(function (Team $team) {
            if ($team->isDirty('name') && ! $team->isDirty('slug')) {
                $team->slug = static::generateUniqueTeamSlug($team->name, $team->id);
            }

            // Spec 11: "`region` is set at provisioning and is immutable
            // afterwards — moving a tenant between regions is a migration,
            // not a setting." Setting it for the first time (null -> value,
            // what spec-9 provisioning does) is allowed; changing an
            // already-set region is not, regardless of call path
            // (mass-assignment is excluded from `#[Fillable]` entirely, but
            // `forceFill` and direct property assignment both still reach
            // this guard).
            if ($team->isDirty('region') && $team->getOriginal('region') !== null) {
                throw new \RuntimeException(
                    "Cannot change team [{$team->slug}]'s region from [{$team->getOriginal('region')}] to [{$team->region}] — region is immutable after provisioning."
                );
            }
        });
    }

    /**
     * Get the first admin of this team (the creator, in the common case).
     */
    public function firstAdmin(): ?Model
    {
        return $this->members()
            ->wherePivot('role', TeamRole::Admin->value)
            ->oldest('team_members.created_at')
            ->first();
    }

    /**
     * Get all members of this team.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this team.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get all domains claimed for this team.
     *
     * @return HasMany<TeamDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(TeamDomain::class);
    }

    /**
     * Determine whether the team has at least one verified domain.
     */
    public function hasVerifiedDomain(): bool
    {
        return $this->domains()->whereNotNull('verified_at')->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'auth_mode' => AuthMode::class,
            'provisioned_at' => 'datetime',
            'plan' => Plan::class,
            'payment_status' => PaymentStatus::class,
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
