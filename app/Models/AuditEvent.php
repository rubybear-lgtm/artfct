<?php

namespace App\Models;

use App\Enums\AuditEventType;
use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An append-only audit row (spec 11). "No code path updates or deletes an
 * audit row" is enforced here, not merely by convention: `update()` and
 * `delete()` are overridden to throw, so calling either from anywhere in
 * the codebase is a runtime failure, not a silently-accepted no-op.
 *
 * @property int $id
 * @property int|null $team_id
 * @property AuditEventType $event_type
 * @property string $actor
 * @property string $target
 * @property string $ip
 * @property string $user_agent
 * @property string $outcome
 * @property Carbon $created_at
 */
#[Fillable(['team_id', 'event_type', 'actor', 'target', 'ip', 'user_agent', 'outcome'])]
class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use HasFactory;

    /** This table has no `updated_at` column — rows are write-once. */
    public $timestamps = false;

    protected static function boot(): void
    {
        parent::boot();

        // Belt-and-suspenders alongside the save()/update()/delete()
        // overrides below: these model events also fire for
        // `AuditEvent::find($id)->update(...)` / `->delete()` call chains
        // that route through the base Eloquent event pipeline.
        static::updating(function (): void {
            throw new \LogicException('AuditEvent rows are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new \LogicException('AuditEvent rows are append-only and cannot be deleted.');
        });
    }

    protected $casts = [
        'event_type' => AuditEventType::class,
        'created_at' => 'datetime',
    ];

    /**
     * @throws \LogicException always — audit rows are append-only.
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException('AuditEvent rows are append-only and cannot be updated.');
    }

    /**
     * Blocks the `save()` path too, not just `update()` — `$event->outcome
     * = 'x'; $event->save();` would otherwise reach Eloquent's internal
     * `performUpdate()` directly and bypass the guard above. Only the
     * initial insert (`$this->exists === false`) is allowed through.
     *
     * @throws \LogicException when called on an already-persisted row.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('AuditEvent rows are append-only and cannot be updated.');
        }

        return parent::save($options);
    }

    /**
     * @throws \LogicException always — audit rows are append-only.
     */
    public function delete(): ?bool
    {
        throw new \LogicException('AuditEvent rows are append-only and cannot be deleted.');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
