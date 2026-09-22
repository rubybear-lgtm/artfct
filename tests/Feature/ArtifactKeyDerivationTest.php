<?php

/**
 * Pins the artifact key derivation across the three places that implement it.
 *
 * The CLI encrypts a secure artifact; the browser encryptor does the same for
 * the public upload page; and the viewer the Worker serves decrypts. All three
 * must derive identical bytes, and the failure mode if they do not is silent:
 * nothing here exercises the encrypted-artifact decrypt path end to end, so a
 * one-byte disagreement would make every new secure artifact unopenable and no
 * existing test would say so.
 *
 * Two complementary guards, because this repository has no JavaScript test
 * runner to host the assertion natively:
 *
 * 1. The shared vector is derived through WebCrypto in node -- the same API the
 *    browser and the Worker's viewer use -- and must equal the value
 *    `salted_derivation_matches_the_shared_test_vector` asserts against ring in
 *    the Rust suite. Together the two tests pin Rust == WebCrypto.
 * 2. Each of the three sources must carry that same iteration count, version and
 *    salt length, so the shipped code cannot drift from the parameters the
 *    vector was computed with.
 */

/** The shared vector: PBKDF2-HMAC-SHA256, 210_000 iterations, 32-byte key. */
const ARTIFACT_KDF_VECTOR = [
    'code' => '0123456789',
    'salt_hex' => '00000000000000000000000000000000',
    'iterations' => 210000,
    'key_hex' => '0b51d5dc36329bb22150ebeda005e4d2129a3f9d10a8408f34d8ab00cd61bb21',
];

test('webcrypto derives the same key as ring for the shared vector', function () {
    $vector = ARTIFACT_KDF_VECTOR;

    $script = <<<'JS'
    const { webcrypto } = require('crypto');

    (async () => {
        const encoder = new TextEncoder();
        const material = await webcrypto.subtle.importKey(
            'raw',
            encoder.encode(process.argv[1]),
            'PBKDF2',
            false,
            ['deriveBits'],
        );
        const bits = await webcrypto.subtle.deriveBits(
            {
                name: 'PBKDF2',
                salt: Buffer.from(process.argv[2], 'hex'),
                iterations: Number(process.argv[3]),
                hash: 'SHA-256',
            },
            material,
            256,
        );

        process.stdout.write(Buffer.from(bits).toString('hex'));
    })();
    JS;

    $command = sprintf(
        'node -e %s -- %s %s %s 2>&1',
        escapeshellarg($script),
        escapeshellarg($vector['code']),
        escapeshellarg($vector['salt_hex']),
        escapeshellarg((string) $vector['iterations']),
    );

    $output = trim((string) shell_exec($command));

    expect($output)->not->toContain('Error', 'node or WebCrypto is unavailable: '.$output)
        ->and($output)->toBe(
            $vector['key_hex'],
            'WebCrypto and ring disagree on the artifact key derivation, which would make every new secure artifact unopenable',
        );
});

test('every derivation site uses the same parameters', function () {
    $vector = ARTIFACT_KDF_VECTOR;

    $sites = [
        'CLI encryptor' => 'mcp-server/src/artifact_crypto.rs',
        'browser encryptor' => 'resources/js/lib/artifactCrypto.ts',
        'viewer decryptor' => 'backend/src/lib.rs',
    ];

    $problems = [];

    foreach ($sites as $label => $relative) {
        $source = (string) file_get_contents(base_path($relative));

        // The iteration count is written differently in each language; assert
        // the digits, which is the part that has to agree.
        if (! preg_match('/210[_]?000/', $source)) {
            $problems[] = $label.' ('.$relative.') does not carry the vector iteration count';
        }
    }

    $rust = (string) file_get_contents(base_path('mcp-server/src/artifact_crypto.rs'));
    $typescript = (string) file_get_contents(base_path('resources/js/lib/artifactCrypto.ts'));
    $worker = (string) file_get_contents(base_path('backend/src/lib.rs'));

    if (! str_contains($rust, 'pbkdf2::PBKDF2_HMAC_SHA256')) {
        $problems[] = 'the CLI encryptor does not derive with PBKDF2-HMAC-SHA256';
    }

    if (! str_contains($typescript, "'PBKDF2'")) {
        $problems[] = 'the browser encryptor does not derive with PBKDF2';
    }

    if (! preg_match('/KDF_SALT_BYTES: usize = 16/', $rust) || ! preg_match('/KDF_SALT_BYTES = 16/', $typescript)) {
        $problems[] = 'the salt length is not 16 bytes in both encryptors';
    }

    // The version tag is what selects the derivation; if the writers and the
    // reader disagree on it, new links fall back to the legacy path and fail.
    if (! preg_match('/KDF_VERSION: u32 = 2/', $rust) || ! preg_match('/KDF_VERSION = 2/', $typescript)) {
        $problems[] = 'the KDF version tag is not 2 in both encryptors';
    }

    if (! str_contains($worker, 'version === 2')) {
        $problems[] = 'the viewer decryptor does not dispatch on the version tag';
    }

    expect($problems)->toBe([]);
});

test('the viewer still opens fragments minted before the salted KDF', function () {
    $worker = (string) file_get_contents(base_path('backend/src/lib.rs'));

    // A bare fragment has no `v`, so `Number(hash.get('v'))` is 0 rather than 2
    // and the legacy branch must be what runs. Both halves are asserted because
    // either one alone would let live links break silently.
    expect($worker)->toContain("hash.get('p') ?? window.location.hash.slice(1)")
        ->and($worker)->toContain("hash.get('s')")
        ->and($worker)->toContain("hash.get('v')")
        ->and($worker)->toContain('version === 2')
        ->and($worker)->toContain("crypto.subtle.digest(\n      'SHA-256'");

    // The legacy derivation is a bare SHA-256 of the code, and the links already
    // in the wild were minted with it, so the value is pinned here -- in the
    // language that actually still performs it. The CLI never decrypts, so this
    // is the decryptor's contract, not the encryptor's.
    $script = <<<'JS'
    const { webcrypto } = require('crypto');

    (async () => {
        const digest = await webcrypto.subtle.digest(
            'SHA-256',
            new TextEncoder().encode('0123456789'),
        );

        process.stdout.write(Buffer.from(digest).toString('hex'));
    })();
    JS;

    $output = trim((string) shell_exec('node -e '.escapeshellarg($script).' 2>&1'));

    expect($output)->toBe(
        '84d89877f0d4041efb6bf91a16f0248f2fd573e6af05c19f96bedb9f882f7882',
        'the legacy derivation changed, which would orphan every share link already in the wild',
    );
});
