/**
 * Railway GraphQL client. The token is never logged.
 */

/**
 * Railway project tokens authenticate with the `Project-Access-Token` header;
 * account, workspace and OAuth tokens use `Authorization: Bearer`. The repo
 * secrets are project tokens, so that is the default. Set
 * RAILWAY_API_TOKEN_KIND=bearer to run the scripts locally with an account
 * token.
 *
 * @param {string} token
 * @param {string} [kind]
 * @returns {Record<string, string>}
 */
export function railwayAuthHeaders(
    token,
    kind = process.env.RAILWAY_API_TOKEN_KIND || 'project',
) {
    if (kind === 'project') {
        return { 'Project-Access-Token': token };
    }

    if (kind === 'bearer') {
        return { Authorization: `Bearer ${token}` };
    }

    throw new Error(
        `Unknown RAILWAY_API_TOKEN_KIND "${kind}". Use "project" or "bearer".`,
    );
}

/**
 * @param {{
 *   token: string,
 *   tokenKind?: string,
 *   endpoint?: string,
 *   fetchImpl?: typeof fetch,
 * }} options
 */
export function createRailwayGraphql(options) {
    const fetchImpl = options.fetchImpl ?? fetch;
    const endpoint =
        options.endpoint ?? 'https://backboard.railway.com/graphql/v2';

    /**
     * @param {string} query
     * @param {Record<string, unknown>} [variables]
     */
    return async function graphql(query, variables = {}) {
        const response = await fetchImpl(endpoint, {
            method: 'POST',
            headers: {
                ...railwayAuthHeaders(options.token, options.tokenKind),
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ query, variables }),
        });

        const payload = await response.json();

        if (!response.ok || payload.errors?.length) {
            const message = payload.errors
                ? JSON.stringify(payload.errors)
                : `HTTP ${response.status}`;

            throw new Error(`Railway GraphQL error: ${message}`);
        }

        return payload.data;
    };
}
