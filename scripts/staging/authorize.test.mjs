import assert from 'node:assert/strict';
import { test } from 'node:test';

import { assertMutationAuthorized } from './authorize.mjs';

test('read-only staging acceptance is allowed without mutation authorization', () => {
    const result = assertMutationAuthorized({
        authorizeMutations: false,
        environmentName: 'staging',
        mutating: false,
    });
    assert.equal(result.ok, true);
});

test('mutating staging work requires explicit authorization on the staging environment', () => {
    assert.equal(
        assertMutationAuthorized({
            authorizeMutations: false,
            environmentName: 'staging',
            mutating: true,
        }).code,
        'mutations_not_authorized',
    );

    assert.equal(
        assertMutationAuthorized({
            authorizeMutations: true,
            environmentName: 'production',
            mutating: true,
        }).code,
        'environment_not_staging',
    );

    assert.equal(
        assertMutationAuthorized({
            authorizeMutations: true,
            environmentName: 'artfct-pr-103',
            mutating: true,
        }).code,
        'environment_not_staging',
    );

    const allowed = assertMutationAuthorized({
        authorizeMutations: true,
        environmentName: 'staging',
        mutating: true,
    });
    assert.equal(allowed.ok, true);
});
