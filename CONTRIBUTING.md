# Contributing to Splitline

## Prerequisites

- **PHP 8.5+** with `pdo_sqlite`, `zip`, `curl`, `openssl`, `mbstring`, `intl`, `bcmath`, and `pcntl`
- **Composer 2**
- **Node.js 26+** and **npm**
- **SQLite 3**

## Local Setup

```bash
git clone https://github.com/jpgnz/splitline.git
cd splitline
composer setup
composer dev
```

`composer dev` starts the Laravel HTTP server, queue worker, Vite+ dev server, and Pail logs. To sync live data locally, set `GARMIN_KEY` and `TIMEZONE` in `.env` and use the Artisan commands in [README.md](README.md#artisan-commands).

### AI Tooling (Optional)

Splitline includes [Laravel Boost](https://laravel.com/docs/boost) as a dev dependency. If you use an AI coding agent (Claude Code, Cursor, OpenCode, Codex, etc.), run the interactive installer after `composer setup` to generate local MCP config, guidelines, and skills for your agent:

```bash
php artisan boost:install
```

Generated agent files (`boost.json`, `AGENTS.md`, `CLAUDE.md`, `.mcp.json`, and agent folders) are gitignored so each contributor can configure their preferred tooling locally.

## Code Quality & Testing

Run the full CI suite before opening a pull request:

```bash
composer ci:check
```

| Check                   | Command                                             | Purpose                                                        |
| :---------------------- | :-------------------------------------------------- | :------------------------------------------------------------- |
| **Vite+ Lint & Format** | `npm run check` (`npm run check:fix` to auto-fix)   | TypeScript, Vue, and CSS linting & formatting via Oxlint/Oxfmt |
| **Vue TypeScript**      | `npm run types:check`                               | Static type checking with `vue-tsc`                            |
| **Laravel Pint**        | `composer lint:check` (`composer lint` to auto-fix) | PHP formatting                                                 |
| **PHPStan / Larastan**  | `composer types:check`                              | Static analysis at level 7                                     |
| **Pest Test Suite**     | `php artisan test --compact`                        | Backend feature and unit tests                                 |

Run targeted tests while iterating:

```bash
php artisan test --compact tests/Feature/DashboardTest.php
php artisan test --compact --filter="test name"
```

## Conventions

- **Terminology**: Use **GNSS** (never GPS) and **Cycling** (never Biking) in user-facing copy, docs, and new identifiers.
- **Provider-agnostic core**: Keep core config (`TIMEZONE`, `DEFAULT_UNITS`, `DEFAULT_SPORTS_MODE`) independent of any watch vendor; scope Garmin logic to Garmin integration services.
- **SI/metric storage**: Store all telemetry in SI/metric units (`meters`, `grams`, `m/s`, `°C`, `ml`) in SQLite and convert in the presentation layer.
- **Wayfinder routes**: Use generated `@/routes` and `@/actions` helpers instead of hardcoded URLs in Vue components.
- **Laravel & Vue style**: Follow existing service/controller boundaries, use explicit PHP return types and PHPDoc array shapes for PHPStan level 7, and keep Inertia Vue components single-root.

## Pull Requests

1. Fork and branch from `main` with a descriptive name (e.g., `fix/wind-arrow-bearing` or `feat/swim-pace-splits`).
2. Keep PRs focused on a single change.
3. Add or update Pest tests for behavior changes, bug fixes, and important failure modes.
4. Never commit personal data (`.env`, `database/*.sqlite*`, FIT archives, or screenshots containing personal GNSS tracks or health records).
