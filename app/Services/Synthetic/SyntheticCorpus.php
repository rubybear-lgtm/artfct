<?php

namespace App\Services\Synthetic;

/**
 * Deterministic artifact corpus for the synthetic org (RUB-325). Pure: the
 * same `(seed, count)` always yields the same specs, and file bytes are
 * produced lazily by `SyntheticFile::content()` so the ceiling-sized bundles
 * are never held in memory until deployed. Artifact ids are assigned by the
 * Worker (content-addressed), so specs are addressed by a stable `key`.
 *
 * @phpstan-type FileSpec array{path: string, content_type: string, content: string|null, size: int, seed: string}
 * @phpstan-type Spec array{key: string, title: string, description: string, kind: string, tier: string, files: list<FileSpec>, provenance: array<string, mixed>, backdate_days: int|null, state: string|null, supersedes: string|null}
 */
final class SyntheticCorpus
{
    public const AGENTS = ['claude-code', 'cursor', 'codex'];

    public const REPOS = [
        'github.com/northwind/billing',
        'github.com/northwind/analytics-web',
        'github.com/northwind/ops-runbooks',
        'github.com/northwind/data-platform',
        'github.com/northwind/growth',
    ];

    /**
     * Named artifacts the golden queries point at; always present.
     *
     * @var list<array{key: string, title: string, kind: string, repo: int, agent: int, backdate_days: int}>
     */
    private const NAMED = [
        ['key' => 'billing-dashboard-aug', 'title' => 'Billing dashboard — August 2026', 'kind' => 'react', 'repo' => 0, 'agent' => 0, 'backdate_days' => 30],
        ['key' => 'billing-dashboard-jul', 'title' => 'Billing dashboard — July 2026', 'kind' => 'react', 'repo' => 0, 'agent' => 0, 'backdate_days' => 62],
        ['key' => 'oncall-handoff-runbook', 'title' => 'On-call handoff runbook', 'kind' => 'report', 'repo' => 2, 'agent' => 1, 'backdate_days' => 45],
        ['key' => 'churn-cohort-analysis', 'title' => 'Churn cohort analysis Q2', 'kind' => 'vue', 'repo' => 1, 'agent' => 2, 'backdate_days' => 90],
        ['key' => 'pipeline-latency-report', 'title' => 'Data pipeline latency report', 'kind' => 'report', 'repo' => 3, 'agent' => 0, 'backdate_days' => 20],
        ['key' => 'signup-funnel-dashboard', 'title' => 'Signup funnel dashboard', 'kind' => 'react', 'repo' => 4, 'agent' => 1, 'backdate_days' => 15],
        ['key' => 'incident-2026-07-postmortem', 'title' => 'Incident postmortem — July checkout outage', 'kind' => 'report', 'repo' => 2, 'agent' => 2, 'backdate_days' => 70],
        ['key' => 'pricing-experiment-results', 'title' => 'Pricing experiment results', 'kind' => 'bundle', 'repo' => 4, 'agent' => 0, 'backdate_days' => 40],
        ['key' => 'invoice-reconciliation-tool', 'title' => 'Invoice reconciliation tool', 'kind' => 'bundle', 'repo' => 0, 'agent' => 1, 'backdate_days' => 120],
        ['key' => 'api-latency-heatmap', 'title' => 'API latency heatmap', 'kind' => 'vue', 'repo' => 1, 'agent' => 2, 'backdate_days' => 10],
        ['key' => 'quarterly-board-deck', 'title' => 'Quarterly board deck Q3', 'kind' => 'bundle', 'repo' => 4, 'agent' => 0, 'backdate_days' => 5],
        ['key' => 'schema-migration-plan', 'title' => 'Schema migration plan', 'kind' => 'report', 'repo' => 3, 'agent' => 1, 'backdate_days' => 200],
        ['key' => 'customer-health-scorecard', 'title' => 'Customer health scorecard', 'kind' => 'react', 'repo' => 1, 'agent' => 0, 'backdate_days' => 25],
        ['key' => 'runbook-database-failover', 'title' => 'Runbook: database failover', 'kind' => 'report', 'repo' => 2, 'agent' => 2, 'backdate_days' => 300],
        ['key' => 'onboarding-checklist', 'title' => 'New engineer onboarding checklist', 'kind' => 'report', 'repo' => 2, 'agent' => 1, 'backdate_days' => 150],
    ];

