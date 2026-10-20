import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const backend = resolve(root, 'backend');
const wrangler = resolve(root, 'node_modules/.bin/wrangler');
const defaultConfig = resolve(backend, 'wrangler.jsonc');
const databaseBinding = 'ARTIFACTS_DB';
const requiredVars = [
    'ARTFCT_PUBLIC_BASE_URL',
    'ARTFCT_ARTIFACT_ORIGIN_SUFFIX',
];
const requiredBindings = [
    { field: 'kv_namespaces', binding: 'ARTIFACTS_KV' },
    { field: 'd1_databases', binding: databaseBinding },
    { field: 'r2_buckets', binding: 'ARTIFACTS_BUCKET' },
];
const smokeTimeoutMs = 15000;

export function parseArgs(argv) {
    const options = {
        config: defaultConfig,
        dryRun: false,
        applyMigrations: false,
        deployTriggers: false,
        percentage: 100,
        smokeUrl: null,
    };

    for (let index = 0; index < argv.length; index += 1) {
        const flag = argv[index];
        const readValue = () => {
            const value = argv[index + 1];

            if (value === undefined || value.startsWith('--')) {
                throw new Error(`${flag} needs a value.`);
            }

            index += 1;

            return value;
        };

        switch (flag) {
            case '--config':
                options.config = resolve(process.cwd(), readValue());
                break;
            case '--dry-run':
                options.dryRun = true;
                break;
            case '--apply-migrations':
                options.applyMigrations = true;
                break;
            case '--deploy-triggers':
                options.deployTriggers = true;
                break;
            case '--percentage': {
                const percentage = Number(readValue());

                if (
                    !Number.isInteger(percentage) ||
                    percentage < 1 ||
                    percentage > 100
                ) {
                    throw new Error(
                        '--percentage must be a whole number from 1 to 100.',
                    );
                }

                options.percentage = percentage;
                break;
            }
            case '--smoke-url': {
                const smokeUrl = readValue();

                if (!isHttpUrl(smokeUrl)) {
                    throw new Error(
                        '--smoke-url must be an http or https URL.',
                    );
                }

                options.smokeUrl = smokeUrl;
                break;
            }
            default:
                throw new Error(`Unknown option: ${flag}`);
        }
    }

    return options;
}

function isHttpUrl(value) {
    try {
        const url = new URL(value);

        return url.protocol === 'https:' || url.protocol === 'http:';
    } catch {
        return false;
    }
}

/**
 * Parses a wrangler config, which is JSONC: comments and trailing commas are
 * stripped outside string literals, so URLs such as "https://artfct.dev" survive.
 */
export function parseJsonc(text) {
    const withoutComments = text.replace(
        /("(?:\\.|[^"\\])*")|\/\/[^\n]*|\/\*[\s\S]*?\*\/|,(?=\s*[}\]])/g,
        (match, string) => string ?? '',
    );

    return JSON.parse(withoutComments);
}

export function readWranglerConfig(configPath) {
    return parseJsonc(readFileSync(configPath, 'utf8'));
}

export function validateWranglerConfig(config) {
    const errors = [];

    if (!Array.isArray(config.routes) || config.routes.length === 0) {
        errors.push('routes is empty, so the Worker would receive no traffic.');
    }

    for (const name of requiredVars) {
        if (
            typeof config.vars?.[name] !== 'string' ||
            config.vars[name].trim() === ''
        ) {
            errors.push(`vars.${name} is not set.`);
        }
    }

    for (const { field, binding } of requiredBindings) {
        const bindings = Array.isArray(config[field]) ? config[field] : [];

        if (!bindings.some((entry) => entry?.binding === binding)) {
            errors.push(`${field} has no "${binding}" binding.`);
        }
    }

    // Without --locked, worker-build 0.8.3 resolves to yanked zip 2.x and the build fails.
    const buildCommand = config.build?.command ?? '';

    if (
        !buildCommand.includes('worker-build') ||
        !buildCommand.includes('--locked')
    ) {
        errors.push(
            'build.command must install and run worker-build with --locked.',
        );
    }

    return errors;
}

