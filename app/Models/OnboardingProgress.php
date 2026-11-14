<?php

namespace App\Models;

use Database\Factories\OnboardingProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's answer for one onboarding step the server cannot observe:
 * `done` or `skipped`. Read through `OnboardingChecklist`, which also folds in
 * the steps the server can see for itself.
 *
 * @property int $id
 * @property int $user_id
 * @property string $step
 * @property string $outcome
 * @property string|null $host
 * @property string $guidance_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'step', 'outcome', 'host', 'guidance_version'])]
class OnboardingProgress extends Model
{
    /** @use HasFactory<OnboardingProgressFactory> */
    use HasFactory;

    protected $table = 'onboarding_progress';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
