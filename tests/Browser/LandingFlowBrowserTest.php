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
