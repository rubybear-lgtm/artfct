/**
 * Dry-run credential checks for the release Action (RUB-517).
 *
 * A dry run skips the push, so it never exercised the Railway or smoke tokens.
 * These checks make the same kind of read-only call the real release makes
 * afterwards, so a missing, renamed or wrong secret fails the dry run instead
 * of failing after `main` has already been fast-forwarded.
 */

/**
 * Confirms the Railway token can read the production project, using the
 * query the deploy poller runs first. For a project token it also confirms
 * the token is bound to the production project and environment, so a staging
 * token pasted into the production secret is caught.
 *
 * @param {{
 *   graphql?: (query: string, variables?: Record<string, unknown>) => Promise<any>,
 *   projectId: string,
 *   environmentId?: string,
 *   verifyProjectToken?: boolean,
 *   serviceNames: string[],
 * }} options
 * @returns {Promise<{ ok: boolean, message: string }>}
 */
export async function checkRailwayCredentials(options) {
    if (!options.graphql) {
        return {
            ok: false,
            message:
                'RAILWAY_API_TOKEN is empty. Check the secret name the workflow reads (RAILWAY_API_TOKEN_PRODUCTION).',
        };
    }

    if (options.verifyProjectToken && options.environmentId) {
        try {
            const scope = await options.graphql(
                `query TokenScope {
      projectToken {
        projectId
        environmentId
      }
    }`,
            );

            if (
                scope?.projectToken?.projectId !== options.projectId ||
                scope?.projectToken?.environmentId !== options.environmentId
            ) {
                return {
                    ok: false,
                    message: `Railway project token is bound to project ${scope?.projectToken?.projectId} / environment ${scope?.projectToken?.environmentId}, not the production project ${options.projectId} / environment ${options.environmentId}.`,
                };
            }
        } catch (error) {
            return {
                ok: false,
                message: `Railway rejected the project token: ${
                    error instanceof Error ? error.message : String(error)
                }`,
            };
        }
    }

    let data;

    try {
        data = await options.graphql(
            `query ProjectServices($id: String!) {
      project(id: $id) {
        id
        services {
          edges {
            node {
              id
              name
            }
          }
        }
      }
    }`,
            { id: options.projectId },
        );
    } catch (error) {
        return {
            ok: false,
            message: `Railway token could not read project ${options.projectId}: ${
                error instanceof Error ? error.message : String(error)
            }`,
        };
    }

    const names = (data?.project?.services?.edges ?? []).map(
        (edge) => edge.node.name,
    );
    const missing = options.serviceNames.filter((name) => !names.includes(name));

    if (missing.length > 0) {
        return {
            ok: false,
            message: `Railway production project is missing services: ${missing.join(', ')}.`,
        };
    }

    return {
        ok: true,
        message: `Railway token can read project ${options.projectId} (${options.serviceNames.join(', ')} found).`,
    };
}

/**
 * Confirms the smoke bot token is accepted by the production MCP endpoint.
 * Only `initialize` is called: comparing `tools/list` here would fail any
 * release that adds a tool, because production still runs the old revision.
 *
 * @param {{
 *   token?: string,
 *   mcpUrl?: string,
 *   fetchImpl?: typeof fetch,
 * }} options
 * @returns {Promise<{ ok: boolean, message: string }>}
 */
export async function checkSmokeCredentials(options) {
    if (!options.token) {
        return {
            ok: false,
            message: 'ARTFCT_SMOKE_BOT_TOKEN is empty.',
        };
    }

    const fetchImpl = options.fetchImpl ?? fetch;
    const mcpUrl = options.mcpUrl ?? 'https://artfct.dev/mcp';

    let response;

    try {
        response = await fetchImpl(mcpUrl, {
            method: 'POST',
            headers: {
                Authorization: `Bearer ${options.token}`,
                Accept: 'application/json, text/event-stream',
                'Content-Type': 'application/json',
                'MCP-Protocol-Version': '2025-03-26',
            },
            body: JSON.stringify({
                jsonrpc: '2.0',
                id: 'initialize',
                method: 'initialize',
                params: {
                    protocolVersion: '2025-03-26',
                    capabilities: {},
                    clientInfo: { name: 'artfct-release-dry-run', version: '1.0.0' },
                },
            }),
        });
    } catch (error) {
        return {
            ok: false,
            message: `Production MCP was unreachable: ${
                error instanceof Error ? error.message : String(error)
            }`,
        };
    }

    if (response.status < 200 || response.status >= 300) {
        return {
            ok: false,
            message: `Production MCP rejected the smoke token (initialize returned HTTP ${response.status}).`,
        };
    }

    return {
        ok: true,
        message: 'Smoke token accepted by production MCP initialize.',
    };
}