export function parseVersionId(uploadOutput) {
    return (
        uploadOutput.match(/Worker Version ID:\s*([a-f0-9-]+)/i)?.[1] ?? null
    );
}

/**
 * Returns the version id carrying the most traffic in `wrangler deployments status --json`.
 * The JSON shape is searched rather than hard-coded, so a wrapped or nested layout still resolves.
 */
export function parseCurrentVersionId(statusOutput) {
    let parsed;

    try {
        parsed = JSON.parse(statusOutput);
    } catch {
        return null;
    }

    const entries = findVersionEntries(parsed) ?? [];
    const versions = entries
        .map((entry) => ({
            id: entry.version_id ?? entry.versionId,
            percentage: entry.percentage ?? 100,
        }))
        .filter((version) => typeof version.id === 'string')
        .sort((first, second) => second.percentage - first.percentage);

    return versions[0]?.id ?? null;
}

function findVersionEntries(value) {
    if (Array.isArray(value)) {
        const hasVersions = value.some(
            (item) =>
                item &&
                typeof item === 'object' &&
                ('version_id' in item || 'versionId' in item),
        );

        if (hasVersions) {
            return value;
        }

        for (const item of value) {
            const found = findVersionEntries(item);

            if (found) {
                return found;
            }
        }

        return null;
    }

    if (value && typeof value === 'object') {
        for (const child of Object.values(value)) {
            const found = findVersionEntries(child);

            if (found) {
                return found;
            }
        }
    }

    return null;
}

export function isSmokeSuccess(status) {
    return status >= 200 && status < 300;
}

export function buildStatusArgs(configPath) {
    return ['deployments', 'status', '--json', '--config', configPath];
}

export function buildMigrationArgs(configPath) {
    return [
        'd1',
        'migrations',
        'apply',
        databaseBinding,
        '--remote',
        '--config',
        configPath,
    ];
}

/**
 * `versions upload` runs the config's build.command, so this is the one and only build.
 */
export function buildUploadArgs({ configPath, message, tag }) {
    const args = [
        'versions',
        'upload',
        '--message',
        message,
        '--config',
        configPath,
    ];

    if (tag) {
        args.push('--tag', tag);
    }

    return args;
}

export function buildVersionDeployArgs({
    configPath,
    versionId,
    percentage,
    currentVersionId,
    message,
}) {
    let specs = [`${versionId}@100`];

    if (percentage !== 100) {
        if (!currentVersionId) {
            throw new Error(
                'A gradual rollout needs the current version to split traffic with.',
            );
        }

        specs = [
            `${versionId}@${percentage}`,
            `${currentVersionId}@${100 - percentage}`,
        ];
    }

    return [
        'versions',
        'deploy',
        ...specs,
        '--yes',
        '--message',
        message,
        '--config',
        configPath,
    ];
}

export function buildTriggersArgs(configPath) {
    return ['triggers', 'deploy', '--config', configPath];
}

export function rollbackCommand(configPath, currentVersionId) {
    if (!currentVersionId) {
        return null;
    }

    return formatCommand(
        buildVersionDeployArgs({
            configPath,
            versionId: currentVersionId,
            percentage: 100,
            message: 'Rollback',
        }),
    );
}

function formatCommand(args) {
    const quoted = args.map((arg) =>
        /^[\w@%./:=-]+$/.test(arg) ? arg : `'${arg.replaceAll("'", "'\\''")}'`,
    );

    return [wrangler, ...quoted].join(' ');
}

function runWrangler(args, { quiet = false } = {}) {
    const result = spawnSync(wrangler, args, {
        cwd: backend,
        encoding: 'utf8',
    });

    if (result.error) {
        throw result.error;
    }

    if (result.stdout && !quiet) {
        process.stdout.write(result.stdout);
    }

    if (result.stderr) {
        process.stderr.write(result.stderr);
    }

    if (result.status !== 0) {
        throw new Error(
            `${formatCommand(args)} exited with ${result.status ?? 1}.`,
        );
    }

    return result.stdout ?? '';
}

