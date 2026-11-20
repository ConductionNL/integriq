# AGENTS.md

Guide for coding agents working in this repository. Every command below is defined in
`composer.json` or `package.json` in this repo. Translation work has its own rules in
`CLAUDE.md`; read it before touching `l10n/`.

## What this app is

Integriq is an API gateway and integration hub for Nextcloud: it connects REST and SOAP
sources, exposes API endpoints, transforms data with mappings and keeps systems in sync
through scheduled or event-driven synchronizations. It requires OpenRegister at runtime;
every entity it manages is stored as an OpenRegister object.

## Setup

- Install dependencies: `composer install` and `npm ci` (Node version in `.nvmrc`).
  Both set `core.hooksPath` to `.githooks`.
- Development stack: `docker compose -f docker-compose.yml up -d` mounts this checkout at
  `/var/www/html/custom_apps/integriq` in a Nextcloud container on port 8080.
- Evaluation stack: `docker compose -f docker/docker-compose.yml up -d --build`, then open
  `http://localhost:8080/apps/integriq/` (see `README.md`, "Try it with Docker").
- Demo stack: `docker compose -f integriq-compose.yaml up -d` (port 8602). It is a demo,
  not a development environment.

## Build

- `npm run build` builds the production frontend bundle with webpack.
- `npm run dev` builds a development bundle; `npm run watch` rebuilds on change.

## Test

- PHP unit tests (PHPUnit): `composer test:unit`
- PHP coverage gate: `composer test:coverage` then `composer coverage:check`
- JavaScript unit tests (Vitest, `tests/vitest`): `npm run test`
- End-to-end tests (Playwright): `npm run test:e2e`, or `npm run test:regression` for the
  regression project. Both need a running Nextcloud with OpenRegister installed and seeded.
- API tests (Newman): `npm run test:newman`, against a running dev container.

## Lint and quality

- Full PHP gate: `composer check:strict` (legacy-type and route checks, lint, phpcs, phpmd,
  psalm, phpstan, tests). `composer check` is the quicker subset.
- Single tools: `composer phpcs`, `composer phpmd`, `composer psalm`, `composer phpstan`;
  auto-fix with `composer phpcs:fix` or `composer cs:fix`.
- Frontend: `npm run lint` (ESLint), `npm run stylelint` and `npm run format` (Prettier check).
- Config and spec files: `npm run check:specs` (strict JSON, manifest, register, connector
  templates).

## OpenSpec flow

Work is spec-driven (`openspec/config.yaml`).

1. Propose a change in `openspec/changes/<change-name>/` with a proposal, design, tasks and
   delta specs.
2. When the change is implemented, its requirements land in `openspec/specs/<capability>/spec.md`.
3. The finished change moves to `openspec/changes/archive/`.

The pre-commit hook in `.githooks/pre-commit` regenerates `docs/features.json` when
`openspec/specs/` changes.

## Architecture decisions

ADRs for this app live in `openspec/architecture/`; the index is
`openspec/architecture/README.md`. `ARCHITECTURE.md` gives the overview.

## Repo conventions

- OpenRegister is a required runtime dependency. Do not reimplement in this app what
  OpenRegister already provides (`openspec/config.yaml`).
- Backend code follows Controller, then Service, then Mapper layering (`lib/Controller/`,
  `lib/Service/`).
- Pages are manifest config: `src/manifest.json` plus the fragments in `src/manifest.d/`.
- Connector templates and example configurations are data under `lib/Settings/`, not code.

## Commits

Use [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/), for example
`feat(mapping): ...` or `fix(endpoint): ...`. See `CONTRIBUTING.md`.
