<?php

namespace App\Models;

use Database\Factories\McpActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only, non-sensitive telemetry for authenticated MCP tool calls.
 * Request arguments and artifact contents are intentionally never persisted.
 *
 * @property int $id
 * @property int $team_id
 * @property string|null $credential_jti
 * @property string $actor
 * @property string $tool
 * @property string|null $artifact_id
 * @property string $transport
 * @property string|null $request_id
 * @property string|null $session_id
 * @property string|null $client_name
 * @property string $outcome
 * @property int $latency_ms
 * @property Carbon $created_at
 */
#[Fillable(['team_id', 'credential_jti', 'actor', 'tool', 'artifact_id', 'transport', 'request_id', 'session_id', 'client_name', 'outcome', 'latency_ms', 'created_at'])]
class McpActivity extends Model
{
    /** @use HasFactory<McpActivityFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