async function smokeTest(url) {
    const response = await fetch(url, {
        signal: AbortSignal.timeout(smokeTimeoutMs),
    });

    return response.status;
}

function printDryRunPlan(options, message, tag) {
    const configPath = options.config;
    const { percentage, smokeUrl } = options;
    const placeholderCurrent = '<current-version-id>';
    const steps = [formatCommand(buildStatusArgs(configPath))];

    if (options.applyMigrations) {
        steps.push(formatCommand(buildMigrationArgs(configPath)));
    }

    steps.push(formatCommand(buildUploadArgs({ configPath, message, tag })));
    steps.push(
        formatCommand(
            buildVersionDeployArgs({
                configPath,
                versionId: '<uploaded-version-id>',
                percentage,
                currentVersionId: placeholderCurrent,
                message,
            }),
        ),
    );

    if (options.deployTriggers) {
        steps.push(formatCommand(buildTriggersArgs(configPath)));
    }

    if (smokeUrl) {
        steps.push(`GET ${smokeUrl} (on failure, roll back)`);
    }

    console.log(`Config valid: ${configPath}`);
    console.log('Dry run: nothing was executed. Commands in order:');
    steps.forEach((step, index) => console.log(`  ${index + 1}. ${step}`));
    console.log(
        `Rollback target: ${rollbackCommand(configPath, placeholderCurrent)}`,
    );
}

export async function main(argv) {
    const options = parseArgs(argv);
    const { config: configPath } = options;

    if (!existsSync(configPath)) {
        throw new Error(`Config not found: ${configPath}`);
    }

    const errors = validateWranglerConfig(readWranglerConfig(configPath));

    if (errors.length > 0) {
        throw new Error(
            `${configPath} failed validation:\n  - ${errors.join('\n  - ')}`,
        );
    }

    const tag = process.env.GITHUB_SHA
        ? process.env.GITHUB_SHA.slice(0, 12)
        : undefined;
    const message = tag ? `Deploy ${tag}` : 'Deploy Worker version';

    if (options.dryRun) {
        printDryRunPlan(options, message, tag);

        return;
    }

    const currentVersionId = parseCurrentVersionId(
        runWrangler(buildStatusArgs(configPath), { quiet: true }),
    );

    if (currentVersionId) {
        console.log(
            `Current production version (rollback target): ${currentVersionId}`,
        );
    } else {
        console.warn(
            'No current production version was found. This deploy has no recorded rollback target.',
        );
    }

    if (options.applyMigrations) {
        runWrangler(buildMigrationArgs(configPath));
    }

    const versionId = parseVersionId(
        runWrangler(buildUploadArgs({ configPath, message, tag })),
    );

    if (!versionId) {
        throw new Error(
            'Unable to determine uploaded Worker version ID from Wrangler output.',
        );
    }

    runWrangler(
        buildVersionDeployArgs({
            configPath,
            versionId,
            percentage: options.percentage,
            currentVersionId,
            message,
        }),
    );

    if (options.deployTriggers) {
        runWrangler(buildTriggersArgs(configPath));
    }

    const rollback =
        rollbackCommand(configPath, currentVersionId) ??
        'none recorded; roll back from the Cloudflare dashboard';

    console.log(
        `Promoted ${versionId} to ${options.percentage}%. Roll back with: ${rollback}`,
    );

    if (options.smokeUrl) {
        let status;

        try {
            status = await smokeTest(options.smokeUrl);
        } catch (error) {
            throw new Error(
                `Smoke test ${options.smokeUrl} failed: ${error.message}\nRoll back with: ${rollback}`,
            );
        }

        if (!isSmokeSuccess(status)) {
            throw new Error(
                `Smoke test ${options.smokeUrl} returned ${status}.\nRoll back with: ${rollback}`,
            );
        }

        console.log(`Smoke test ${options.smokeUrl} returned ${status}.`);
    }
}

if (
    process.argv[1] &&
    import.meta.url === pathToFileURL(process.argv[1]).href
) {
    main(process.argv.slice(2)).catch((error) => {
        console.error(error.message);
        process.exitCode = 1;
    });
}
