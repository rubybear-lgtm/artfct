<?php

test('the landing page shows the flow animation with a sound control that starts muted', function () {
    visit('/')
        ->assertNoJavaScriptErrors()
        ->assertPresent('@landing-flow')
        ->assertSeeIn('@landing-sound', 'Sound on')
        ->assertScript('document.querySelector(\'[data-testid="landing-flow"]\').muted', true)
        ->click('@landing-sound')
        ->assertSeeIn('@landing-sound', 'Sound off')
        ->assertScript('document.querySelector(\'[data-testid="landing-flow"]\').muted', false)
        ->click('@landing-sound')
        ->assertSeeIn('@landing-sound', 'Sound on')
        ->assertScript('document.querySelector(\'[data-testid="landing-flow"]\').muted', true);
});

test('the headline and the whole animation fit one laptop screen', function () {
    visit('/')
        ->resize(1440, 800)
        ->assertScript('document.querySelector(\'[data-testid="landing-flow"]\').getBoundingClientRect().bottom <= window.innerHeight + 1', true)
        ->assertScript('document.querySelector(\'[data-testid="landing-hero-cta"]\').getBoundingClientRect().bottom <= window.innerHeight', true)
        ->assertNoJavaScriptErrors();
});

test('a phone gets the portrait cut and it fits the screen width', function () {
    visit('/')
        ->resize(390, 844)
        ->assertScript('document.querySelector(\'[data-testid="landing-flow"]\').currentSrc.includes("landing-flow-phone-")', true)
        ->assertScript('document.querySelector(\'[data-testid="landing-flow"]\').getBoundingClientRect().width <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});

test('the animation starts on a quality tier that suits the screen', function () {
    visit('/')
        ->assertScript('["high", "medium", "low"].includes(document.querySelector(\'[data-testid="landing-flow"]\').dataset.quality)', true)
        ->assertScript('document.querySelector(\'[data-testid="landing-flow"]\').currentSrc.includes("landing-flow-wide-")', true)
        ->assertNoJavaScriptErrors();
});

test('the tool strip shows a product mark beside each AI tool name', function () {
    visit('/')
        ->assertScript('document.querySelectorAll(".strip .names > span:not(.more) svg").length', 4)
        ->assertNoJavaScriptErrors();
});
