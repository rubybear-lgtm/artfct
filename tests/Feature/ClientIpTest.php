<?php

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Services\Governance\AuditLogger;
use App\Support\ClientIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * RUB-372. No header a caller writes may reach a throttle or audit key.
 *
 * `ClientIp::for()` returns the transport peer and reads nothing, because in
 * this topology no header can be trusted: the peer is the platform's edge, and
 * every header that could carry a client address is written by the caller. The
 * tests below pin that from several directions, including the shape that broke
 * an earlier revision — a configured trust range that promoted
 * `CF-Connecting-IP` into a key.
 *
 * The consequence, deliberate and stated at the throttles: callers arriving
 * through one edge address share a bucket, and per-account keys provide the
 * granularity instead.
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
    // Sent alone — the shape that defeats a "take the rightmost entry" rule —
    // prepended to a chain, and claiming to be Cloudflare.
    foreach ([
        '203.0.113.9',
        '203.0.113.9, 10.20.30.40',
        CLOUDFLARE_EDGE,
        '203.0.113.9, 198.51.100.4, '.CLOUDFLARE_EDGE,
    ] as $forged) {
        expect(ClientIp::for(forgedRequest(['X-Forwarded-For' => $forged])))
            ->toBe('10.20.30.40', 'forged: '.$forged);
    }
});

test('a forged cf connecting ip is never the client address', function () {
    expect(ClientIp::for(forgedRequest([
        'X-Forwarded-For' => '203.0.113.9, 10.20.30.40',
        'CF-Connecting-IP' => '203.0.113.77',
    ])))->toBe('10.20.30.40');
});

test('trusting a non-cloudflare range does not turn a header into a key', function () {
    // The shape that broke an earlier revision: the trust list is also what
    // gates the Cloudflare branch, so a maintainer following the old config note
    // and adding the platform's own edge ranges would have made CF-Connecting-IP
    // caller-chosen again. This pins that the list cannot do that.
    config(['trusted_ingress.edge_ranges' => ['10.20.30.0/24']]);

    $probe = function (string $connecting): array {
        test()->call('GET', '/up', [], [], [], [
            'HTTP_CF_CONNECTING_IP' => $connecting,
            'REMOTE_ADDR' => '10.20.30.40',
        ]);

        $request = app('request');

        return [
            'client' => ClientIp::for($request),
            'keys' => collect((RateLimiter::limiter('auth'))($request))
                ->map(fn ($limit) => $limit->key)->values()->all(),
        ];
    };

    $first = $probe('203.0.113.1');
    $second = $probe('203.0.113.2');

    expect($first['client'])->toBe('10.20.30.40')
        ->and($second['client'])->toBe('10.20.30.40')
        ->and($first['keys'])->toBe($second['keys'], 'a trusted range must not promote a caller-written header into a key');
});

test('without any forwarded header the peer address is used', function () {
    expect(ClientIp::for(forgedRequest([])))->toBe('10.20.30.40');
});

test('a forged x forwarded for does not change the rate-limit key', function () {
    // Built by the kernel, so the trusted-proxy state is whatever the
    // application configures. A hand-built request does not reproduce it, and an
    // earlier version of this test passed against the vulnerable code because of
    // exactly that.
    $probe = function (string $forged): array {
        test()->call('GET', '/up', [], [], [], [
            'HTTP_X_FORWARDED_FOR' => $forged,
            'REMOTE_ADDR' => '10.20.30.40',
        ]);

        $request = app('request');

        return [
            'header' => $request->headers->get('X-Forwarded-For'),
            'ip' => (string) $request->ip(),
            'key' => collect((RateLimiter::limiter('auth'))($request))
                ->map(fn ($limit) => $limit->key)->values()->all(),
        ];
    };

    $first = $probe('203.0.113.1');
    $second = $probe('203.0.113.2');

    expect($first['header'])->toBe('203.0.113.1')
        ->and($second['header'])->toBe('203.0.113.2')
        ->and($first['key'])->toBe($second['key'], 'rotating the forged header must not create a fresh bucket')
        ->and($first['key'])->toContain('10.20.30.40')
        ->and($first['ip'])->toBe('10.20.30.40', 'untrusted peers must not have their forwarded header read');
});

test('a cloudflare-shaped forwarded entry does not earn trust on the direct path', function () {
    $probe = function (string $connecting): array {
        test()->call('GET', '/up', [], [], [], [
            'HTTP_X_FORWARDED_FOR' => CLOUDFLARE_EDGE,
            'HTTP_CF_CONNECTING_IP' => $connecting,
            'REMOTE_ADDR' => '10.20.30.40',
        ]);

        $request = app('request');

        return [
            'client' => ClientIp::for($request),
            'key' => collect((RateLimiter::limiter('auth'))($request))
                ->map(fn ($limit) => $limit->key)->values()->all(),
        ];
    };

    $first = $probe('203.0.113.1');
    $second = $probe('203.0.113.2');

    expect($first['client'])->toBe('10.20.30.40')
        ->and($second['client'])->toBe('10.20.30.40')
        ->and($first['key'])->toBe($second['key'], 'rotating CF-Connecting-IP must not create a fresh bucket')
        ->and($first['key'])->toContain('10.20.30.40');
});

test('the throttle carries a per-account key and a forged header changes neither', function () {
    $keysFor = function (string $team, string $forged): array {
        test()->call('GET', '/teams/'.$team.'/sso/authenticate', [], [], [], [
            'HTTP_X_FORWARDED_FOR' => $forged,
            'HTTP_CF_CONNECTING_IP' => $forged,
            'REMOTE_ADDR' => '10.20.30.40',
        ]);

        $request = app('request');

        return collect((RateLimiter::limiter('auth'))($request))
            ->map(fn ($limit) => $limit->key)
            ->values()
            ->all();
    };

    $first = $keysFor('acme', '203.0.113.1');
    $sameButForgedDifferently = $keysFor('acme', '203.0.113.2');
    $otherTeam = $keysFor('globex', '203.0.113.1');

    expect($first)->toBe($sameButForgedDifferently, 'rotating the forged header must not move the caller')
        ->and($first)->toContain('10.20.30.40')
        ->and($first)->toContain('auth-team:acme')
        ->and($otherTeam)->toContain('auth-team:globex')
        ->and($otherTeam)->not->toBe($first, 'a different account must not share the bucket');
});

test('the audit log records the resolved address, not a forged header', function () {
    test()->call('GET', '/up', [], [], [], [
        'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
        'HTTP_CF_CONNECTING_IP' => '203.0.113.98',
        'HTTP_USER_AGENT' => 'probe/1.0',
        'REMOTE_ADDR' => '10.20.30.40',
    ]);

    $request = app('request');

    expect($request->headers->get('X-Forwarded-For'))->toBe('203.0.113.99')
        ->and((string) $request->ip())->toBe('10.20.30.40');

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
        ->and($event->ip)->not->toBe('203.0.113.99')
        ->and($event->ip)->not->toBe('203.0.113.98');
});
