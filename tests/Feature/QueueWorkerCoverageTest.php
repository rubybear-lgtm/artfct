<?php

use Illuminate\Support\Facades\File;

/**
 * RUB-396: `ProcessWorkerEvent` dispatched to `events` while the deployed
 * worker consumed `indexing,default`, so every `artifact.created` and
 * `artifact.viewed` event sat in `jobs` for two days — no audit row, no usage
 * signal, and nothing reporting the backlog.
 *
 * The worker's queue list is deploy config, not application code, so a
 * dispatch site and the worker can disagree without either one failing. This
 * asserts the agreement from both ends: the queue names the application
 * dispatches to are read out of the source, and the worker queue lists are
 * read out of the files that carry them. Adding a queue, or dropping one from
 * a worker's list, fails here instead of silently on staging.
 */
test('every_worker_queue_list_covers_every_queue_the_application_dispatches_to', function () {
    $dispatched = dispatchedQueueNames();

    // The scan is the half of this test that can quietly shrink; assert the
    // set it finds, so a scan that stops matching fails instead of leaving the
    // coverage check below passing over an empty set.
    expect($dispatched)->toBe(['events', 'indexing']);

    // Where a job with no `onQueue()`, attribute or property lands. Read from
    // the `database` connection explicitly: the test env queues `sync`
    // (phpunit.xml) while every deployed worker consumes `database`, and
    // `DB_QUEUE` would rename this fallback out from under a worker list that
    // says `default`.
    $fallback = config('queue.connections.database.queue');
    expect($fallback)->toBeString()->not->toBe('', 'The database connection has no default queue name, so a worker list cannot be checked against it.');

    $queues = [...$dispatched, $fallback];

    // Scanned rather than named: a new Railway service config or worker script
    // must not be born with a drifted list that this test never looks at.
    $lists = [];
    foreach ([...glob(base_path('railway*.json')), ...glob(base_path('scripts/*.sh'))] as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        foreach (workerQueueListsIn((string) File::get($path)) as $list) {
            $lists[$relative][] = $list;
        }
    }

    // Guard the other half: if the worker commands move or change shape, this
    // test must fail rather than find no lists and assert nothing.
    expect($lists)->toHaveKeys(['railway.queue.json', 'scripts/mcp-e2e-stack.sh']);

    foreach ($lists as $file => $fileLists) {
        foreach ($fileLists as $list) {
            $missing = array_values(array_diff($queues, $list));

            expect($missing)->toBe([], $file.' runs a worker on ['.implode(', ', $list).'] but the application dispatches to ['.implode(', ', $queues).']; '.implode(', ', $missing).' would sit unconsumed.');
        }
    }
});

/**
 * The literal queue names written anywhere under `app/`, in every form a job
 * can take: an `onQueue('x')` call (dispatch site or constructor), a
 * `#[Queue('x')]` class attribute, or a declared `$queue = 'x'` property. The
 * attribute outranks the property at dispatch time, so both are real.
 *
 * A computed name is an error, not a skip: the coverage assertion can only
 * cover names it can read, and ignoring a dynamic one would recreate the blind
 * spot this test exists to close.
 *
 * @return array<int, string>
 */
function dispatchedQueueNames(): array
{
    $forms = [
        'onQueue()' => '/onQueue\(\s*([^)]*?)\s*\)/',
        '#[Queue()]' => '/#\[Queue\(\s*([^)]*?)\s*\)\]/',
        '$queue property' => '/(?:public|protected|private)\s+(?:string\s+)?\$queue\s*=\s*([^;]+);/',
    ];

    $names = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        foreach ($forms as $form => $pattern) {
            preg_match_all($pattern, $source, $matches);

            foreach ($matches[1] as $argument) {
                if (! preg_match("/^'([^']+)'$/", trim($argument), $literal)) {
                    throw new RuntimeException(
                        "Cannot read the queue name written as {$form} in {$file->getPathname()} (".trim($argument).'): '
                        .'this test asserts the worker covers literal queue names, so a computed one has to be made readable or read here.'
                    );
                }

                $names[] = $literal[1];
            }
        }
    }

    $names = array_values(array_unique($names));
    sort($names);

    return $names;
}

/**
 * Every `--queue=` list in a file, one per `queue:work` invocation — the list
 * the worker actually runs, rather than a copy of it that can drift.
 *
 * @return array<int, array<int, string>>
 */
function workerQueueListsIn(string $source): array
{
    preg_match_all('/queue:work\b[^\n]*?--queue=([A-Za-z0-9_,-]+)/', $source, $matches);

    return array_map(fn (string $list): array => explode(',', $list), $matches[1]);
}
