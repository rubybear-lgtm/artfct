#!/usr/bin/env node
/**
 * Opt-in staging acceptance entrypoint (RUB-518).
 *
 * Verifies staging-only target, optional Railway SHA match, and mutation
 * authorization. Never falls back to production. Does not mutate shared
 * staging unless --authorize-mutations is set.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import { assertMutationAuthorized } from './authorize.mjs';
import {
    evaluateStagingAcceptanceNeed,
    summarizeAcceptancePath,
} from './policy.mjs';
import { assertStagingDeploymentSha } from './sha.mjs';
import { assertStagingOnlyBaseUrl, STAGING_BASE_URL } from './target.mjs';

export const STAGING_PROJECT_ID = 'f54567a7-a1fe-4d1d-ae2c-02477781226c';
export const STAGING_ENVIRONMENT_ID = '6254e71c-cc7d-4f1c-92d4-ac89d368cb6b';

/**
 * @param {string[]} argv
 */
export function parseArgs(argv) {
    /** @type {Record<string, string | boolean>} */
    const out = {
        sha: '',
        baseUrl: STAGING_BASE_URL,
        authorizeMutations: false,
        mutating: false,
        environmentName: 'staging',
        deploymentsJson: '',
        changedPaths: '',
        force: false,
        skipSha: false,
    };

    for (let i = 0; i < argv.length; i += 1) {
        const arg = argv[i];

        if (arg === '--sha') {
            out.sha = String(argv[++i] ?? '');
        } else if (arg === '--base-url') {
            out.baseUrl = String(argv[++i] ?? '');
        } else if (arg === '--authorize-mutations') {
            out.authorizeMutations = true;
        } else if (arg === '--mutating') {
            out.mutating = true;
        } else if (arg === '--environment-name') {
            out.environmentName = String(argv[++i] ?? '');
        } else if (arg === '--deployments-json') {
            out.deploymentsJson = String(argv[++i] ?? '');
        } else if (arg === '--changed-paths') {
            out.changedPaths = String(argv[++i] ?? '');
        } else if (arg === '--force') {
            out.force = true;
        } else if (arg === '--skip-sha') {
            out.skipSha = true;
        } else if (arg === '--help' || arg === '-h') {
            out.help = true;
        } else {
            throw new Error(`Unknown argument: ${arg}`);
        }
    }

    return out;
}

/**
 * @param {{
 *   sha: string,
 *   baseUrl: string,
 *   authorizeMutations: boolean,
 *   mutating: boolean,
 *   environmentName: string,
 *   deployments?: Array<{ serviceName: string, status: string, meta?: unknown }>,
 *   changedPaths?: string[],
 *   force?: boolean,
 *   skipSha?: boolean,
 *   fetchDeployments?: () => Promise<Array<{ serviceName: string, status: string, meta?: unknown }>>,
 * }} options
 */
export async function runStagingAcceptance(options) {
    const policy = evaluateStagingAcceptanceNeed({
        changedPaths: options.changedPaths ?? [],
        force: options.force === true,
    });

    const target = assertStagingOnlyBaseUrl(options.baseUrl);

    if (!target.ok) {
        return { ok: false, step: 'target', ...target, policy };
    }

    const auth = assertMutationAuthorized({
        authorizeMutations: options.authorizeMutations,
        environmentName: options.environmentName,
        mutating: options.mutating,
    });

    if (!auth.ok) {
        return { ok: false, step: 'authorize', ...auth, policy, target };
    }

    if (options.skipSha) {
        return {
            ok: true,
            step: 'complete',
            skippedSha: true,
            policy,
            policySummary: summarizeAcceptancePath(policy),
            target,
            auth,
            warning:
                'SHA verification skipped. Do not treat this as proof the intended commit is live on staging.',
        };
    }

    const deployments =
        options.deployments ??
        (options.fetchDeployments ? await options.fetchDeployments() : null);

    if (!deployments) {
        return {
            ok: false,
            step: 'sha',
            code: 'missing_deployments',
            message:
                'No staging deployments provided. Pass --deployments-json or configure RAILWAY_API_TOKEN to query staging.',
            policy,
            target,
        };
    }

    const sha = assertStagingDeploymentSha({
        expectedSha: options.sha,
        deployments,
    });

    if (!sha.ok) {
        return { ok: false, step: 'sha', ...sha, policy, target, auth };
    }

    return {
        ok: true,
        step: 'complete',
        policy,
        policySummary: summarizeAcceptancePath(policy),
        target,
        auth,
        sha,
    };
}

