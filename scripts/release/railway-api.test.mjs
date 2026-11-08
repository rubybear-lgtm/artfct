import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createRailwayGraphql, railwayAuthHeaders } from './railway-api.mjs';

test('project tokens use the Project-Access-Token header, never Authorization', () => {
    assert.deepEqual(railwayAuthHeaders('tok', 'project'), {
        'Project-Access-Token': 'tok',
    });
});

test('bearer tokens use Authorization: Bearer', () => {
    assert.deepEqual(railwayAuthHeaders('tok', 'bearer'), {
        Authorization: 'Bearer tok',
    });
});

test('an unknown token kind is rejected without echoing the token', () => {
    assert.throws(
        () => railwayAuthHeaders('super-secret', 'oauth'),
        (error) =>
            error instanceof Error &&
            /RAILWAY_API_TOKEN_KIND/.test(error.message) &&
            !error.message.includes('super-secret'),
    );
});

test('the default kind is project unless RAILWAY_API_TOKEN_KIND says otherwise', () => {
    const previous = process.env.RAILWAY_API_TOKEN_KIND;

    try {
        delete process.env.RAILWAY_API_TOKEN_KIND;
        assert.deepEqual(railwayAuthHeaders('tok'), {
            'Project-Access-Token': 'tok',
        });

        process.env.RAILWAY_API_TOKEN_KIND = 'bearer';
        assert.deepEqual(railwayAuthHeaders('tok'), {
            Authorization: 'Bearer tok',
        });
    } finally {
        if (previous === undefined) {
            delete process.env.RAILWAY_API_TOKEN_KIND;
        } else {
            process.env.RAILWAY_API_TOKEN_KIND = previous;
        }
    }
});

test('the GraphQL client sends the project header and returns data', async () => {
    /** @type {Record<string, string>} */
    let sentHeaders = {};
    const graphql = createRailwayGraphql({
        token: 'tok',
        tokenKind: 'project',
        fetchImpl: async (_url, init) => {
            sentHeaders = /** @type {Record<string, string>} */ (
                init?.headers ?? {}
            );

            return new Response(JSON.stringify({ data: { ok: true } }), {
                status: 200,
            });
        },
    });

    assert.deepEqual(await graphql('query { ok }'), { ok: true });
    assert.equal(sentHeaders['Project-Access-Token'], 'tok');
    assert.equal(sentHeaders.Authorization, undefined);
});
