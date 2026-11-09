<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Binds one OAuth public client to one bot teammate inside a team (RUB-524).
 * Reinstalls with the same client reuse this row instead of minting a new bot.
 *
 * @property int $id
 * @property int $team_id
 * @property string $oauth_client_id
 * @property int $bot_user_id
 * @property int|null $mcp_connection_id
 * @property int|null $consented_by_user_id
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Team $team
 * @property-read User $bot
 * @property-read McpConnection|null $mcpConnection
 * @property-read User|null $consentedBy
 */
#[Fillable([
    'team_id',
    'oauth_client_id',
    'bot_user_id',
    'mcp_connection_id',
    'consented_by_user_id',
])]
class OAuthBotInstallation extends Model
{
    protected $table = 'oauth_bot_installations';

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bot_user_id');
    }

    /** @return BelongsTo<McpConnection, $this> */
    public function mcpConnection(): BelongsTo
    {
        return $this->belongsTo(McpConnection::class);
    }

    /** @return BelongsTo<User, $this> */
    public function consentedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consented_by_user_id');
    }
}
