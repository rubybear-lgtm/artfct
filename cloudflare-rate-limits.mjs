import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const apiBaseUrl = 'https://api.cloudflare.com/client/v4';

/**
 * Every host the artifact Worker answers on. The apex serves /v1 and /p;
 * isolated artifact origins use `<tenant>--<id>--prod.<zone>` in production
 * and `<tenant>--<id>--stg.<zone>` on staging (backend/wrangler*.jsonc).
 */
export const artifactHostClauses = (zoneName) => [
    `http.host eq "${zoneName}"`,
    `ends_with(http.host, "--prod.${zoneName}")`,
    `ends_with(http.host, "--stg.${zoneName}")`,
];

const artifactHostMatch = (zoneName) =>
    `(${artifactHostClauses(zoneName).join(' or ')})`;

export const buildManagedRules = (zoneName) => [
    {
        ref: 'artfct_create_artifact_rate_limit',
        description:
            'Artifact creation: 60 POST /v1/artifacts requests per minute per IP',
        expression: `(${artifactHostMatch(zoneName)} and http.request.method eq "POST" and http.request.uri.path eq "/v1/artifacts")`,
        action: 'block',
        ratelimit: {
            characteristics: ['cf.colo.id', 'ip.src'],
            period: 10,
            requests_per_period: 10,
            mitigation_timeout: 10,
            requests_to_origin: false,
        },
    },
    {
        ref: 'artfct_preview_rate_limit',
        description:
            'Artifact previews: 200 GET /p/* requests per minute per IP',
        expression: `(${artifactHostMatch(zoneName)} and http.request.method eq "GET" and starts_with(http.request.uri.path, "/p/"))`,
        action: 'block',
        ratelimit: {
            characteristics: ['cf.colo.id', 'ip.src'],
            period: 10,
            requests_per_period: 34,
            mitigation_timeout: 10,
            requests_to_origin: false,
        },
    },
    {
        ref: 'artfct_manage_rate_limit',
        description:
            'Artifact management: 60 PATCH/DELETE /v1/artifacts/* requests per minute per IP',
        expression: `(${artifactHostMatch(zoneName)} and (http.request.method eq "PATCH" or http.request.method eq "DELETE") and starts_with(http.request.uri.path, "/v1/artifacts/"))`,
        action: 'block',
        ratelimit: {
            characteristics: ['cf.colo.id', 'ip.src'],
            period: 10,
            requests_per_period: 10,
            mitigation_timeout: 10,
            requests_to_origin: false,
        },
    },
];

/**
 * Refs of managed rules that a save did not apply.
 *
 * @param {{ ref: string }[]} managed
 * @param {{ ref: string }[]} applied
 * @returns {string[]}
 */
export const unappliedRuleRefs = (managed, applied) =>
    managed
        .filter((rule) => !applied.some((appliedRule) => appliedRule.ref === rule.ref))
        .map((rule) => rule.ref);

const zoneName = process.env.CLOUDFLARE_ZONE_NAME ?? 'artfct.dev';
const managedRules = buildManagedRules(zoneName);
const requiredRules = managedRules.slice(0, 1);

const cloudflare = async (apiToken, path, options = {}) => {
    const response = await fetch(`${apiBaseUrl}${path}`, {
        ...options,
        headers: {
            Authorization: `Bearer ${apiToken}`,
            'Content-Type': 'application/json',
            ...options.headers,
        },
    });

    return responseJson(response);
};

const responseJson = async (response) => {
    const body = await response.json();

    if (!response.ok || !body.success) {
        throw new Error(JSON.stringify(body.errors ?? body, null, 2));
    }

    return body.result;
};

const findZone = async (apiToken) => {
    const zoneId = process.env.CLOUDFLARE_ZONE_ID;

    if (zoneId) {
        return {
            id: zoneId,
            name: zoneName,
        };
    }

    const zones = await cloudflare(
        apiToken,
        `/zones?name=${encodeURIComponent(zoneName)}`,
    );
    const zone = zones.find((candidate) => candidate.name === zoneName);

    if (!zone) {
        throw new Error(
            `Cloudflare zone not found: ${zoneName}. Check that CLOUDFLARE_API_TOKEN has Zone:Read for this zone, or set CLOUDFLARE_ZONE_ID in .env.`,
        );
    }

    return zone;
};

const getRateLimitEntrypoint = async (apiToken, zoneId) => {
    try {
        return await cloudflare(
            apiToken,
            `/zones/${zoneId}/rulesets/phases/http_ratelimit/entrypoint`,
        );
    } catch (error) {
        if (
            String(error.message).includes('"code": 10003') ||
            String(error.message).includes('"code": 10021')
        ) {
            return null;
        }

        throw error;
    }
};

const saveRateLimits = async (apiToken, zone, entrypoint, rules) => {
    const unmanagedRules = (entrypoint?.rules ?? []).filter(
        (rule) =>
            !managedRules.some((managedRule) => managedRule.ref === rule.ref),
    );

    const ruleset = {
        description:
            'Rate limiting rules for Artifact Engine create and preview routes.',
        rules: [...unmanagedRules, ...rules],
    };

    return entrypoint
        ? await cloudflare(
              apiToken,
              `/zones/${zone.id}/rulesets/phases/http_ratelimit/entrypoint`,
              {
                  method: 'PUT',
                  body: JSON.stringify(ruleset),
              },
          )
        : await cloudflare(apiToken, `/zones/${zone.id}/rulesets`, {
              method: 'POST',
              body: JSON.stringify({
                  ...ruleset,
                  name: 'Artifact Engine rate limits',
                  kind: 'zone',
                  phase: 'http_ratelimit',
              }),
          });
};

const upsertRateLimits = async (apiToken) => {
    const zone = await findZone(apiToken);
    const entrypoint = await getRateLimitEntrypoint(apiToken, zone.id);

    let result;
    let appliedRules = managedRules;

    try {
        result = await saveRateLimits(apiToken, zone, entrypoint, managedRules);
    } catch (error) {
        if (
            !String(error.message).includes(
                'exceeded the maximum number of rules',
            )
        ) {
            throw error;
        }

        appliedRules = requiredRules;
        result = await saveRateLimits(apiToken, zone, entrypoint, appliedRules);
        const notApplied = unappliedRuleRefs(managedRules, appliedRules);
        console.error(
            [
                '',
                '!!! WARNING: ARTIFACT RATE LIMITS PARTIALLY APPLIED !!!',
                `Cloudflare only allows ${appliedRules.length} rate-limit rule for ${zoneName} on this plan.`,
                `NOT APPLIED (these routes have no WAF limit): ${notApplied.join(', ')}`,
                '',
            ].join('\n'),
        );
    }

    console.log(
        `Applied ${appliedRules.length} rate-limit rules to ${zoneName}.`,
    );
    console.log(`Ruleset: ${result.id}`);
};

const main = async () => {
    const apiToken = process.env.CLOUDFLARE_API_TOKEN;

    if (!apiToken) {
        console.error('Set CLOUDFLARE_API_TOKEN before running this command.');
        process.exit(1);
    }

    await upsertRateLimits(apiToken);
};

const isEntrypoint =
    process.argv[1] !== undefined &&
    import.meta.url === pathToFileURL(resolve(process.argv[1])).href;

if (isEntrypoint) {
    main().catch((error) => {
        console.error(error.message);
        process.exit(1);
    });
}
