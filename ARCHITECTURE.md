# Architecture

IntegrIQ (app id `integriq`, repository `openconnector`) is a Nextcloud app. It connects Nextcloud to outside systems: REST and SOAP sources, including Dutch government standards such as StUF, ZGW, DSO and Digikoppeling. It maps and synchronises their data into OpenRegister, and exposes endpoints that other systems can call.

This page is for new developers and for anyone who assesses the code, such as SIG. It names the functional components, says where each one starts and stops, and marks every top-level directory as production, test, generated or tooling.

## System boundary

IntegrIQ runs inside a Nextcloud server, in PHP on the server and Vue in the browser.

| Talks to | How |
|---|---|
| OpenRegister | In-process calls to `OCA\OpenRegister\...` services. Synchronised data and most configuration live there. |
| Nextcloud server | The public `OCP` API (users, files, calendar, events, background jobs) |
| External sources and consumers | HTTP and SOAP, with mTLS, OAuth or API keys per source |
| Message brokers | CloudEvents over HTTP or a broker transport |

## Directory roles

| Directory | Role |
|---|---|
| `lib/` | Production PHP, PSR-4 under `OCA\Integriq`. |
| `lib/Settings/` | Register definitions and seed configuration (JSON). Data, not logic. |
| `lib/Migration/` | Database migrations. Frozen once released. |
| `src/` | Production front end (Vue 3, TypeScript and JavaScript). |
| `appinfo/`, `templates/` | App manifest, route table and the PHP pages that mount the front end. |
| `tests/` | Test code only: PHPUnit (`Unit/`, `Integration/`), Playwright (`e2e/`), Vitest (`vitest/`), helpers, stubs, mocks, fakes, fixtures and Postman collections. |
| `l10n/` | Translation catalogues. `<locale>.js` is generated from `<locale>.json` by `npm run l10n:build`. |
| `configuration/`, `configurations/` | Importable configuration sets (sources, mappings, synchronisations) as JSON. Data, not logic. |
| `examples/` | A sample of how another app calls IntegrIQ, linked from the docs. |
| `scripts/` | Developer tooling: translation checks and quality scripts. |
| `openspec/` | Specifications and change proposals (Markdown). |
| `docs/` | Documentation site. |
| `docker/`, `docker-compose.yml`, `integriq-compose.yaml` | Local development and demo environments. |

## Functional components

We group the code by what it does, not by technical layer. The class name or its directory decides the component.

| Component | What it covers | PHP lines |
|---|---|---|
| Government protocols | Adapters for StUF, ZGW, DSO, OSO, ROD, UWLR, iWMO and iJW, FSC, LTI, KISS and similar exchange standards | 34k |
| Gateway and connections | Endpoints, gateways, consumers, authentication, brokers, webhooks, connection monitoring, logging | 17k |
| Flows and mappings | Flows, mappings, rules, actions, jobs, expressions, approvals | 15k |
| Messaging and delivery | Mail, digital post, SMS, Peppol, payments, document generation, outbound delivery | 15k |
| Synchronisation | Sources, synchronisations, contracts, ownership, provenance, directory sync | 15k |
| Application wiring | Bootstrap, settings, repair steps, commands, shared exceptions and helpers | 7k |
| Web interface | The Vue front end in `src/` | |

Counts exclude `lib/Migration/`, which SIG leaves out by default.

### Boundaries

The protocol adapters build on the gateway, flows and synchronisation. Every component also uses **Application wiring** for shared exceptions and helpers. Those dependencies are intended.

Decoupling between components is in progress. The aim is that the core components reach protocol adapters only through registries and interfaces, so a new protocol needs no change in the core.

## How a request flows

1. An outside system calls an endpoint, or a synchronisation or job fires on schedule.
2. The gateway authenticates the call and finds the matching endpoint, source or flow.
3. Mappings and rules turn the payload into the target shape.
4. The result is stored in OpenRegister, or sent on to the next system by an outbound adapter.
5. Events (`lib/Event/`) announce each step. Listeners in `lib/EventListener/` pick up work in other components.

## Test code

All PHP test code lives in `tests/`. Front-end unit tests live in `tests/vitest/`. End-to-end tests are Playwright specs in `tests/e2e/`. Nothing under `lib/` or `src/` is test code.

## What a default scan picks up

SIG's Sigrid excludes `vendor/`, `node_modules/`, `docs/`, `examples/`, migrations, mocks and stubs by default. Two things it does not exclude by default:

- `l10n/*.js`: generated translation catalogues that would count as JavaScript.
- `scripts/`: developer tooling that would count as production code.

`sigrid.yaml` excludes both and pins the model version. Components are left to Sigrid's automatic detection.

## Licence

IntegrIQ is licensed under EUPL-1.2.
