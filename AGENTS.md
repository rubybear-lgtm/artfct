<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/framework (LARAVEL) - v13
- laravel/mcp (MCP) - v0
- laravel/prompts (PROMPTS) - v0
- laravel/wayfinder (WAYFINDER) - v0
- laravel/boost (BOOST) - v2
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/react (INERTIA_REACT) - v3
- react (REACT) - v19
- tailwindcss (TAILWINDCSS) - v4
- @laravel/vite-plugin-wayfinder (WAYFINDER_VITE) - v0
- eslint (ESLINT) - v9
- prettier (PRETTIER) - v3

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>

## Implementation invariants (confirmed by running the code, not by reading docs)

Check these before touching storage, request routing, identity, cryptography, or any service with more than one caller. Each one was found the hard way.

**Storage shape decides routing.** D1 rows are addressable through the app's routes; anonymous KV records are not, because the app's content read selects from D1 only (`backend/src/lib.rs`). A rule derived from a D1-backed caller is usually wrong for a KV-backed one — enumerate callers before changing a shared service.

**Only the transport peer is beyond the caller's reach.** Headers, session ids, JWT claims and query parameters are all caller-written. Never key a trust, throttle or authorization decision on one.

**Fail-closed controls hide gaps.** `verify_access_token` rejects on an empty secret, so a harness that never set that secret reports every request as correctly refused while never exercising the path at all. A security control needs a test that fails when the control is removed.

**Platform behaviour must be run, not read.**
- `isset($a, $b, $c)` is an AND, not an OR.
- `PRAGMA foreign_keys` is per-connection and defaults OFF, so `ON DELETE CASCADE` silently does not fire.
- `wrangler dev` replaces the request `Host` with the host inferred from the first `routes` entry, and no flag disables it — run the dev session from a config copy with `routes` stripped.
- Cloudflare route patterns allow exactly one wildcard and it must BEGIN the hostname; `*.artfct.dev/*` is the only expressible form and it over-matches `staging.artfct.dev`.
- PHP and browsers parse URLs differently, so validating with one and navigating with the other leaves a gap.

**A merged fix on a stale deployment is not live.** Confirm the running version carries the change, not just the branch.

**Cross-language contracts need one shared fixture.** The same algorithm implemented in Rust, TypeScript and PHP drifts silently unless a single test vector or fixture is asserted from all of them.

**Registries, matrices and runbooks are claim sets** and drift like prose — `tests/Fixtures/mcp-verification-matrix.php` and `docs/mcp-cli-runbook.md` have each carried claims the code contradicted.

**Stage coverage is not flow coverage**, and an entry point with no test is invisible to every other check.


## Design Context

### Users
Developers and technical professionals. Devs are the core audience but other professionals (designers, PMs, marketers) who occasionally deal with HTML files would also find it useful. The landing page should be approachable — technical personality without a "no entry" sign for non-devs.

### Brand Personality
Bold · Confident · Fast. artfct is a precision tool, not a SaaS platform. Simple to the point of being obvious: drop an HTML file, get a link. That's it.

**Voice**: Direct, terse, a little dry. Good README energy with personality.

### Aesthetic Direction
**Retrofuturistic** — the warmth of old-school CLI tools meets modern sensibility. References: ASCII art interfaces of Gemini CLI, Codex, Cursor, Claude Code.

**Color palette**: Solarized Light.
- Background: `#FDF6E3`, Highlights: `#EEE8D5`, Secondary: `#93A1A1`, Body: `#657B83`/`#586E75`, Dark: `#073642`
- Accent colors: Yellow `#B58900`, Orange `#CB4B16`, Red `#DC322F`, Magenta `#D33682`, Violet `#6C71C4`, Blue `#268BD2`, Cyan `#2AA198`, Green `#859900`

**ASCII art hero**: Large bold ASCII letterforms with each character/column colored using randomized-but-complementary solarized accent colors. Gradients across letterforms — warm-to-cool or analogous combos.

**Typography**: Instrument Sans for UI text. Monospace stack for ASCII art, IDs, code output.

### Design Principles
1. **The page is the product** — The upload element is the hero. Drop a file, get a link.
2. **ASCII art as brand voice** — The ASCII letterforms are the identity. Crafted, not generated.
3. **Solarized warmth** — Cream backgrounds, rich accent pops, nothing harsh.
4. **Gradient as personality** — The randomized ASCII gradient is the one expressive element. Everywhere else: restraint.
5. **Approachable precision** — Technical enough for devs, simple enough for everyone else. One input. One button. Done.

## artfct Project Notes

