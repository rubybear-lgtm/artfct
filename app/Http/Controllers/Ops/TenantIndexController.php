<?php

namespace App\Http\Controllers\Ops;

use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Operator\TenantDirectory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operator console's tenant list (spec 17): search, filters and a cursor
 * on `(created_at, id)`. Staff only; guarded by `EnsureStaff` on the group.
 */
class TenantIndexController extends Controller
{
    public function __invoke(Request $request, TenantDirectory $directory): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'plan' => ['nullable', Rule::enum(Plan::class)],
            'payment_status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'region' => ['nullable', 'string', 'max:64'],
        ]);

        $filters = [
            'q' => $validated['q'] ?? null,
            'plan' => $validated['plan'] ?? null,
            'payment_status' => $validated['payment_status'] ?? null,
            'region' => $validated['region'] ?? null,
            'provisioning_failed' => $request->boolean('provisioning_failed'),
            'past_due' => $request->boolean('past_due'),
            'cancelling' => $request->boolean('cancelling'),
            'deleted' => $request->boolean('deleted'),
        ];

        $page = $directory->paginate($filters);

        return Inertia::render('ops/tenants/index', [
            'tenants' => [
                'data' => collect($page->items())
                    ->map(fn (Team $team): array => $directory->row($team))
                    ->values()
                    ->all(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'prev_cursor' => $page->previousCursor()?->encode(),
            ],
            'status' => session()->get('status'),
            'filters' => $filters,
            'options' => [
                'plans' => array_map(fn (Plan $plan): string => $plan->value, Plan::cases()),
                'payment_statuses' => array_map(fn (PaymentStatus $status): string => $status->value, PaymentStatus::cases()),
                'regions' => $directory->regions(),
            ],
        ]);
    }
}
