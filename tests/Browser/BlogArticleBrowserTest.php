<?php

/**
 * Every published post opens in a real browser without a JavaScript error.
 *
 * BlogPermalinksTest and BlogFeatureStoriesTest cover the HTTP contract, and
 * BlogFeatureStoriesTest derives the post list from the files. This is the check
 * that the rendered body actually reaches a reader — a sentence and the FAQ
 * heading per post — which a prop assertion cannot see.
 *
 * The phrases are written out by hand on purpose: deriving them from the file
 * would only assert the file against itself.
 */
dataset('blog articles', [
    'stop-ai-tools-contradicting-each-other' => [
        'stop-ai-tools-contradicting-each-other',
        'Stopping Claude and Cursor contradicting each other',
        'The team is aligned. The tools are not.',
    ],
    'finding-what-your-ai-already-made' => [
        'finding-what-your-ai-already-made',
        'Finding the report your team already wrote',
        'the unit of memory is the tool',
    ],
    'who-sees-what-your-ai-makes' => [
        'who-sees-what-your-ai-makes',
        'Who sees what your AI makes, and when',
        'turn public links off entirely',
    ],
    'one-link-that-keeps-its-history' => [
        'one-link-that-keeps-its-history',
        'One link that keeps a version history',
        'Nearly identical is not identical',
    ],
    'artfct-vs-a-shared-drive-folder' => [
        'artfct-vs-a-shared-drive-folder',
        'Artfct vs a shared drive: what works better',
        'A shared drive is organized for human eyes',
    ],
    'artfct-vs-your-ai-tools-own-sharing' => [
        'artfct-vs-your-ai-tools-own-sharing',
        "Artfct vs your AI tool's own sharing",
        'The boundary is the vendor',
    ],
    'why-your-ai-tools-cannot-see-each-others-work' => [
        'why-your-ai-tools-cannot-see-each-others-work',
        "Why your AI tools cannot see each other's work",
        'The controls stop at the edge of the product',
    ],
    'what-is-an-ai-artifact' => [
        'what-is-an-ai-artifact',
        'What is an AI artifact, and what is not?',
        'It is the output, not the conversation that produced it',
    ],
]);

test('each blog article renders without javascript errors', function (string $slug, string $title, string $body) {
    visit(route('blog.show', ['slug' => $slug]))
        ->assertSee($title)
        ->assertSee($body)
        ->assertSee('Frequently asked questions')
        ->assertNoJavaScriptErrors();
})->with('blog articles');

test('the one post whose file carries figures renders both, and only it', function () {
    visit(route('blog.show', ['slug' => 'stop-ai-tools-contradicting-each-other']))
        ->assertPresent('figure[aria-labelledby="fig1-cap"]')
        ->assertPresent('figure[aria-labelledby="fig2-cap"]')
        ->assertNoJavaScriptErrors();

    visit(route('blog.show', ['slug' => 'what-is-an-ai-artifact']))
        ->assertMissing('figure[aria-labelledby="fig1-cap"]')
        ->assertNoJavaScriptErrors();
});
