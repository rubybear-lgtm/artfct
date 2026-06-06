# Contributing

Thanks for taking the time to contribute to artfct.

## Project Structure

```
backend/          # Cloudflare Worker (Rust → Wasm)
mcp-server/       # CLI + MCP server binary (Rust)
resources/        # Laravel frontend (React + Inertia + Tailwind)
  ├── js/pages/   # Page components (welcome, docs, blog)
  ├── js/lib/     # Shared utilities (theme, markdown)
  ├── css/        # Stylesheets
  └── views/      # Blade layouts
skills/           # Agent skills for AI tooling
routes/           # Laravel routes
```

## Development Setup

### Frontend

Requirements: PHP 8.5, Composer, Node 22.

```sh
composer setup      # install deps, copy .env, generate key, run migrations
composer dev        # PHP server + queue + Vite dev server
```

### Worker

Requirements: Rust stable, `wrangler`.

```sh
npm run worker:kv:create   # create KV namespace (once)
npm run worker:dev         # local worker on http://localhost:8787
npm run worker:deploy      # deploy to Cloudflare
```

### CLI

```sh
cargo build -p artfct              # debug build
cargo test -p artfct               # run tests
cargo run -p artfct -- deploy --help
```

## Checks

Run all checks before submitting:

```sh
npm run lint:check      # ESLint
npm run format:check    # Prettier
npm run types:check     # TypeScript
npm run build           # Vite production build
php artisan test        # Pest
composer ci:check       # all of the above
```

## Pull Requests

- Keep PRs focused on a single concern
- Write clear commit messages
- Update docs if you change behavior
- Make sure tests pass

## Code Style

This project uses:
- **ESLint** with TypeScript and React plugins for JS/TS
- **Prettier** with Tailwind plugin for formatting
- **Pint** (Laravel) for PHP
- **Rustfmt** for Rust

Pre-commit hooks are configured via `.githooks/`. Run `npm run prepare` to enable them.

## Design Language

artfct uses a Solarized Light palette with retrofuturistic ASCII art branding. See `.impeccable.md` for the full design context.

## Questions?

Open a [discussion](https://github.com/rubybear-lgtm/artfct/discussions) or file an issue.