/**
 * Minimal Railway GraphQL helper used only when RAILWAY_API_TOKEN is set.
 *
 * @param {string} token
 * @param {string} query
 * @param {Record<string, unknown>} [variables]
 */
async function railwayGraphql(token, query, variables = {}) {
    const response = await fetch('https://backboard.railway.com/graphql/v2', {
        method: 'POST',
        headers: {
            Authorization: `Bearer ${token}`,
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ query, variables }),
    });

    if (!response.ok) {
        throw new Error(`Railway GraphQL HTTP ${response.status}`);
    }

    const payload = await response.json();

    if (payload.errors?.length) {
        throw new Error(
            `Railway GraphQL error: ${payload.errors.map((error) => error.message).join('; ')}`,
        );
    }

    return payload.data;
}

/**
 * @param {{
 *   token: string,
 *   projectId?: string,
 *   environmentId?: string,
 * }} options
 */
export async function fetchStagingDeployments(options) {
    const projectId = options.projectId ?? STAGING_PROJECT_ID;
    const environmentId = options.environmentId ?? STAGING_ENVIRONMENT_ID;
    const data = await railwayGraphql(
        options.token,
        `query StagingDeployments($projectId: String!, $environmentId: String!) {
      project(id: $projectId) {
        services {
          edges {
            node {
              id
              name
              deployments(
                first: 5
                input: { environmentId: $environmentId }
              ) {
                edges {
                  node {
                    id
                    status
                    meta
                  }
                }
              }
            }
          }
        }
      }
    }`,
        { projectId, environmentId },
    );

    /** @type {Array<{ serviceName: string, status: string, meta?: unknown }>} */
    const deployments = [];

    for (const edge of data?.project?.services?.edges ?? []) {
        const service = edge.node;
        const latest = service.deployments?.edges?.[0]?.node;

        if (!latest) {
            continue;
        }

        deployments.push({
            serviceName: service.name,
            status: latest.status,
            meta: latest.meta,
        });
    }

    return deployments;
}

async function main(argv = process.argv.slice(2)) {
    const args = parseArgs(argv);

    if (args.help) {
        console.log(`Usage: node scripts/staging/accept.mjs --sha <sha> [options]

Options:
  --base-url <url>           default https://staging.artfct.dev
  --authorize-mutations      allow shared-staging mutations
  --mutating                 this run performs mutations
  --environment-name <name>  must be staging for mutations
  --deployments-json <file>  fixture/API dump for SHA checks
  --changed-paths <csv>      paths for risk policy summary
  --force                    mark staging acceptance as warranted
  --skip-sha                 do not claim SHA verification (explicit)
`);
        return 0;
    }

    /** @type {Array<{ serviceName: string, status: string, meta?: unknown }> | undefined} */
    let deployments;

    if (args.deploymentsJson) {
        deployments = JSON.parse(
            readFileSync(String(args.deploymentsJson), 'utf8'),
        );
    }

    const token = process.env.RAILWAY_API_TOKEN?.trim();

    const result = await runStagingAcceptance({
        sha: String(args.sha ?? ''),
        baseUrl: String(args.baseUrl ?? STAGING_BASE_URL),
        authorizeMutations: args.authorizeMutations === true,
        mutating: args.mutating === true,
        environmentName: String(args.environmentName ?? 'staging'),
        deployments,
        changedPaths: String(args.changedPaths ?? '')
            .split(',')
            .map((path) => path.trim())
            .filter(Boolean),
        force: args.force === true,
        skipSha: args.skipSha === true,
        fetchDeployments:
            !deployments && token && args.skipSha !== true
                ? () => fetchStagingDeployments({ token })
                : undefined,
    });

    if (!result.ok) {
        console.error(JSON.stringify(result, null, 2));
        return 1;
    }

    console.log(JSON.stringify(result, null, 2));
    return 0;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
    main().then((code) => process.exit(code));
}
