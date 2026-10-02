<?php

namespace App\Models;

use Database\Factories\TeamDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A domain an org has claimed and (optionally) proven control of via a DNS
 * TXT record. `verified_at` is nullable and re-settable so a domain can be
 * verified, unverified (record removed), and reverified.
 *
 * @property int $id
 * @property int $team_id
 * @property string $domain
 * @property string $verification_token
 * @property Carbon|null $verified_at
 * @property-read Team $team
 */
#[Fillable(['team_id', 'domain', 'verification_token', 'verified_at'])]
class TeamDomain extends Model
{
    /** @use HasFactory<TeamDomainFactory> */
    use HasFactory;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (TeamDomain $domain) {
            if (empty($domain->verification_token)) {
                $domain->verification_token = Str::random(32);
            }
        });
    }

    /**
     * The DNS TXT record name that must carry `verification_token`.
     */
    public function txtRecordName(): string
    {
        return '_artfct-verify.'.$this->domain;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Get the team that claims this domain.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
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
        ];
    }
}
