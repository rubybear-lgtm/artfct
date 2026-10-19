<?php

namespace App\Services\Synthetic;

use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Normalizer;

/** Frozen input contract; evaluation answers never enter publication payloads. */
final class SearchEvaluationDataset
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly array $data)
    {
        Validator::make($data, [
            'version' => ['required', 'string'],
            'seed' => ['required', 'integer'],
            'provenance' => ['required', 'array'],
            'sources' => ['required', 'array', 'min:20'],
            'sources.*.key' => ['required', 'string', 'distinct', 'regex:/^[a-z0-9-]+$/'],
            'sources.*.topic' => ['required', 'string'],
            'sources.*.split' => ['required', 'in:tuning,held_out'],
            'sources.*.title' => ['required', 'string', 'max:200'],
            'sources.*.description' => ['required', 'string', 'max:1000'],
            'sources.*.collections' => ['present', 'array'],
            'sources.*.collections.*' => ['required', 'string', 'max:100', 'starts_with:zz-'],
            'sources.*.sections' => ['required', 'array', 'min:2'],
            'sources.*.sections.*.locator' => ['required', 'string', 'regex:/^[a-z0-9-]+$/'],
            'sources.*.sections.*.heading' => ['required', 'string'],
            'sources.*.sections.*.text' => ['required', 'string'],
            'sources.*.presentation' => ['required', 'in:article,boilerplate,rendered,bundle,long'],
            'cases' => ['required', 'array', 'min:60'],
            'cases.*.id' => ['required', 'string', 'distinct'],
            'cases.*.query' => ['required', 'string'],
            'cases.*.class' => ['required', 'string'],
            'cases.*.split' => ['required', 'in:tuning,held_out,regression'],
            'cases.*.review' => ['required', 'string'],
            'cases.*.reference_answer' => ['present', 'string'],
            'cases.*.no_answer' => ['required', 'boolean'],
            'cases.*.filters' => ['present', 'array:collection,agent,repo,since'],
            'cases.*.relevance' => ['present', 'array'],
            'cases.*.gold' => ['present', 'array'],
            'cases.*.gold.*.source' => ['required', 'string'],
            'cases.*.gold.*.locator' => ['required', 'string'],
            'cases.*.gold.*.text' => ['required', 'string'],
        ])->validate();

        $sources = array_column($data['sources'], null, 'key');
        $topics = [];
        foreach ($sources as $source) {
            if (isset($topics[$source['topic']]) && $topics[$source['topic']] !== $source['split']) {
                throw new InvalidArgumentException('A topic crosses tuning and held-out splits.');
            }
            $topics[$source['topic']] = $source['split'];
            $locators = array_column($source['sections'], 'locator');
            if (count($locators) !== count(array_unique($locators))) {
                throw new InvalidArgumentException('Duplicate source locator.');
            }
        }
        $payloads = array_map(fn (array $source): string => self::normalize(json_encode($this->publication($source), JSON_THROW_ON_ERROR)), $sources);
        $negativeCount = 0;
        foreach ($data['cases'] as $case) {
            $negativeCount += $case['no_answer'] ? 1 : 0;
            if ($case['no_answer'] !== (array_filter($case['relevance'], fn (mixed $grade): bool => is_int($grade) && $grade > 0) === []) || ($case['no_answer'] && ($case['gold'] !== [] || $case['reference_answer'] !== ''))) {
                throw new InvalidArgumentException('No-answer cases must have no relevant sources, answers or gold spans.');
            }
            foreach ($case['relevance'] as $key => $grade) {
                if (! isset($sources[$key]) || ! in_array($grade, [0, 1, 2], true)) {
                    throw new InvalidArgumentException('Unknown source or invalid relevance grade.');
                }
                if ($grade > 0 && $case['split'] !== 'regression' && $sources[$key]['split'] !== $case['split']) {
                    throw new InvalidArgumentException('Relevant source crosses the frozen split.');
                }
                if ($grade > 0 && isset($case['filters']['collection']) && ! in_array($case['filters']['collection'], $sources[$key]['collections'], true)) {
                    throw new InvalidArgumentException('Relevant source is outside the collection.');
                }
                if ($grade > 0 && ! in_array($key, array_column($case['gold'], 'source'), true)) {
                    throw new InvalidArgumentException('Relevant source has no gold evidence.');
                }
            }
            foreach ($case['gold'] as $span) {
                $sections = array_column($sources[$span['source']]['sections'] ?? [], null, 'locator');
                $section = $sections[$span['locator']] ?? null;
                if (($case['relevance'][$span['source']] ?? 0) < 1 || $section === null || ! str_contains(self::normalize($section['text']), self::normalize($span['text']))) {
                    throw new InvalidArgumentException('Gold evidence does not occur at its source locator.');
                }
            }
            foreach ($payloads as $payload) {
                if ((str_ends_with($case['query'], '?') && str_contains($payload, self::normalize($case['query']))) || ($case['reference_answer'] !== '' && str_contains($payload, self::normalize($case['reference_answer'])))) {
                    // Exact-title queries legitimately repeat source titles; body/metadata must not contain evaluation-only answers.
                    if ($case['class'] !== 'exact_title' || ($case['reference_answer'] !== '' && str_contains($payload, self::normalize($case['reference_answer'])))) {
                        throw new InvalidArgumentException('Evaluation question or reference answer injected into publication.');
                    }
                }
            }
        }
        if ($negativeCount < 10) {
            throw new InvalidArgumentException('At least ten no-answer cases are required.');
        }
        $heldOut = count(array_filter($sources, fn (array $source): bool => $source['split'] === 'held_out'));
        if ($heldOut / count($sources) < 0.25 || $heldOut / count($sources) > 0.35) {
            throw new InvalidArgumentException('Freeze approximately 70/30 source membership.');
        }
    }

    public static function load(string $path): self
    {
        return new self(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function normalize(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_C);
        }

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public function publication(array $source): array
    {
        $escape = fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $body = '<h1>'.$escape($source['title']).'</h1>';
        foreach ($source['sections'] as $section) {
            $body .= '<section id="'.$escape($section['locator']).'"><h2>'.$escape($section['heading']).'</h2><p>'.$escape($section['text']).'</p></section>';
        }
        $title = $escape($source['title']);
        $head = '<!doctype html><html><head><meta charset="utf-8"><title>'.$title.'</title></head><body>';
        $html = $head.$body.'</body></html>';
        if ($source['presentation'] === 'rendered') {
            return [
                'title' => $source['title'], 'description' => $source['description'], 'sharing' => 'team', 'model' => 'rub-442-frozen-corpus-v1',
                'entrypoint' => 'index.html', 'files' => [
                    ['path' => 'index.html', 'content' => $head.'<div id="root"></div><script src="app.js"></script></body></html>'],
                    ['path' => 'app.js', 'content' => 'document.getElementById("root").innerHTML='.json_encode($body, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR).';'],
                ],
            ];
        }
        if ($source['presentation'] === 'boilerplate') {
            $html = $head.'<nav>Home Reports Contact Subscribe</nav>'.$body.'<footer>our AI makes, remembered. Subscribe to updates. Start your free trial.</footer><script type="application/json">{"state":"ready","noise":"footer\\nSubscribe"}</script></body></html>';
        }
        $payload = ['title' => $source['title'], 'description' => $source['description'], 'sharing' => 'team', 'model' => 'rub-442-frozen-corpus-v1'];
        if ($source['presentation'] === 'bundle') {
            $files = [['path' => 'index.html', 'content' => $html]];
            foreach (array_chunk($source['sections'], (int) ceil(count($source['sections']) / 2)) as $index => $sections) {
                $chapter = '';
                foreach ($sections as $section) {
                    $chapter .= '<section id="'.$escape($section['locator']).'"><h2>'.$escape($section['heading']).'</h2><p>'.$escape($section['text']).'</p></section>';
                }
                $files[] = ['path' => 'chapter-'.($index + 1).'.html', 'content' => $head.$chapter.'</body></html>'];
            }

            return $payload + ['entrypoint' => 'index.html', 'files' => $files];
        }

        return $payload + ['html' => $html];
    }

    /**
     * Provider-neutral SDG Hub import; all imported cases require explicit review before freezing.
     *
     * @param  array<string, mixed>  $row
     * @param  array{provider: string, model: string, prompt_version: string, generation_config: array<string, mixed>, seed: int|null}  $provenance
     * @return array<string, mixed>
     */
    public function import(array $row, string $sourceKey, string $locator, string $id, string $review, array $provenance): array
    {
        Validator::make($row, ['question' => ['required', 'string'], 'answer' => ['required', 'string'], 'ground_truth_context' => ['required', 'string']])->validate();
        Validator::make($provenance, ['provider' => ['required', 'string'], 'model' => ['required', 'string'], 'prompt_version' => ['required', 'string'], 'generation_config' => ['present', 'array'], 'seed' => ['present', 'nullable', 'integer']])->validate();
        $source = null;
        $section = null;
        foreach ($this->data['sources'] as $candidate) {
            if ($candidate['key'] === $sourceKey) {
                $source = $candidate;
                foreach ($candidate['sections'] as $candidateSection) {
                    if ($candidateSection['locator'] === $locator) {
                        $section = $candidateSection;
                    }
                }
                break;
            }
        }
        if ($source === null || $section === null || trim($review) === '' || ! str_contains(self::normalize($section['text']), self::normalize($row['ground_truth_context']))) {
            throw new InvalidArgumentException('Imported context must be grounded in the specified source span and reviewed.');
        }

        return ['id' => $id, 'query' => $row['question'], 'class' => 'paraphrase', 'split' => $source['split'], 'review' => $review, 'reference_answer' => $row['answer'], 'no_answer' => false, 'filters' => [], 'relevance' => [$sourceKey => 2], 'gold' => [['source' => $sourceKey, 'locator' => $locator, 'text' => $row['ground_truth_context']]], 'generator' => $provenance];
    }
}
