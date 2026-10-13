<?php

/**
 * Every post in $blogPosts opens in a real browser without a JavaScript error.
 *
 * BlogPermalinksTest and BlogFeatureStoriesTest cover the HTTP contract; this is
 * the check that the generated body actually renders, which is the part a prop
 * assertion cannot see.
 */
dataset('blog articles', [
    'stop-ai-tools-contradicting-each-other' => [
        'stop-ai-tools-contradicting-each-other',
        'Stopping Claude and Cursor from contradicting each other',
        'The team is aligned. The tools are not.',
    ],
    'finding-what-your-ai-already-made' => [
        'finding-what-your-ai-already-made',
        'Finding the report your team already wrote',
        'the unit of memory is the tool',
    ],
    'who-sees-what-your-ai-makes' => [
        'who-sees-what-your-ai-makes',
        'Who sees what your AI makes',
        'turn public links off entirely',
    ],
    'one-link-that-keeps-its-history' => [
        'one-link-that-keeps-its-history',
        'One link that keeps its history',
        'Nearly identical is not identical',
    ],
    'artfct-vs-a-shared-drive-folder' => [
        'artfct-vs-a-shared-drive-folder',
        'Artfct vs a shared drive: what works better',
        'A shared drive is organized for human eyes',
    ],
    'artfct-vs-your-ai-tools-own-sharing' => [
        'artfct-vs-your-ai-tools-own-sharing',
        'Artfct vs your AI tool',
        'The boundary is the vendor',
    ],
    'why-your-ai-tools-cannot-see-each-others-work' => [
        'why-your-ai-tools-cannot-see-each-others-work',
        'Why your AI tools cannot see each other',
        'The controls stop at the edge of the product',
    ],
    'what-is-an-ai-artifact' => [
        'what-is-an-ai-artifact',
        'What is an AI artifact?',
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
