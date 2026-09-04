<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('registration_and_org_creation_flow', function () {
    $page = visit('/login');

    $page->assertNoJavaScriptErrors()
        ->fill('email', 'browser-user@example.com')
        ->fill('name', 'Browser User')
        ->click('Continue with Google')
        ->assertSee('Dashboard');

    $page->navigate('/settings/teams');

    $page->assertNoJavaScriptErrors()
        ->assertSee("Browser User's Team")
        ->fill('name', 'Browser Org')
        ->click('Create org')
        ->assertSee('Browser Org');
});

test('console_pages_have_no_js_errors', function () {
    visit('/login')->assertNoJavaScriptErrors();
    visit('/')->assertNoJavaScriptErrors();
});
