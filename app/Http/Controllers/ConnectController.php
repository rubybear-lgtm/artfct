<?php

namespace App\Http\Controllers;

use App\Support\AiToolSetup;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The /connect hub and one page per connectable AI tool (spec 12's
 * programmatic surface, redesigned in RUB-502). Both read AiToolSetup, the
 * same source as the docs page and /llms-full.txt, so a setup step or the
 * setup prompt cannot drift between them.
 */
class ConnectController extends Controller
{
    public function index(): Response
    {
        $setup = AiToolSetup::forCurrentEnvironment();

        return Inertia::render('connect-index', [
            'meta' => [
                'title' => 'Agent setup: connect Claude, Cursor, Codex and ChatGPT to Artfct',
                'description' => 'Connect every AI agent your team uses to the same Artfct workspace, so what one agent publishes the others can search, read and cite. One prompt, or step-by-step guides.',
            ],
            'guides' => array_map($this->card(...), $setup->guides()),
            'prompt' => $setup->agentSetupPrompt(),
            'quickInstall' => $setup->quickInstall(),
        ]);
    }

    public function show(string $tool): Response
    {
        $setup = AiToolSetup::forCurrentEnvironment();
        $guides = collect($setup->guides());
        $guide = $guides->firstWhere('id', $tool);

        abort_if($guide === null, 404);

        return Inertia::render('connect-show', [
            'meta' => [
                'title' => "Connect {$guide['label']} to Artfct",
                'description' => "Add Artfct to {$guide['label']} so it can publish to your team's workspace and search what your other AI tools made.",
            ],
            'guide' => $guide,
            'mcpUrl' => $setup->mcpUrl(),
            'quickInstall' => $setup->quickInstall(),
            'prompt' => $setup->agentSetupPrompt(),
            'related' => $guides
                ->reject(fn (array $candidate): bool => $candidate['id'] === $tool)
                ->map($this->card(...))
                ->values()
                ->all(),
            'troubleshooting' => $this->troubleshootingFor($setup, $guide['label']),
        ]);
    }

    /**
     * What the hub and the "Other agents" list need from a guide.
     *
     * @param  array<string, mixed>  $guide
     * @return array{id: string, label: string, kind: string, method: string, summary: string, maker: ?string, icon: ?string}
     */
    private function card(array $guide): array
    {
        return [
            'id' => $guide['id'],
            'label' => $guide['label'],
            'kind' => $guide['kind'],
            'method' => $guide['method'],
            'summary' => $guide['summary'],
            'maker' => $guide['maker'] ?? null,
            'icon' => $guide['icon'] ?? null,
        ];
    }

    /**
     * The troubleshooting entries, each narrowed to this tool's sentence when
     * the note names tools ("Codex: run ...").
     *
     * @return list<array{name: string, type: string, note: string}>
     */
    private function troubleshootingFor(AiToolSetup $setup, string $label): array
    {
        return array_map(function (array $item) use ($label): array {
            $mine = array_values(array_filter(
                preg_split('/(?<=\.)\s+/', $item['note']) ?: [],
                fn (string $sentence): bool => str_starts_with($sentence, "{$label}:"),
            ));

            return $mine === [] ? $item : [...$item, 'note' => implode(' ', $mine)];
        }, $setup->troubleshooting());
    }
}
