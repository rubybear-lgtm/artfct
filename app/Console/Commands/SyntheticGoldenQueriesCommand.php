<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Search\SearchResult;
use App\Services\Search\SearchService;
use App\Services\Synthetic\SyntheticSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('synthetic:golden-queries {org=zz-northwind} {--top=10 : How many results count as a hit} {--fixture= : Alternative fixture path}')]
#[Description('Runs the golden relevance queries against a seeded synthetic org and reports which expected artifacts were found')]
class SyntheticGoldenQueriesCommand extends Command
{
    public function handle(SearchService $search): int
    {
        $slug = (string) $this->argument('org');
        $top = max(1, (int) $this->option('top'));
        $fixturePath = (string) ($this->option('fixture') ?: base_path('tests/Fixtures/synthetic/queries.json'));

        try {
            SyntheticSeeder::assertSafe($slug);
            $team = Team::query()->where('slug', $slug)->firstOrFail();
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        // Search reaches the Worker through the directory, which mints its
        // credential for the signed-in user, so act as the team's first admin.
        $admin = $team->firstAdmin();
        if ($admin === null) {
            $this->components->error("Team \"{$slug}\" has no admin to act as.");

            return self::FAILURE;
        }
        auth()->setUser($admin);

        $mapPath = SyntheticSeeder::mapPath($slug);
        if (! File::exists($mapPath) || ! File::exists($fixturePath)) {
            $this->components->error('Missing the seed map or the fixture; run synthetic:seed first.');

            return self::FAILURE;
        }

        /** @var array<string, string> $idsByKey */
        $idsByKey = json_decode(File::get($mapPath), true) ?: [];
        /** @var list<array{query: string, expected_keys: list<string>, agent?: ?string, repo_url?: ?string, collection?: ?string}> $queries */
        $queries = json_decode(File::get($fixturePath), true)['queries'] ?? [];

        $rows = [];
        $passed = 0;
        foreach ($queries as $entry) {
            $resultIds = array_map(
                fn (SearchResult $result): string => $result->id,
                $search->search($team, $entry['query'], array_filter([
                    'agent' => $entry['agent'] ?? null,
                    'repo' => $entry['repo_url'] ?? null,
                    'collection' => $entry['collection'] ?? null,
                ], fn (?string $value): bool => $value !== null), $top, actor: 'golden-queries'),
            );
            $missing = array_values(array_filter(
                $entry['expected_keys'],
                fn (string $key): bool => ! isset($idsByKey[$key]) || ! in_array($idsByKey[$key], $resultIds, true),
            ));
            $passed += $missing === [] ? 1 : 0;
            $rows[] = [$missing === [] ? 'pass' : 'FAIL', $entry['query'], implode(', ', $missing)];
        }

        $this->table(['Result', 'Query', 'Missing from top '.$top], $rows);
        $this->components->info(sprintf('%d of %d golden queries returned every expected artifact in the top %d.', $passed, count($queries), $top));

        return $passed === count($queries) ? self::SUCCESS : self::FAILURE;
    }
}
