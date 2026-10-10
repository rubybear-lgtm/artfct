<?php

test('the landing page hero stage plays the whole story', function () {
    visit('/')
        ->assertNoJavaScriptErrors()
        ->assertPresent('@landing-flow')
        ->assertScript('document.querySelectorAll(\'[data-testid="landing-flow"] img\').length', 4)
        ->assertScript('document.querySelectorAll(\'[data-testid="landing-flow"] .fs-win\').length', 1);
});

test('the headline and the whole hero stage fit one laptop screen', function () {
    visit('/')
        ->resize(1440, 800)
        ->assertScript('document.querySelector(".strip").getBoundingClientRect().bottom <= window.innerHeight + 1', true)
        ->assertScript('document.querySelector(\'[data-testid="landing-flow"]\').getBoundingClientRect().bottom <= window.innerHeight + 1', true)
        ->assertScript('document.querySelector(\'[data-testid="landing-hero-cta"]\').getBoundingClientRect().bottom <= window.innerHeight', true)
        ->assertNoJavaScriptErrors();
});

test('the four steps are separate sections under a sticky index', function () {
    visit('/')
        ->resize(1440, 800)
        ->assertScript('document.querySelectorAll(\'[data-testid^="landing-step-"]\').length', 4)
        ->assertScript('Array.from(document.querySelectorAll(\'[data-testid^="landing-step-"] h2\')).map((el) => el.textContent).join(",")', 'Ask,Share,Find,Use')
        ->assertScript('getComputedStyle(document.querySelector(".story-index")).position', 'sticky')
        ->assertScript('document.querySelectorAll(".story-index a").length', 4)
        ->assertScript('Array.from(document.querySelectorAll(\'[data-testid^="landing-step-"]\')).every((el) => getComputedStyle(el).position !== "sticky" && el.getBoundingClientRect().height >= window.innerHeight * 0.7)', true)
        ->assertNoJavaScriptErrors();
});

test('every window is finished before it plays', function () {
    visit('/')
        ->resize(1440, 800)
        ->assertScript('document.querySelector(\'[data-testid="landing-step-use"]\').textContent.includes("Source: Pricing teardown")', true)
        ->assertScript('document.querySelector(\'[data-testid="landing-step-ask"]\').textContent.includes("Pricing teardown")', true)
        ->assertNoJavaScriptErrors();
});

test('a step plays on arrival and shows its finished state', function () {
    $page = visit('/')->resize(1440, 800);

    $page->script('document.querySelector(\'[data-testid="landing-step-ask"]\').scrollIntoView()');
    $page->wait(9)
        ->assertSeeIn('@landing-step-ask', 'Pricing teardown')
        ->assertNoJavaScriptErrors();
});

test('a phone gets the story in one column and it fits the screen width', function () {
    visit('/')
        ->resize(390, 844)
        ->assertScript('document.querySelector(\'[data-testid="landing-flow"]\').getBoundingClientRect().width <= window.innerWidth', true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertScript('Array.from(document.querySelectorAll(\'[data-testid^="landing-step-"] .fs-in\')).every((el) => getComputedStyle(el).gridTemplateColumns.split(" ").length === 1)', true)
        ->assertNoJavaScriptErrors();
});

test('the tool strip shows a product mark beside each AI tool name', function () {
    visit('/')
        ->assertScript('document.querySelectorAll(".strip .names > span:not(.more) svg").length', 4)
        ->assertNoJavaScriptErrors();
});
