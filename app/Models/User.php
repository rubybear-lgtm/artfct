<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasTeams;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'avatar', 'email_verified_at', 'current_team_id'])]
#[Hidden(['password', 'remember_token', 'staff_at', 'case_manager_at'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable;

    /**
     * Get the external (WorkOS AuthKit / Polis / SCIM) identities linked
     * to this user. One human, many identities, across providers — see
     * spec 06.
     *
     * @return HasMany<ExternalIdentity, $this>
     */
    public function externalIdentities(): HasMany
    {
        return $this->hasMany(ExternalIdentity::class);
    }

    /**
     * Get the org tokens (spec 07) this user has created.
     *
     * @return HasMany<OrgToken, $this>
     */
    public function orgTokens(): HasMany
    {
        return $this->hasMany(OrgToken::class);
    }

    /**
     * @return HasMany<McpConnection, $this>
     */
    public function mcpConnections(): HasMany
    {
        return $this->hasMany(McpConnection::class);
    }

    /**
     * Whether this user is artfct staff (spec 17). `staff_at` is not
     * mass-assignable and is only ever set from an operator command.
     */
    public function isStaff(): bool
    {
        return $this->staff_at !== null;
    }

    /**
     * Whether this staff member may open, renew or release a suppression case.
     * A separate privilege: holding `staff_at` alone is not enough.
     */
    public function isCaseManager(): bool
    {
        return $this->staff_at !== null && $this->case_manager_at !== null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'staff_at' => 'datetime',
            'case_manager_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
