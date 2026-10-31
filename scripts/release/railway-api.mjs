/**
 * Railway GraphQL client. The token is never logged.
 */

/**
 * @param {{
 *   token: string,
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
                Authorization: `Bearer ${options.token}`,
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
