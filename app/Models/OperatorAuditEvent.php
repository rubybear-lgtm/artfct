<?php

namespace App\Models;

use App\Enums\AuditEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A row in the internal operator log (spec 17). It receives every operator
 * event at full fidelity, including the ones withheld from a tenant's own
 * audit log by a suppression case. Append-only exactly like `AuditEvent`:
 * the write guards are copied from there, and `team_id` has no foreign key,
 * so the row survives a tenant erase.
 *
 * @property int $id
 * @property int|null $team_id
 * @property string|null $team_slug
 * @property AuditEventType $event_type
 * @property int|null $staff_user_id
 * @property string $target
 * @property string|null $reason
 * @property string $ip
 * @property string $user_agent
 * @property string $outcome
 * @property int|null $suppression_case_id
 * @property Carbon $created_at
 */
#[Fillable([
    'team_id',
    'team_slug',
    'event_type',
    'staff_user_id',
    'target',
    'reason',
    'ip',
    'user_agent',
    'outcome',
    'suppression_case_id',
])]
class OperatorAuditEvent extends Model
{
    /** This table has no `updated_at` column: rows are write-once. */
    public $timestamps = false;

    protected static function boot(): void
    {
        parent::boot();

        static::updating(function (): void {
            throw new \LogicException('OperatorAuditEvent rows are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new \LogicException('OperatorAuditEvent rows are append-only and cannot be deleted.');
        });
    }

    protected $casts = [
        'event_type' => AuditEventType::class,
        'created_at' => 'datetime',
    ];

    /**
     * @throws \LogicException always: operator log rows are append-only.
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException('OperatorAuditEvent rows are append-only and cannot be updated.');
    }

    /**
     * Blocks the `save()` path too, not just `update()`. Only the initial
     * insert (`$this->exists === false`) is allowed through.
     *
     * @throws \LogicException when called on an already-persisted row.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('OperatorAuditEvent rows are append-only and cannot be updated.');
        }

        return parent::save($options);
    }

    /**
     * @throws \LogicException always: operator log rows are append-only.
     */
    public function delete(): ?bool
    {
        throw new \LogicException('OperatorAuditEvent rows are append-only and cannot be deleted.');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
}
