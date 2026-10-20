#!/usr/bin/env node
/**
 * Tests the artifact WAF rule builder in cloudflare-rate-limits.mjs. No
 * Cloudflare calls: the rule expressions are checked against sample hosts.
 *
 *   node test-cloudflare-rate-limits.mjs
 *
 * Prints one line per case and exits non-zero if any case fails.
 */

import assert from 'node:assert/strict';
import {
    buildManagedRules,
    unappliedRuleRefs,
} from './cloudflare-rate-limits.mjs';

const ZONE = 'artfct.dev';

const ARTIFACT_HOSTS = [
    'artfct.dev',
    'acme--abc1234567--prod.artfct.dev',
    'acme--abc1234567--stg.artfct.dev',
];

const UNRELATED_HOSTS = [
    'example.com',
    'artfct.dev.example.com',
    'evil--prod.artfct.dev.example.com',
];

/**
 * Evaluates the host clauses of a rule expression the way Cloudflare does:
 * `http.host eq` is an exact match and `ends_with(http.host, ...)` a suffix.
 *
 * @param {string} expression
 * @param {string} host
 * @returns {boolean}
 */
const hostMatches = (expression, host) =>
    [
        ...expression.matchAll(
            /http\.host eq "([^"]+)"|ends_with\(http\.host, "([^"]+)"\)/g,
        ),
    ].some(([, exact, suffix]) =>
        exact !== undefined ? host === exact : host.endsWith(suffix),
    );

let failures = 0;

const check = (name, fn) => {
    try {
        fn();
        console.log(`ok   ${name}`);
    } catch (error) {
        failures++;
        console.log(`FAIL ${name}\n${error.message}`);
    }
};

const rules = buildManagedRules(ZONE);

check('every artifact rule covers apex, --prod and --stg hosts', () => {
    for (const rule of rules) {
        for (const host of ARTIFACT_HOSTS) {
            assert.ok(
                hostMatches(rule.expression, host),
                `${rule.ref} does not match ${host}: ${rule.expression}`,
            );
        }
    }
});

check('artifact rules do not match unrelated hosts', () => {
    for (const rule of rules) {
        for (const host of UNRELATED_HOSTS) {
            assert.equal(
                hostMatches(rule.expression, host),
                false,
                `${rule.ref} matches ${host}: ${rule.expression}`,
            );
        }
    }
});

check('the three artifact rules are present with unique refs', () => {
    assert.deepEqual(
        rules.map((rule) => rule.ref),
        [
            'artfct_create_artifact_rate_limit',
            'artfct_preview_rate_limit',
            'artfct_manage_rate_limit',
        ],
    );
});

check('the host clause follows the zone name', () => {
    const otherRules = buildManagedRules('example.test');

    assert.equal(
        hostMatches(otherRules[0].expression, 'acme--abc1234567--stg.example.test'),
        true,
    );
    assert.equal(hostMatches(otherRules[0].expression, 'artfct.dev'), false);
});

check('unappliedRuleRefs names the rules a one-rule save left out', () => {
    assert.deepEqual(unappliedRuleRefs(rules, rules.slice(0, 1)), [
        'artfct_preview_rate_limit',
        'artfct_manage_rate_limit',
    ]);
    assert.deepEqual(unappliedRuleRefs(rules, rules), []);
});

if (failures > 0) {
    console.log(`\n${failures} case(s) failed`);
    process.exit(1);
}

console.log('\nall cases passed');