**What it is** — artfct publishes self-contained HTML to artfct.dev and returns a shareable, encrypted link. This repo is a Cargo workspace plus a Laravel control plane:

- `backend/` — Cloudflare Worker (Rust → wasm) named `artfct-engine`; serves `artfct.dev/v1/*` (API) and `artfct.dev/p/*` (artifact delivery). Bindings: `ARTIFACTS_KV`, `ARTIFACTS_DB` (D1), `ARTIFACTS_BUCKET` (R2).
- `mcp-server/` — Rust CLI + MCP server binary exposing `deploy_to_canvas` and `search_artifacts`.
- `app/`, `routes/`, `resources/` — Laravel 13 + Inertia React control plane (browser UI, docs, teams, billing, admin). Architecture reference: `DOCUMENTATION.md` and `docs/`.

**Local development**

- `composer setup`, then `composer dev` (PHP server + queue + Pail logs + Vite in one process).
- Worker: `npm run worker:dev` (wrangler dev, port 8787) or `npm run worker:dev:local` (port 8788, sets `ARTFCT_PUBLIC_BASE_URL`). Point the frontend at it with `VITE_WORKER_URL` in `.env`.
- `herd sites` reports no sites for this repo, so the Herd block above does not apply — run the app with `composer dev` (or `php artisan serve`).
- Login uses WorkOS AuthKit; tests get an injectable fake client, so no credentials are needed.

**Verification gates** — `.githooks/pre-commit` (wired via `core.hooksPath`) runs, per staged file type:

- always: gitleaks secret scan on staged files
- Rust staged: `cargo fmt --all -- --check`, `cargo check --workspace --locked`, `cargo check -p artfct-backend --target wasm32-unknown-unknown`, `cargo clippy --workspace --all-targets -- -D warnings`, `cargo test -p artfct`, `cargo run -p artfct -- doctor`
- PHP staged: `vendor/bin/pint --test --format agent`
- frontend staged: `npm run format:check`, `npm run lint:check`, `npm run types:check`

`composer test` runs config clear + Pint check + Pest; `composer ci:check` runs the full frontend and test suite. A failing hook is a real failure — fix it, do not bypass with `--no-verify`.

**Deploying** — `npm run worker:deploy` (wraps `scripts/deploy-worker-version.mjs`); worker routes and bindings live in `backend/wrangler.jsonc`.

**Agent output** — for any visual HTML, call `deploy_to_canvas` rather than emitting a code block: tier `public` by default, `ephemeral` + `ttl_minutes` for throwaway previews, `secure` for sensitive content. Deployable skills live in `skills/` (`artfct`, `developer-tools`, `presentation`); Boost-managed skills live in `.agents/skills/`.

**Stitch MCP (design work)** — the `Artfct Editorial Terminal` design system and the landing-page explorations live in a Stitch project; the landing page they vary is `resources/js/pages/welcome.tsx`.

Auth lives in `~/.config/mcp/mcp.json` and uses your gcloud ADC login. The Stitch API **rejects API keys outright** — there is no key to extract, and a `X-Goog-Api-Key` header will always fail with "API keys are not supported by this API". It needs an OAuth2 token plus a GCP quota project (`live-music-broadcast`, sent as `X-Goog-User-Project`) with `stitch.googleapis.com` enabled. Do not set ADC's own `quota_project_id` — that silently re-attributes every other Google tool on the machine. `requestTimeoutMs: 300000` is required: generation routinely runs past the default ~60s client timeout.

When calling the Stitch tools:

- `projectId` params take the **bare** id (`15827176938049879405`). The `projects/` prefix fails with a misleading "Requested entity was not found" — not an "invalid argument" — so a prefix error looks like a missing resource. `name` params (`get_project`) and screen resource names *do* use `projects/X`.
- `designSystem` takes the slash form, `assets/221530ab59d147aeaca51dc6a493a15b`.
- Generated screens **never appear in `list_screens`**. The deliverable is the generation response's `outputComponents[].design.screens[]`, which carries `screenshot.downloadUrl` and `htmlCode.downloadUrl`. Surface that payload whole — anything you truncate in transit is unrecoverable and forces a full regeneration. The adapter spills oversized output to a temp file you can re-read, so there is never a reason to slice it.
- Generate variations **sequentially**, not in parallel. Parallel calls widen each call past the timeout, and a timed-out call loses its URLs permanently even though generation continues server-side.

**Generated content** — everything inside `<laravel-boost-guidelines>` is regenerated by `php artisan boost:update`. Write custom guidance outside that block; the writer preserves it.
