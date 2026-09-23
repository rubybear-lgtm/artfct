<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('registration_and_org_creation_flow', function () {
    $page = visit('/login');

    $page->assertNoJavaScriptErrors()
        ->fill('email', 'browser-user@example.com')
        ->fill('name', 'Browser User')
        ->click('Continue with Google')
        ->assertSee('Create your first team')
        ->click('Create team')
        ->assertSee('What your AI makes');

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

    // Console pages (requires authentication and team membership)
    $page = visit('/login');
    $page->assertNoJavaScriptErrors()
        ->fill('email', 'console-user@example.com')
        ->fill('name', 'Console User')
        ->click('Continue with Google')
        ->assertSee('Create your first team')
        ->click('Create team')
        ->assertSee('What your AI makes');

    // Navigate to console
    $page->navigate('/settings/teams');
    $page->assertNoJavaScriptErrors();
});

test('admin_finds_artifact_by_repo_and_revokes_it', function () {
    $page = visit('/login');
    $page->assertNoJavaScriptErrors()
        ->fill('email', 'admin-user@example.com')
        ->fill('name', 'Admin User')
        ->click('Continue with Google')
        ->assertSee('Create your first team')
        ->click('Create team')
        ->assertSee('What your AI makes');

    // The page now shows the team; navigate to console for that team
    // Since we're using the fake artifact directory, it will have test data
    $page->navigate('/settings/teams/admin-users-team/console');
    $page->assertNoJavaScriptErrors()
        ->assertSee('Dashboard HTML')
        ->assertSee('Active');

    // Filter by repo
    $page->fill('repo_url', 'https://github.com/example/repo1')
        ->wait(1);
    $page->assertSee('Dashboard HTML');

    // Click revoke button, then confirm (two-step in-page confirmation,
    // not a native confirm() dialog — Pest's browser driver has no dialog
    // API to accept one).
    $page->click('Revoke')
        ->wait(1)
        ->click('Confirm revoke?')
        ->wait(1);

    // Verify the artifact is now marked as revoked
    $page->assertSee('Revoked');
});
