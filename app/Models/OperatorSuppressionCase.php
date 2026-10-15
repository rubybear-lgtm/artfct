<?php

namespace App\Models;

use Database\Factories\OperatorSuppressionCaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A time-bound suppression case (spec 17). While a case is active, operator
 * read events on its tenant are recorded in the internal log only; once it
 * expires or is released, `ops:release-suppressed` writes them to the tenant's
 * log with their original timestamps. Mutable by design, but every change is
 * recorded in the internal operator log by `SuppressionCaseService`.
 *
 * @property int $id
 * @property int $team_id
 * @property string $category
 * @property string $reference
 * @property int|null $created_by_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $released_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['team_id', 'category', 'reference', 'created_by_user_id', 'expires_at', 'released_at'])]
class OperatorSuppressionCase extends Model
{
    /** @use HasFactory<OperatorSuppressionCaseFactory> */
    use HasFactory;

    public const CATEGORY_LEGAL_REQUEST = 'legal_request';

    public const CATEGORY_ABUSE_INVESTIGATION = 'abuse_investigation';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_LEGAL_REQUEST,
        self::CATEGORY_ABUSE_INVESTIGATION,
    ];

    /** The longest a case may run from creation or renewal. */
    public const MAX_DURATION_DAYS = 90;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * Cases that currently suppress operator reads: not released, not expired.
     *
     * @param  Builder<OperatorSuppressionCase>  $query
     * @return Builder<OperatorSuppressionCase>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at')->where('expires_at', '>', now());
    }

    /**
     * Cases whose window has ended but whose withheld events are not yet
     * written to the tenant's log.
     *
     * @param  Builder<OperatorSuppressionCase>  $query
     * @return Builder<OperatorSuppressionCase>
     */
    public function scopePendingRelease(Builder $query): Builder
    {
        return $query->whereNull('released_at')->where('expires_at', '<=', now());
    }

    public function isActive(): bool
    {
        return $this->released_at === null && $this->expires_at->isFuture();
    }
}
