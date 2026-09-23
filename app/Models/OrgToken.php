<?php

namespace App\Models;

use App\Enums\TeamRole;
use Database\Factories\OrgTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An org-scoped API credential for the CLI, MCP server, or CI (spec 07's
 * `orgToken`). The raw JWT is minted once at creation time and never
 * persisted — this row only ever stores `jti` (the denylist key), a
 * display-only `last_four`, and the claims that were signed into the
 * token, so revocation and auditing don't require the credential itself.
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property int|null $mcp_connection_id
 * @property string $name
 * @property string $jti
 * @property TeamRole $role
 * @property string $last_four
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property array<int, string>|null $slack_channels
 * @property-read Team $team
 * @property-read User $user
 */
#[Fillable(['team_id', 'user_id', 'mcp_connection_id', 'name', 'jti', 'role', 'last_four', 'expires_at', 'slack_channels'])]
class OrgToken extends Model
{
    /** @use HasFactory<OrgTokenFactory> */
    use HasFactory;

    /**
     * Get the team (org) this token belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who created this token.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<McpConnection, $this> */
    public function mcpConnection(): BelongsTo
    {
        return $this->belongsTo(McpConnection::class);
    }

    /**
     * Scope to tokens that have not been revoked.
     *
     * @param  Builder<OrgToken>  $query
     * @return Builder<OrgToken>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * Whether this token has been revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'slack_channels' => 'array',
        ];
    }
}
