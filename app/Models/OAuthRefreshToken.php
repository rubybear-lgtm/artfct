<?php

namespace App\Models;

use Database\Factories\OAuthRefreshTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A rotating OAuth refresh credential. Only a SHA-256 hash of the raw value
 * is stored, so a database read cannot mint a new access token.
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property int|null $mcp_connection_id
 * @property string $client_id
 * @property string $token_hash
 * @property list<string> $scopes
 * @property Carbon $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['team_id', 'user_id', 'mcp_connection_id', 'client_id', 'token_hash', 'scopes', 'expires_at', 'last_used_at', 'revoked_at'])]
class OAuthRefreshToken extends Model
{
    protected $table = 'oauth_refresh_tokens';

    /** @use HasFactory<OAuthRefreshTokenFactory> */
    use HasFactory;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<McpConnection, $this> */
    public function mcpConnection(): BelongsTo
    {
        return $this->belongsTo(McpConnection::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