    /**
     * @return list<Spec>
     */
    public static function generate(int $seed, int $count, int $bundleCeilingBytes): array
    {
        mt_srand($seed);
        $specs = [];

        foreach (self::NAMED as $named) {
            $specs[] = self::spec($named['key'], $named['title'], $named['kind'], $named['repo'], $named['agent'], $named['backdate_days'], $seed);
        }

        // Lifecycle and edge-case artifacts.
        $specs[] = self::withState(self::spec('state-revoked', 'Revoked prototype', 'report', 1, 1, 100, $seed), 'revoked');
        $specs[] = self::withState(self::spec('state-legal-hold', 'Contract review under legal hold', 'report', 0, 0, 400, $seed), 'legal_hold');
        $specs[] = self::withState(self::spec('state-expired', 'Expired preview', 'report', 3, 2, 60, $seed), 'expired');

        // Version lineage: v2 supersedes v1.
        $specs[] = self::spec('lineage-v1', 'Capacity plan v1', 'report', 3, 0, 180, $seed);
        $lineage = self::spec('lineage-v2', 'Capacity plan v2', 'report', 3, 0, 35, $seed);
        $lineage['supersedes'] = 'lineage-v1';
        $specs[] = $lineage;

        // Byte-identical content across two artifacts (dedupe / refcount).
        $shared = 'shared-vendor-bundle';
        $specs[] = self::withSharedFile(self::spec('dup-a', 'Metrics explorer (copy A)', 'bundle', 3, 0, 50, $seed), $shared);
        $specs[] = self::withSharedFile(self::spec('dup-b', 'Metrics explorer (copy B)', 'bundle', 3, 1, 51, $seed), $shared);

        // One bundle just under the per-artifact ceiling and one just over.
        $specs[] = self::sized('bundle-just-under', 'Large data export (just under ceiling)', $bundleCeilingBytes - 1024, $seed);

        // Fill with generated artifacts up to `$count` (named/edge ones included).
        $kinds = ['report', 'react', 'vue', 'bundle'];
        $topics = ['Revenue', 'Latency', 'Retention', 'Cost', 'Adoption', 'Errors', 'Throughput', 'Backlog'];
        $index = 1;
        while (count($specs) < $count) {
            $kind = $kinds[$index % 4];
            $topic = $topics[$index % 8];
            $spec = self::spec(
                sprintf('gen-%03d', $index),
                sprintf('%s overview %d', $topic, $index),
                $kind,
                $index % 5,
                $index % 3,
                self::backdate($index),
                $seed,
            );
            // A few generated artifacts carry no repo at all.
            if ($index % 11 === 0) {
                $spec['provenance'] = ['agent' => self::AGENTS[$index % 3]];
            }
            $specs[] = $spec;
            $index++;
        }

        // Refused by the quota gate, so appended after the fill: `$count` artifacts deploy.
        $specs[] = self::sized('bundle-just-over', 'Large data export (over ceiling)', $bundleCeilingBytes + 1024, $seed);

        return $specs;
    }

    /**
     * @return list<Spec>
     */
    public static function generateOrgB(int $seed, int $count): array
    {
        $specs = [];
        $overlap = ['Billing dashboard — August 2026', 'On-call handoff runbook', 'Churn cohort analysis Q2', 'Pipeline latency report'];
        for ($i = 0; $i < $count; $i++) {
            $title = $overlap[$i % 4].($i >= 4 ? ' (Contoso '.$i.')' : ' (Contoso)');
            $specs[] = self::spec(sprintf('b-%03d', $i + 1), $title, ['report', 'react'][$i % 2], $i % 5, $i % 3, 10 + $i, $seed + 1000);
        }

        return $specs;
    }

