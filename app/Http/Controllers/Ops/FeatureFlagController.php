<?php

namespace App\Http\Controllers\Ops;

use App\Enums\AuditEventType;
use App\Enums\FeatureReleaseState;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateFeatureReleaseRequest;
use App\Models\OperatorAuditEvent;
use App\Services\FeatureFlags;
use App\Services\Operator\Actions\UpdateFeatureRelease;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FeatureFlagController extends Controller
{
    public function index(FeatureFlags $flags): Response
    {
        return Inertia::render('ops/features/index', [
            'environment' => $flags->environment(),
            'releases' => collect($flags->registry())->map(function (array $definition, string $key) use ($flags): array {
                $release = $flags->release($key);

                return ['key' => $key, ...$definition, 'state' => ($release->state ?? FeatureReleaseState::Off)->label()];
            })->values()->all(),
        ]);
    }

    public function show(string $feature, FeatureFlags $flags): Response
    {
        abort_unless($flags->registered($feature), 404);
        $release = $flags->release($feature);

        return Inertia::render('ops/features/show', [
            'environment' => $flags->environment(),
            'feature' => ['key' => $feature, ...$flags->registry()[$feature]],
            'state' => ($release->state ?? FeatureReleaseState::Off)->value,
            'team_slugs' => $flags->selectedTeams($release)->pluck('slug')->all(),
            'history' => OperatorAuditEvent::query()
                ->where('event_type', AuditEventType::OperatorFeatureReleaseChanged)
                ->where('target', $flags->environment().':feature:'.$feature)
                ->with('staff:id,name')->latest('id')->limit(30)->get()
                ->map(fn (OperatorAuditEvent $event): array => [
                    'id' => $event->id,
                    'staff' => $event->staff->name ?? 'Former staff member',
                    'reason' => $event->reason,
                    'created_at' => $event->created_at->toIso8601String(),
                ])->all(),
            'status' => session('status'),
        ]);
    }

    public function update(UpdateFeatureReleaseRequest $request, string $feature, UpdateFeatureRelease $update): RedirectResponse
    {
        $validated = $request->validated();
        $update->run($request, $feature, FeatureReleaseState::from($validated['state']), $validated['team_slugs'], $validated['reason']);

        return redirect()->route('ops.features.show', $feature)->with('status', 'Feature release updated.');
    }
}
