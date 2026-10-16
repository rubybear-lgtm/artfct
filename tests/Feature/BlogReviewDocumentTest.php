<?php

use App\Services\Blog\BlogContent;
use App\Services\Blog\BlogRenderer;

/**
 * The review document is what the linter scores and what the publish gate reads,
 * so it is a contract of its own: this is the file that would have caught a
 * draft whose served title falls outside the band `E12` enforces.
 */
beforeEach(function () {
    $this->content = app(BlogContent::class);
});

test('the review document carries what the linter and the gate read', function () {
    $post = $this->content->find('what-is-an-ai-artifact');

    expect($post)->not->toBeNull();

    $document = $this->content->standalone($post);

    expect($document)
        ->toContain('data-surface="explainer"')
        ->toContain('data-register="glean-formal"')
        ->toContain('data-target="AI artifact"')
        ->toContain('<meta name="description"')
        ->toContain('<details class="q">')
        ->toContain('<p class="lead">')
        ->toContain('Last updated 2026-10-01');
});

test('the lint opt-out reaches the document as the gate reads it', function () {
    $directory = sys_get_temp_dir().'/artfct-blog-'.bin2hex(random_bytes(6));

    mkdir($directory);
    file_put_contents($directory.'/held-back.md', <<<'MD'
---
slug: held-back
title: A post that opts out of the linter
description: A description long enough to be real copy and short enough to stay inside the band the linter enforces.
date: 2026-09-01
tag: workflows
published: false
surface: explainer
register: glean-formal
target: a temporary question
lint: skip
---

A lead paragraph.

## A section

The body.
MD);

    $content = new BlogContent(app(BlogRenderer::class), $directory);
    $document = $content->standalone($content->fromPath($directory.'/held-back.md'));

    expect($document)->toContain('data-lint="skip"');
});

test('every draft renders a title and a description inside the band E12 and E13 measure', function () {
    $posts = $this->content->every();

    expect($posts)->not->toBeEmpty();

    foreach ($posts as $post) {
        $document = $this->content->standalone($post);

        preg_match('#<title>(.*?)</title>#s', $document, $title);
        preg_match('#<meta name="description" content="(.*?)"#s', $document, $description);

        // Decoded, because that is the string a search result shows and what the
        // linter measures; the attribute itself escapes an apostrophe.
        $titleLength = mb_strlen(html_entity_decode(trim($title[1] ?? ''), ENT_QUOTES));
        $descriptionLength = mb_strlen(html_entity_decode(trim($description[1] ?? ''), ENT_QUOTES));

        $this->assertGreaterThanOrEqual(
            45,
            $titleLength,
            "the rendered title of {$post->slug} is {$titleLength} characters; the band is 45-60",
        );
        $this->assertLessThanOrEqual(
            60,
            $titleLength,
            "the rendered title of {$post->slug} is {$titleLength} characters; the band is 45-60",
        );
        $this->assertGreaterThanOrEqual(
            120,
            $descriptionLength,
            "the rendered description of {$post->slug} is {$descriptionLength} characters; the band is 120-160",
        );
        $this->assertLessThanOrEqual(
            160,
            $descriptionLength,
            "the rendered description of {$post->slug} is {$descriptionLength} characters; the band is 120-160",
        );
    }
});

test('the review document renders every figure as a placeholder rather than dropping it', function () {
    $post = $this->content->find('stop-ai-tools-contradicting-each-other');

    expect($post)->not->toBeNull();

    $document = $this->content->standalone($post);

    expect($document)->toContain('Figure: break')
        ->toContain('Figure: loop')
        ->toContain('class="fig"');
});

test('blog:render writes the review document to the path it is given', function () {
    $target = sys_get_temp_dir().'/artfct-render-'.bin2hex(random_bytes(6)).'.html';

    $this->artisan('blog:render', [
        'draft' => 'what-is-an-ai-artifact',
        '--out' => $target,
    ])->assertSuccessful();

    expect(file_get_contents($target))->toContain('data-surface="explainer"');

    unlink($target);
});

test('blog:render fails clearly on a draft that does not exist', function () {
    $this->artisan('blog:render', ['draft' => 'not-a-draft'])->assertFailed();
});

test('blog:render --all writes one document per draft', function () {
    $directory = sys_get_temp_dir().'/artfct-render-all-'.bin2hex(random_bytes(6));

    $this->artisan('blog:render', [
        '--all' => true,
        '--out' => $directory,
    ])->assertSuccessful();

    $written = glob($directory.'/*.html') ?: [];

    expect(count($written))->toBe(count($this->content->every()));

    foreach ($written as $path) {
        unlink($path);
    }

    rmdir($directory);
});
