<?php

use App\Models\User;
use App\Support\StaffAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.org_jwt.worker_base_url' => 'https://worker.test',
        'services.org_jwt.revocation_write_secret' => 'test-revocation-secret',
    ]);
    Http::fake(['*' => Http::response([], 200)]);
});

function sessionRowFor(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => '',
        'last_activity' => time(),
    ]);
}

test('a_deactivated_users_live_request_is_rejected', function () {
    $user = User::factory()->create(['deactivated_at' => now()]);

    test()->actingAs($user)->get(route('account.show'))->assertRedirect(route('login'));

    test()->assertGuest();
});

test('a_deactivated_users_json_request_gets_unauthenticated', function () {
    $user = User::factory()->create(['deactivated_at' => now()]);

    test()->actingAs($user)->getJson(route('account.show'))->assertUnauthorized();

    test()->assertGuest();
});

test('an_active_user_keeps_access_to_the_account_page', function () {
    $user = User::factory()->create();

    test()->actingAs($user)->get(route('account.show'))->assertOk();
});

test('staff_access_is_refused_for_a_deactivated_staff_user', function () {
    $staff = User::factory()->create(['staff_at' => now()]);
    $request = Request::create('http://localhost/ops');

    expect(StaffAccess::allows($staff, $request))->toBeTrue();

    $staff->forceFill(['deactivated_at' => now()])->save();

    expect(StaffAccess::allows($staff->fresh(), $request))->toBeFalse();
});

test('closing_an_account_ends_every_session_the_user_has', function () {
    $user = User::factory()->create(['staff_at' => now(), 'case_manager_at' => now()]);
    sessionRowFor($user, 'other-browser');
    sessionRowFor($user, 'this-browser');

    test()->actingAs($user)->delete(route('account.destroy'), ['confirmation' => 'DELETE'])->assertRedirect(route('home'));

    $user->refresh();
    expect($user->deactivated_at)->not->toBeNull()
        ->and($user->staff_at)->toBeNull()
        ->and($user->case_manager_at)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});
