<?php

use App\Enums\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Team;
use App\Services\Governance\AuditLogger;
use App\Support\ClientIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * RUB-372. Two layers, tested separately because they fail differently.
 *
 * Trust is scoped to Cloudflare in AppServiceProvider, so `$request->ip()` no
 * longer reads a header written by whoever called us: on the public path
 * Cloudflare appended the real client and only Cloudflare is trusted, and on the
 * direct path nothing is trusted. `App\Support\ClientIp` covers what is left —
 * resolving from what Cloudflare wrote, or the peer, for anything that must not
 * key on a caller-supplied value whatever the trust list is.
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
    // the wrong reasons — which is how an earlier version of this test managed
    // to pass against the vulnerable code.
    //
    // The peer is not a Cloudflare address, so this is the direct path: the
    // forwarded header is not trusted at all.
    $probe = function (string $forged): array {
        test()->call('GET', '/up', [], [], [], [
            'HTTP_X_FORWARDED_FOR' => $forged,
            'REMOTE_ADDR' => '10.20.30.40',
        ]);

        $request = app('request');
        $limit = (RateLimiter::limiter('auth'))($request);

        return [
            'header' => $request->headers->get('X-Forwarded-For'),
            'ip' => (string) $request->ip(),
            'key' => $limit->key,
        ];
    };

    $first = $probe('203.0.113.1');
    $second = $probe('203.0.113.2');

    // The header guard keeps this from passing on a request the forgery never
    // reached; the key assertion is the acceptance criterion.
    expect($first['header'])->toBe('203.0.113.1')
        ->and($second['header'])->toBe('203.0.113.2')
        ->and($first['key'])->toBe($second['key'], 'rotating the forged header must not create a fresh bucket')
        ->and($first['key'])->toBe('10.20.30.40')
        ->and($first['ip'])->toBe('10.20.30.40', 'untrusted peers must not have their forwarded header read');
});

test('a forgery prepended to a trusted cloudflare chain is not the client', function () {
    // The non-vacuity carrier. Here the peer IS trusted, so the forwarded chain
    // is read — and the forgery sits to the left of what Cloudflare appended.
    // Trusting every proxy, the configuration before RUB-372, returns the
    // leftmost entry, i.e. the forgery, and both assertions below fail.
    test()->call('GET', '/up', [], [], [], [
        'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 198.51.100.4, '.CLOUDFLARE_EDGE,
        'HTTP_CF_CONNECTING_IP' => '198.51.100.4',
        'REMOTE_ADDR' => CLOUDFLARE_EDGE,
    ]);

    $request = app('request');

    expect($request->headers->get('X-Forwarded-For'))->toBe('203.0.113.9, 198.51.100.4, '.CLOUDFLARE_EDGE)
        ->and((string) $request->ip())->not->toBe('203.0.113.9', 'the prepended forgery must not be read as the client')
        ->and(ClientIp::for($request))->toBe('198.51.100.4');
});

test('the audit log records the resolved address, not a forged header', function () {
    test()->call('GET', '/up', [], [], [], [
        'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
        'HTTP_USER_AGENT' => 'probe/1.0',
        'REMOTE_ADDR' => '10.20.30.40',
    ]);

    $request = app('request');

    // The forgery arrived and is not what the request resolves to.
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
        ->and($event->ip)->not->toBe('203.0.113.99');
});
