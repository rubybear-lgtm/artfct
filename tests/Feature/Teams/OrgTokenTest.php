<?php

use App\Enums\TeamRole;
use App\Models\OrgToken;
use App\Models\Team;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Http;

/**
 * Test-only RSA-2048 keypair, same one used by the backend's Rust test
 * fixtures (`backend/src/lib.rs`'s `TEST_KEY_A_DER_B64`, PEM form here).
 * Generated with `openssl genrsa 2048`; used only to sign fixture JWTs.
 */
const ORG_JWT_TEST_PRIVATE_KEY = <<<'PEM'
-----BEGIN RSA PRIVATE KEY-----
MIIEowIBAAKCAQEAuJKelmXQyzS9BeaUdOIEfv1TSowF0uWjK9tw05G5/9eFWx/s
b4RNFKXk4ERWK91DStR1UKy1VeYiyd/w/Bj/bNylKQL0sox4iZpQH8ZN6oBK0qPO
Np5WSJvAIW5VEdQCDh/nDV1rV2Zg6E0W1gXoOaRgT0Dqu+Qd0vURFNqWBmK9gWN7
BkgU963RvMYHao5apdWn1mcWP3+E6eXaoXmp0V7MpRLTphJz8mlsyr6U/NdPjVgw
Zg5jzttouNJtcFLlvwNaBXuMNlivBnjsbBp5nkmOSzeT9a7m3OvKbinWO+ffL3xW
LWQmHGPOyBnfk2o3tx9n6GMch0KrAg5S7luEKwIDAQABAoIBADCqeCYvsl3iCfUE
VyB6d7UEFnIReXeiFOP7eERQqDpNGVxtjmnY+Hn5Q9/eJNpr/NI+MrCS2T1M8N9J
rMDL1o1doC6wGNT7NM0TYwz9vI2YRiJEDptYJGgAqSgnb0bEH8aZotJjT2o8FFEs
AllsNU79iGddNodUHokBFP/qoqQL8iW4pEmvjDRWvZhLJ+9/yj8cgMMwEwCmmjgM
w5T+Ag2+A0LN5JKYrs/Lk5sk1P0Bj008NxzN5DOmDHli4DBbSgBS08UQz/vzFKSd
BXfrvAv3n33JCJt+r3OW1WI13kajtWocErAjmxbwxMn85+/A3eqN6sjpCSrLvqDg
b4mCcAECgYEA5IWGFOsNypnTUlqM9uUCT2uIo0av6VocHW+SXe8Z/EiOs12jFJSB
NPU+jY4QYkn5DNKUhqn0Gdj/xJjTNrQZAL2SkIWdOnc3u3rbwrLg6JVUzmoVyInb
so/EnDM4u2t3BHgxN6cwLFNhf104KBxrty+2XO3r5mHSCtdLwi+3SFECgYEAzsQ9
A3bnejDF2MLN65+BtPZohBeGfbcGFm5e7fl2F42Nefph45Co7cvyaCUWursRR0BI
VuAO5W8Rrwl88EmRNTBrv6n+Ppg1kJZBhG99EDWzobISh4+/IYqwh3WPcDME0u0U
LJDGxbfERRDIUz9lpc/EsnZhIuO1Og3a+V4jYbsCgYB/WVGxUpRq9XJokIHCDTlO
XRTWOMxLdKX6WXTt2BNZHm430tTQ4Tln88uaQzMqMyMRXEDdEtUvmlhejPQXpiHQ
4dRNqchHDq0GU58oT1s7Ag0ywrfE+95tEeV1Tq4s8+RtnzV+WDNmYEkTGzXyVHRK
r9Im04gE6TqORBC59LFlIQKBgHdUfB4GvpsfkN+DthI5UUNePn2VkjH1sha6BiFz
qnr3X+I45cvPDh+HZ9RBK3gDRHqJl/ZDg3VYf600XZ3T53D6DAVml2wKrkdO4GsN
aPE0/QHh4p3IETfLcgwLhgfr+em9l7oMqBst7qEpiWO6H/DtEwkoFvFq14m0u17V
vLfHAoGBAMj9sq9lMfFzTk+kthAickPoej53srNcHbg/byzejo94ZltdqhU9FbKS
Dorfdqh91T//TrDCCfClLk/AnptCzE0NlevQOwLsJwvBuaTIGZ3rlxa9NFgPLtU+
e/YuZBI1/353WWyP8mQ5W+vevP4t5RnZdnUd3+iqg/cjJrmdzm/R
-----END RSA PRIVATE KEY-----
PEM;

