<?php

namespace App\Models;

use Database\Factories\ExternalIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One external-provider identity linked to a `users` row. A single human
 * accumulates many of these across providers (spec 06) — this table
 * replaces the `workos_id` column the starter kit would otherwise put on
 * `users`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $external_id
 * @property string $email
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'provider', 'external_id', 'email', 'verified_at'])]
class ExternalIdentity extends Model
{
    /** @use HasFactory<ExternalIdentityFactory> */
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * Only `created_at` is tracked (per the spec's column list).
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the user this identity belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Determine whether this identity is active (i.e. verified).
     */
    public function isActive(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ExternalIdentity $identity) {
            $identity->created_at ??= now();
        });
    }
}
