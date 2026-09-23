<?php

namespace App\Models;

use Database\Factories\McpConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A revocable MCP client connection record.
 *
 * Credentials are intentionally not persisted here. Authentication material
 * is owned by the transport-specific credential store, while this model keeps
 * the auditable connection metadata and lifecycle state.
 */
#[Fillable([
    'team_id',
    'user_id',
    'name',
    'client_name',
    'client_version',
    'protocol_version',
    'transport',
    'scopes',
    'metadata',
    'expires_at',
    'revoked_at',
])]
#[Hidden(['credential_jti'])]
class McpConnection extends Model
{
    /** @use HasFactory<McpConnectionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (McpConnection $connection): void {
            $connection->public_id ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<OAuthRefreshToken, $this> */
    public function oauthRefreshTokens(): HasMany
    {
        return $this->hasMany(OAuthRefreshToken::class);
    }

    /** @return HasMany<OrgToken, $this> */
    public function orgTokens(): HasMany
    {
        return $this->hasMany(OrgToken::class);
    }

    /**
     * @param  Builder<McpConnection>  $query
     * @return Builder<McpConnection>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function touchUsage(): void
    {
        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'metadata' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
