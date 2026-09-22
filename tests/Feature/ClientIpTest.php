<?php

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Services\Governance\AuditLogger;
use App\Support\ClientIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * RUB-372. `trustProxies(at: '*')` is kept deliberately: it is what keeps
 * X-Forwarded-Proto honoured so generated URLs stay https behind the platform's
 * edge, and narrowing it breaks the OAuth redirect URIs. The cost is that
 * `$request->ip()` returns the leftmost X-Forwarded-For entry — the value the
 * caller wrote — so throttling and the audit log must not key on it.
 *
 * These tests pin the replacement: a header a caller controls cannot change the
 * address that security decisions use.
 */

/** A Cloudflare edge address, from their published range 172.64.0.0/13. */
const CLOUDFLARE_EDGE = '172.64.10.5';

function forgedRequest(array $headers, string $peer = '10.20.30.40'): Request
{
    $request = Request::create('https://artfct.test/login', 'GET');
    $request->server->set('REMOTE_ADDR', $peer);

    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return $request;
}

test('a forged x forwarded for does not become the client address', function () {
    // Sent on its own — the shape that defeats a naive "take the rightmost
    // entry" rule, because the forged value *is* the rightmost entry.
    expect(ClientIp::for(forgedRequest(['X-Forwarded-For' => '203.0.113.9'])))
        ->toBe('10.20.30.40');

    // Prepended to a chain, and rotated between requests.
    expect(ClientIp::for(forgedRequest(['X-Forwarded-For' => '203.0.113.9, 10.20.30.40'])))
        ->toBe('10.20.30.40')
        ->and(ClientIp::for(forgedRequest(['X-Forwarded-For' => '198.51.100.9, 10.20.30.40'])))
        ->toBe('10.20.30.40');
});

test('a forged cf connecting ip is ignored when the request did not come through cloudflare', function () {
    // The direct Railway path: nothing Cloudflare wrote is present, so the
    // header is just another caller-supplied value.
    expect(ClientIp::for(forgedRequest([
        'X-Forwarded-For' => '203.0.113.9, 10.20.30.40',
        'CF-Connecting-IP' => '203.0.113.77',
    ])))->toBe('10.20.30.40');
});

test('through cloudflare the address cloudflare wrote is used', function () {
    // Cloudflare appended the real client, then the platform recorded
    // Cloudflare's own address as the last hop.
    expect(ClientIp::for(forgedRequest(
        [
            'X-Forwarded-For' => '203.0.113.9, 198.51.100.4, '.CLOUDFLARE_EDGE,
            'CF-Connecting-IP' => '198.51.100.4',
        ],
        CLOUDFLARE_EDGE,
    )))->toBe('198.51.100.4');
});

test('without any forwarded header the peer address is used', function () {
    expect(ClientIp::for(forgedRequest([])))->toBe('10.20.30.40');
});

test('a forged x forwarded for does not change the rate-limit key', function () {
    // The request is built by the kernel rather than by hand, so the trusted
    // proxy state is whatever the application actually configures. A hand-built
    // request does not reproduce it, and a test that sets it up wrong passes for
    // the wrong reasons -- which is how an earlier version of this test managed
    // to pass against the vulnerable code.
    $probe = function (string $forged): array {
        test()->call('GET', '/up', [], [], [], [
            'HTTP_X_FORWARDED_FOR' => $forged,
            'REMOTE_ADDR' => '10.20.30.40',
        ]);

        $request = app('request');
        $limit = (RateLimiter::limiter('auth'))($request);

        return ['ip' => (string) $request->ip(), 'key' => $limit->key];
    };

    $first = $probe('203.0.113.1');
    $second = $probe('203.0.113.2');

    // The first assertion is the guard: it proves the forged header still
    // reaches `$request->ip()`, which is the vulnerability. Without it, this
    // test would also pass on a request the header never reached.
    expect($first['ip'])->not->toBe($second['ip'], 'the forged header must still move $request->ip(), or this proves nothing')
        ->and($first['key'])->toBe($second['key'], 'rotating the forged header must not create a fresh bucket')
        ->and($first['key'])->toBe('10.20.30.40');
});

test('the audit log records the resolved address, not a forged header', function () {
    test()->call('GET', '/up', [], [], [], [
        'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
        'HTTP_USER_AGENT' => 'probe/1.0',
        'REMOTE_ADDR' => '10.20.30.40',
    ]);

    $request = app('request');

    // Same guard: the forged value must be what `$request->ip()` gives, so the
    // assertion below is about the fix rather than about an untouched request.
    expect($request->ip())->toBe('203.0.113.99');

    $team = Team::factory()->create();

    app(AuditLogger::class)->recordForRequest(
        $request,
        AuditEventType::TokenCreated,
        $team,
        'actor-1',
        'token:1',
    );

    $event = AuditEvent::query()->latest('id')->first();

    expect($event)->not->toBeNull()
        ->and($event->ip)->toBe('10.20.30.40')
        ->and($event->ip)->not->toBe('203.0.113.99');
});
