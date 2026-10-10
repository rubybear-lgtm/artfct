#!/usr/bin/env node
// RUB-435: tenant isolation, end to end. Two real teams, created by real
// signed-in users through the app's own flows (dev-login stand-in, team
// creation, OAuth consent), and credentials minted by Laravel, not fabricated.
// Every check then tries to cross from one team into the other, against the
// real Laravel app and the real Worker.
//
// Run against the stack from scripts/mcp-e2e-stack.sh:
//   scripts/mcp-e2e-stack.sh isolation
//
// Requires AUTHKIT_DEV_LOGIN_ENABLED=true on the target (never true in
// production). Exits non-zero if any check fails.
import { baseline } from './isolation/baseline.mjs';
import { failureCount, laravel, run, worker } from './isolation/lib.mjs';
import { matrix } from './isolation/matrix.mjs';
import { originLink } from './isolation/origin-link.mjs';
import { revocation } from './isolation/revocation.mjs';
import { roles } from './isolation/roles.mjs';
import { webRoutes } from './isolation/web-routes.mjs';

const scenarios = [baseline, webRoutes, originLink, matrix, roles, revocation];

async function main() {
    console.log(
        `tenant isolation e2e (run ${run})  laravel=${laravel}  worker=${worker}`,
    );

    const world = {};

    for (const scenario of scenarios) {
        console.log(`\n== ${scenario.name}`);
        await scenario(world);
    }

    const failures = failureCount();
    console.log(
        failures === 0
            ? '\nALL ISOLATION CHECKS PASSED'
            : `\n${failures} ISOLATION CHECK(S) FAILED`,
    );
    process.exit(failures === 0 ? 0 : 1);
}

main().catch((error) => {
    console.error(`\nERROR ${error.stack ?? error.message}`);
    process.exit(2);
});
