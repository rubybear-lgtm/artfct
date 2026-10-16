<?php

use App\Services\Blog\FrontMatter;

function blogDraftFile(string $front, string $body = "A lead paragraph.\n"): string
{
    return "---\n".$front."\n---\n\n".$body;
}

function blogDraftFront(): string
{
    return <<<'FRONT'
slug: a-post
title: A post about artifacts
description: A description that is long enough to be real copy and short enough to stay inside the band.
date: 2026-10-01
tag: explainers
published: true
surface: explainer
register: glean-formal
target: AI artifact
FRONT;
}

test('it reads the flat subset, including a colon inside a value', function () {
    [$meta, $body] = FrontMatter::split(
        blogDraftFile(str_replace('target: AI artifact', 'target: planning: the first pass', blogDraftFront())),
        '/tmp/draft.md',
    );

    expect($meta['slug'])->toBe('a-post')
        ->and($meta['target'])->toBe('planning: the first pass')
        ->and($meta['published'])->toBe('true')
        ->and($body)->toBe("A lead paragraph.\n");
});

test('it accepts quoted values and normalises CRLF', function () {
    $raw = str_replace(
        "\n",
        "\r\n",
        blogDraftFile(str_replace('tag: explainers', "tag: 'versions and history'", blogDraftFront())),
    );

    [$meta] = FrontMatter::split($raw, '/tmp/draft.md');

    expect($meta['tag'])->toBe('versions and history');
});

test('it refuses a file with no front matter', function () {
    FrontMatter::split("# Just a heading\n", '/tmp/draft.md');
})->throws(RuntimeException::class, 'does not start with a front matter block');

test('it refuses an unterminated front matter block', function () {
    FrontMatter::split("---\nslug: a-post\n", '/tmp/draft.md');
})->throws(RuntimeException::class, 'unterminated front matter block');

test('it names the file and the key when a required key is missing', function () {
    $front = str_replace(
        "description: A description that is long enough to be real copy and short enough to stay inside the band.\n",
        '',
        blogDraftFront(),
    );

    FrontMatter::split(blogDraftFile($front), '/tmp/draft.md');
})->throws(RuntimeException::class, 'draft.md is missing the required `description` key');

test('it names the key when an unknown key is present', function () {
    FrontMatter::split(blogDraftFile(blogDraftFront()."\ndescripton: a typo"), '/tmp/draft.md');
})->throws(RuntimeException::class, 'unknown front matter key `descripton`');

test('it refuses a surface outside the four', function () {
    FrontMatter::split(
        blogDraftFile(str_replace('surface: explainer', 'surface: blog-post', blogDraftFront())),
        '/tmp/draft.md',
    );
})->throws(RuntimeException::class, 'unknown surface `blog-post`');

test('it refuses a register outside the two', function () {
    FrontMatter::split(
        blogDraftFile(str_replace('register: glean-formal', 'register: casual', blogDraftFront())),
        '/tmp/draft.md',
    );
})->throws(RuntimeException::class, 'unknown register `casual`');

test('it refuses a published value that is not true or false', function () {
    FrontMatter::split(
        blogDraftFile(str_replace('published: true', 'published: maybe', blogDraftFront())),
        '/tmp/draft.md',
    );
})->throws(RuntimeException::class, 'is not true or false');

test('it refuses a slug that is not url-safe', function () {
    FrontMatter::split(
        blogDraftFile(str_replace('slug: a-post', 'slug: A Post', blogDraftFront())),
        '/tmp/draft.md',
    );
})->throws(RuntimeException::class, 'not `[a-z0-9-]+`');

test('it refuses a date that is not ISO', function () {
    FrontMatter::split(
        blogDraftFile(str_replace('date: 2026-10-01', 'date: October 2026', blogDraftFront())),
        '/tmp/draft.md',
    );
})->throws(RuntimeException::class, 'not `YYYY-MM-DD`');

test('it reads the lint opt-out', function () {
    [$meta] = FrontMatter::split(blogDraftFile(blogDraftFront()."\nlint: skip"), '/tmp/draft.md');

    expect($meta['lint'])->toBe('skip');
});

test('it refuses a lint value other than run or skip', function () {
    FrontMatter::split(blogDraftFile(blogDraftFront()."\nlint: quiet"), '/tmp/draft.md');
})->throws(RuntimeException::class, 'unknown lint value `quiet`');

test('it refuses a key that is set twice', function () {
    FrontMatter::split(blogDraftFile(blogDraftFront()."\ntag: workflows"), '/tmp/draft.md');
})->throws(RuntimeException::class, 'sets `tag` twice');