const ORG_JWT_TEST_PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAuJKelmXQyzS9BeaUdOIE
fv1TSowF0uWjK9tw05G5/9eFWx/sb4RNFKXk4ERWK91DStR1UKy1VeYiyd/w/Bj/
bNylKQL0sox4iZpQH8ZN6oBK0qPONp5WSJvAIW5VEdQCDh/nDV1rV2Zg6E0W1gXo
OaRgT0Dqu+Qd0vURFNqWBmK9gWN7BkgU963RvMYHao5apdWn1mcWP3+E6eXaoXmp
0V7MpRLTphJz8mlsyr6U/NdPjVgwZg5jzttouNJtcFLlvwNaBXuMNlivBnjsbBp5
nkmOSzeT9a7m3OvKbinWO+ffL3xWLWQmHGPOyBnfk2o3tx9n6GMch0KrAg5S7luE
KwIDAQAB
-----END PUBLIC KEY-----
PEM;

function configureOrgJwt(): void
{
    config([
        'services.org_jwt.private_key' => ORG_JWT_TEST_PRIVATE_KEY,
        'services.org_jwt.kid' => 'test-kid',
        'services.org_jwt.worker_base_url' => 'https://worker.test',
        'services.org_jwt.revocation_write_secret' => 'test-revocation-secret',
    ]);
}

function memberOfTeam(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => $role]);

    return $user;
}

test('token_creation_returns_value_once_only', function () {
    configureOrgJwt();
    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);

    $response = test()->actingAs($admin)->postJson("/settings/teams/{$team->slug}/tokens", [
        'name' => 'CI deploy token',
        'role' => 'admin',
    ]);

    $response->assertCreated();
    $rawToken = $response->json('token');
    expect($rawToken)->toBeString()->not->toBeEmpty();

    $claims = (array) JWT::decode($rawToken, new Key(ORG_JWT_TEST_PUBLIC_KEY, 'RS256'));
    expect($claims['org_id'])->toBe($team->slug);
    expect($claims['role'])->toBe('admin');

    $stored = OrgToken::query()->findOrFail($response->json('id'));
    expect($stored->jti)->toBe($claims['jti']);
    // The raw token is never persisted — only jti and a display fragment.
    expect($stored->getAttributes())->not->toHaveKey('token');
    expect(str_ends_with($rawToken, $stored->last_four))->toBeTrue();

    // A second read of the same resource never surfaces the raw value again.
    expect($stored->toArray())->not->toContain($rawToken);
});

test('token_revocation_writes_denylist', function () {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['revoked' => true], 200),
    ]);

    $team = Team::factory()->create();
    $admin = memberOfTeam($team, TeamRole::Admin);
    $token = OrgToken::factory()->for($team)->for($admin)->create(['jti' => 'jti-under-test']);

    $response = test()->actingAs($admin)->deleteJson("/settings/teams/{$team->slug}/tokens/{$token->id}");

    $response->assertOk();
    expect($token->fresh()->revoked_at)->not->toBeNull();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://worker.test/v1/internal/revocations'
            && $request['jti'] === 'jti-under-test'
            && $request->hasHeader('Authorization', 'Bearer test-revocation-secret');
    });
});

test('member_cannot_revoke_another_users_token', function () {
    configureOrgJwt();
    Http::fake([
        'https://worker.test/v1/internal/revocations' => Http::response(['revoked' => true], 200),
    ]);
    $team = Team::factory()->create();
    $owner = memberOfTeam($team, TeamRole::Admin);
    $otherMember = memberOfTeam($team, TeamRole::Member);
    $token = OrgToken::factory()->for($team)->for($owner)->create();

    $response = test()->actingAs($otherMember)->deleteJson("/settings/teams/{$team->slug}/tokens/{$token->id}");

    $response->assertForbidden();
    expect($token->fresh()->revoked_at)->toBeNull();
});
