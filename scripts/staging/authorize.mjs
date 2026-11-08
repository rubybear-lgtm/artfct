/**
 * Explicit authorization gate for shared-staging mutations (RUB-518).
 */

/**
 * @param {{
 *   authorizeMutations: boolean,
 *   environmentName: string,
 *   mutating: boolean,
 * }} options
 */
export function assertMutationAuthorized(options) {
    const environmentName = (options.environmentName ?? '').trim();

    if (!options.mutating) {
        return {
            ok: true,
            mutating: false,
            environmentName,
        };
    }

    if (environmentName !== 'staging') {
        return {
            ok: false,
            code: 'environment_not_staging',
            message: `Refusing mutations outside staging (got ${environmentName || '(empty)'}). Preview and production are never mutation targets for this flow.`,
        };
    }

    if (!options.authorizeMutations) {
        return {
            ok: false,
            code: 'mutations_not_authorized',
            message:
                'Mutating shared staging requires authorize_mutations=true. Default acceptance is read-only.',
        };
    }

    return {
        ok: true,
        mutating: true,
        environmentName: 'staging',
        authorizeMutations: true,
    };
}
