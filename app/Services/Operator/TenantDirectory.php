<?php

namespace App\Services\Operator;

use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Models\Team;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The operator console's tenant list (spec 17, phase 1). Reads only the
 * `teams` table: identity, plan, billing and provisioning columns. Nothing
 * here touches artifacts or anything derived from their content.
 */
final class TenantDirectory
{
    public const PAGE_SIZE = 25;

    /**
     * Teams matching the filters, newest first. Ordered on `(created_at, id)`
     * so the cursor is stable when several teams share a timestamp.
     *
     * @param  array{q?: string|null, plan?: string|null, payment_status?: string|null, region?: string|null, provisioning_failed?: bool, past_due?: bool, cancelling?: bool, deleted?: bool}  $filters
     * @return CursorPaginator<int, Team>
     */
    public function paginate(array $filters): CursorPaginator
    {
        $query = Team::query();

        if ($filters['deleted'] ?? false) {
            $query->onlyTrashed();
        }

        $this->applySearch($query, $filters['q'] ?? null);

        $query
            ->when($filters['plan'] ?? null, fn (Builder $query, string $plan) => $query->where('plan', $plan))
            ->when($filters['payment_status'] ?? null, fn (Builder $query, string $status) => $query->where('payment_status', $status))
            ->when($filters['region'] ?? null, fn (Builder $query, string $region) => $query->where('region', $region))
            ->when($filters['provisioning_failed'] ?? false, fn (Builder $query) => $query->whereNotNull('provisioning_failed_step'))
            ->when($filters['past_due'] ?? false, fn (Builder $query) => $query->where('payment_status', PaymentStatus::PastDue->value))
            ->when($filters['cancelling'] ?? false, fn (Builder $query) => $query->where('cancel_at_period_end', true));

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PAGE_SIZE)
            ->withQueryString();
    }

    /**
     * One list row for a team.
     *
     * @return array{slug: string, name: string, plan: string, payment_status: string, seats: int, region: string|null, release_version: string|null, schema_version: int|null, created_at: string|null, state: 'active'|'deleted'|'provisioning_failed'}
     */
    public function row(Team $team): array
    {
        return [
            'slug' => $team->slug,
            'name' => $team->name,
            'plan' => ($team->plan ?? Plan::Free)->value,
            'payment_status' => ($team->payment_status ?? PaymentStatus::Active)->value,
            'seats' => (int) $team->seats_billed,
            'region' => $team->region,
            'release_version' => $team->release_version,
            'schema_version' => $team->schema_version,
            'created_at' => $team->created_at?->toIso8601String(),
            'state' => match (true) {
                $team->deleted_at !== null => 'deleted',
                $team->provisioning_failed_step !== null => 'provisioning_failed',
                default => 'active',
            },
        ];
    }

    /**
     * Distinct regions in use, for the region filter.
     *
     * @return list<string>
     */
    public function regions(): array
    {
        return Team::withTrashed()
            ->whereNotNull('region')
            ->distinct()
            ->orderBy('region')
            ->pluck('region')
            ->map(fn (mixed $region): string => (string) $region)
            ->values()
            ->all();
    }

    /**
     * Case-insensitive substring match over name, slug, billing email and
     * Stripe customer id. `%`, `_` and the escape character itself are
     * escaped, so the term is always literal. `whereLike` cannot take an
     * `ESCAPE` clause, and SQLite has no default escape character, so this
     * is a raw clause with an explicit `!` escape that works on SQLite and
     * Postgres alike.
     *
     * @param  Builder<Team>  $query
     */
    private function applySearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $pattern = '%'.mb_strtolower(strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_'])).'%';

        $query->where(function (Builder $query) use ($pattern): void {
            foreach (['name', 'slug', 'billing_email', 'stripe_customer_id'] as $column) {
                $query->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }
}