    /**
     * @return Spec
     */
    private static function spec(string $key, string $title, string $kind, int $repo, int $agent, int $backdateDays, int $seed): array
    {
        $files = match ($kind) {
            'react' => [
                self::file('index.html', 'text/html', '<!doctype html><html><head><title>'.$title.'</title></head><body><div id="root"></div><script src="app.js"></script></body></html>'),
                self::generated('app.js', 'application/javascript', 2048, $key.$seed),
            ],
            'vue' => [
                self::file('index.html', 'text/html', '<!doctype html><html><head><title>'.$title.'</title></head><body><div id="app"></div><script src="main.js"></script></body></html>'),
                self::generated('main.js', 'application/javascript', 2048, $key.$seed),
            ],
            'bundle' => [
                self::file('index.html', 'text/html', '<!doctype html><html><head><title>'.$title.'</title><link rel="stylesheet" href="assets/site.css"></head><body><h1>'.$title.'</h1><p>'.self::paragraph($key.$seed).'</p></body></html>'),
                self::generated('assets/site.css', 'text/css', 1024, $key.$seed),
                self::generated('data/nested/series.json', 'application/json', 1024, $key.$seed),
            ],
            default => [
                self::file('index.html', 'text/html', '<!doctype html><html><head><title>'.$title.'</title></head><body><h1>'.$title.'</h1><p>'.self::paragraph($key.$seed).'</p><p>'.self::paragraph($key.'2'.$seed).'</p></body></html>'),
            ],
        };

        $provenance = [
            'agent' => self::AGENTS[$agent % 3],
            'repo_url' => self::REPOS[$repo % 5],
            'branch' => ['main', 'develop', 'feature/reports', 'fix/latency'][($repo + $agent) % 4],
            'commit_sha' => substr(hash('sha256', $key.$seed), 0, 40),
            'dirty' => ($repo + $agent) % 4 === 0,
        ];

        return [
            'key' => $key,
            'title' => $title,
            'description' => 'Synthetic '.$kind.' artifact for testing.',
            'kind' => $kind,
            'tier' => 'secure',
            'files' => $files,
            'provenance' => $provenance,
            'backdate_days' => $backdateDays,
            'state' => null,
            'supersedes' => null,
        ];
    }

    /**
     * @param  Spec  $spec
     * @return Spec
     */
    private static function withState(array $spec, string $state): array
    {
        $spec['state'] = $state;

        return $spec;
    }

    /**
     * @param  Spec  $spec
     * @return Spec
     */
    private static function withSharedFile(array $spec, string $sharedSeed): array
    {
        $spec['files'][] = self::generated('vendor/shared.js', 'application/javascript', 4096, $sharedSeed);

        return $spec;
    }

    /**
     * A single-purpose bundle of `$totalBytes`, split into ≤1 MiB files.
     *
     * @return Spec
     */
    private static function sized(string $key, string $title, int $totalBytes, int $seed): array
    {
        $spec = self::spec($key, $title, 'report', 3, 0, 8, $seed);
        $spec['files'] = [self::file('index.html', 'text/html', '<!doctype html><html><body><h1>'.$title.'</h1></body></html>')];
        $remaining = $totalBytes - $spec['files'][0]['size'];
        $part = 0;
        while ($remaining > 0) {
            $size = min($remaining, 1024 * 1024);
            $spec['files'][] = self::generated(sprintf('data/part-%03d.bin', $part++), 'application/octet-stream', $size, $key.$seed);
            $remaining -= $size;
        }
        $spec['kind'] = 'bundle';

        return $spec;
    }

    /**
     * @return array{path: string, content_type: string, content: string|null, size: int, seed: string}
     */
    private static function file(string $path, string $contentType, string $content): array
    {
        return ['path' => $path, 'content_type' => $contentType, 'content' => $content, 'size' => strlen($content), 'seed' => ''];
    }

    /**
     * @return array{path: string, content_type: string, content: string|null, size: int, seed: string}
     */
    private static function generated(string $path, string $contentType, int $size, string $seed): array
    {
        return ['path' => $path, 'content_type' => $contentType, 'content' => null, 'size' => $size, 'seed' => $seed];
    }

    private static function backdate(int $index): int
    {
        return ($index * 37) % 360 + 1;
    }

    private static function paragraph(string $seed): string
    {
        $words = ['revenue', 'latency', 'cohort', 'pipeline', 'incident', 'runbook', 'forecast', 'invoice', 'churn', 'deploy', 'metric', 'quarter'];
        $hash = crc32($seed);
        $out = [];
        for ($i = 0; $i < 28; $i++) {
            $out[] = $words[($hash >> ($i % 16)) % 12];
            $hash = ($hash * 1103515245 + 12345) & 0x7FFFFFFF;
        }

        return ucfirst(implode(' ', $out)).'.';
    }
}
