const apiToken = process.env.CLOUDFLARE_API_TOKEN;
const zoneId = process.env.CLOUDFLARE_ZONE_ID;
const zoneName = process.env.CLOUDFLARE_ZONE_NAME ?? 'artfct.dev';

if (!apiToken) {
    console.error('Set CLOUDFLARE_API_TOKEN before running this command.');
    process.exit(1);
}

const apiBaseUrl = 'https://api.cloudflare.com/client/v4';

const managedRules = [
    {
        ref: 'artfct_create_artifact_rate_limit',
        description:
            'Artifact creation: 60 POST /v1/artifacts requests per minute per IP',
        expression: `(http.host eq "${zoneName}" and http.request.method eq "POST" and http.request.uri.path eq "/v1/artifacts")`,
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
        expression: `(http.host eq "${zoneName}" and http.request.method eq "GET" and starts_with(http.request.uri.path, "/p/"))`,
        action: 'block',
        ratelimit: {
            characteristics: ['cf.colo.id', 'ip.src'],
            period: 10,
            requests_per_period: 34,
            mitigation_timeout: 10,
            requests_to_origin: false,
        },
    },
];

const requiredRules = managedRules.slice(0, 1);

const headers = {
    Authorization: `Bearer ${apiToken}`,
    'Content-Type': 'application/json',
};

const responseJson = async (response) => {
    const body = await response.json();

    if (!response.ok || !body.success) {
        throw new Error(JSON.stringify(body.errors ?? body, null, 2));
    }

    return body.result;
};

const cloudflare = async (path, options = {}) => {
    const response = await fetch(`${apiBaseUrl}${path}`, {
        ...options,
        headers: {
            ...headers,
            ...options.headers,
        },
    });

    return responseJson(response);
};

const findZone = async () => {
    if (zoneId) {
        return {
            id: zoneId,
            name: zoneName,
        };
    }

    const zones = await cloudflare(
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

const getRateLimitEntrypoint = async (zoneId) => {
    try {
        return await cloudflare(
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

const saveRateLimits = async (zone, entrypoint, rules) => {
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
              `/zones/${zone.id}/rulesets/phases/http_ratelimit/entrypoint`,
              {
                  method: 'PUT',
                  body: JSON.stringify(ruleset),
              },
          )
        : await cloudflare(`/zones/${zone.id}/rulesets`, {
              method: 'POST',
              body: JSON.stringify({
                  ...ruleset,
                  name: 'Artifact Engine rate limits',
                  kind: 'zone',
                  phase: 'http_ratelimit',
              }),
          });
};

const upsertRateLimits = async () => {
    const zone = await findZone();
    const entrypoint = await getRateLimitEntrypoint(zone.id);

    let result;
    let appliedRules = managedRules;

    try {
        result = await saveRateLimits(zone, entrypoint, managedRules);
    } catch (error) {
        if (
            !String(error.message).includes(
                'exceeded the maximum number of rules',
            )
        ) {
            throw error;
        }

        appliedRules = requiredRules;
        result = await saveRateLimits(zone, entrypoint, appliedRules);
        console.warn(
            `Cloudflare only allows ${appliedRules.length} rate-limit rule for this zone. Applied the artifact creation rule only.`,
        );
    }

    console.log(
        `Applied ${appliedRules.length} rate-limit rules to ${zoneName}.`,
    );
    console.log(`Ruleset: ${result.id}`);
};

upsertRateLimits().catch((error) => {
    console.error(error.message);
    process.exit(1);
});
