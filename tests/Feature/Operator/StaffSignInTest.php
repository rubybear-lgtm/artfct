<?php

use App\Services\AuthKit\AuthKitClientContract;
use App\Services\AuthKit\AuthKitProfile;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config([
        'services.workos.client_id' => 'client_test',
        'services.workos.secret' => 'sk_test',
        'services.workos.redirect_url' => 'https://app.test/authenticate',
        'services.workos.staff_organization_id' => 'org_staff_test',
    ]);

    // The hosted (non-fake) client, so the login route builds a real
    // authorization URL instead of rendering the dev screen.
    app()->instance(AuthKitClientContract::class, new class implements AuthKitClientContract
    {
        public function authenticateWithCode(string $code): AuthKitProfile
        {
            return new AuthKitProfile('ext-1', 'GoogleOAuth', 'person@example.com', true, 'Person', null, null);
        }
    });
});

/**
 * The query string of the WorkOS URL the login route sent the browser to.
 *
 * @return array<string, string>
 */
function staffSignInAuthorizeQuery(TestResponse $response): array
{
    $response->assertRedirect();
    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('user_management/authorize');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query;
}

test('staff_sign_in_scopes_the_hosted_login_to_the_staff_organization_and_lands_on_ops', function () {
    $query = staffSignInAuthorizeQuery($this->get(route('login', ['staff' => 1])));

    expect($query['organization_id'] ?? null)->toBe('org_staff_test')
        ->and(session('url.intended'))->toBe(route('ops.tenants.index'));
});

test('an_ordinary_sign_in_is_never_scoped_to_the_staff_organization', function () {
    $query = staffSignInAuthorizeQuery($this->get(route('login')));

    expect($query)->not->toHaveKey('organization_id')
        ->and(session('url.intended'))->toBeNull();
});

test('the_staff_hint_does_nothing_when_no_staff_organization_is_configured', function () {
    foreach ([null, ''] as $unset) {
        config(['services.workos.staff_organization_id' => $unset]);

        $query = staffSignInAuthorizeQuery($this->get(route('login', ['staff' => 1])));

        expect($query)->not->toHaveKey('organization_id')
            ->and(session('url.intended'))->toBeNull();
    }
});

test('a_staff_hint_cannot_name_a_different_organization', function () {
    // Only the configured id is ever sent; a caller cannot choose one.
    $query = staffSignInAuthorizeQuery($this->get(route('login', ['staff' => 1, 'organization_id' => 'org_attacker'])));

    expect($query['organization_id'] ?? null)->toBe('org_staff_test');
});
