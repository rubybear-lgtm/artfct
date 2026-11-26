---
name: verify-artfct
description: "Verify Artfct through its browser UI, hosted MCP, or Worker using the repository's existing checks. Use when asked for /verify-artfct or to prove an Artfct change works."
---

# Verify Artfct

## Launch

Use a dedicated worktree. From its root, prepare the same browser build as CI:

```sh
composer install --no-interaction --prefer-dist
npm ci
npx playwright install chromium
test -f .env || cp .env.example .env
php artisan key:generate --no-interaction
npm run build
```

Generate the key only in the disposable worktree with its own test configuration. Pest launches the browser and Laravel test server when you run the mapped command. The browser tests seed their own fixtures. They use the testing database in `phpunit.xml` and require no WorkOS credentials.

## Doctor

From the worktree root, check the revision, build and Chromium paths before driving the UI:

```sh
git rev-parse HEAD &&
test -f vendor/autoload.php &&
test -f public/build/manifest.json &&
node -e 'require("node:fs").accessSync(require("playwright").chromium.executablePath(), require("node:fs").constants.X_OK)'
```

Rebuild after frontend changes. Confirm the selected test reaches its page and assertions before treating the instance as usable.

## Drive

- Choose a journey from the [feature map](features/README.md). Run its existing browser test, narrowing with `--filter` when appropriate.
- For hosted MCP or Worker behavior that needs real services, use `scripts/mcp-e2e-stack.sh run` in a dedicated checkout with Docker. Coordinate with other local users first because the stack uses shared local ports. Consult the script before running it.
- Use isolated PR CI for ordinary changes. Follow [Staging acceptance](../../../STAGING.md) when deployed behavior matters. Confirm the exact commit on staging and require explicit authorization for shared mutations. Production and PR previews are outside this verification path.

## Evidence

Keep the command, commit, exit status and test output in a directory outside the worktree. Use `mktemp -d /tmp/artfct-verification.XXXXXX` and report the path. Name the user action and the visible result. Include persisted state when the behavior changes it. Capture before and after screenshots when the PR changes UI.

## Cleanup

Pest stops the browser and test server when the test exits. If you started the MCP stack with `up`, use `scripts/mcp-e2e-stack.sh down` on that same stack. The `run` command handles teardown. Retain the evidence directory after cleanup.
