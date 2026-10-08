# Woo build queue for this repository

11 OpenSpec changes in this repository close gaps in the Woo capability programme. Each has a change folder under `openspec/changes/` and an issue titled `[OpenSpec] <change-name>` that the OpenSpec workflow keeps in step with the spec.

## How to pick up a change

1. Take the first change below whose dependencies are all merged on `development`. A dependency in another repository is linked to its issue there; check that issue's linked PR is merged.
2. Inside a wave, the order below is the order to build. Statutory rows come first.
3. Read `openspec/woo-build-rules.md` before the first command, then the change's `proposal.md`, its specs and its `tasks.md`.
4. The decisions the specs cite (D1 to D13) are in `openspec/woo-decisions.md`. A spec never contradicts one. If a task seems to, stop and say so in the issue.
5. Work on the branch the issue names, open one PR with `--base development`, and close the issue through the PR.

Two things need a person, not an agent: settling the Woo refusal grounds against the law (dossiq `woo-refusal-grounds-list`, task 1, blocks seeding), and the screen-reader pass for row 15.5.

## Wave 1

| change | rows | depends on |
|---|---|---|
| [integriq/observability-opentelemetry-export](https://github.com/ConductionNL/integriq/issues/2537) | 13.29 | nothing |
| [integriq/observability-opentelemetry-export-errors-and-app-spans](https://github.com/ConductionNL/integriq/issues/2548) | 13.29 | [integriq/observability-opentelemetry-export](https://github.com/ConductionNL/integriq/issues/2537) |
| [integriq/outbound-call-log-investigation-window](https://github.com/ConductionNL/integriq/issues/2538) | 13.23 | nothing |
| [integriq/outbound-call-log-investigation-window-page-and-allowlist](https://github.com/ConductionNL/integriq/issues/2549) | 13.23 | [integriq/outbound-call-log-investigation-window](https://github.com/ConductionNL/integriq/issues/2538) |
| [integriq/sources-sftp-adapter](https://github.com/ConductionNL/integriq/issues/2539) | 1.7 | nothing |
| [integriq/sources-sftp-adapter-intake-hand-over](https://github.com/ConductionNL/integriq/issues/2551) | 1.7 | [integriq/sources-sftp-adapter-watched-folder](https://github.com/ConductionNL/integriq/issues/2550), [integriq/sources-sftp-adapter](https://github.com/ConductionNL/integriq/issues/2539) |
| [integriq/sources-sftp-adapter-watched-folder](https://github.com/ConductionNL/integriq/issues/2550) | 1.7 | [integriq/sources-sftp-adapter](https://github.com/ConductionNL/integriq/issues/2539) |

## Wave 2

| change | rows | depends on |
|---|---|---|
| [integriq/connections-declare-data-residency](https://github.com/ConductionNL/integriq/issues/2540) | 17.11 | nothing |
| [integriq/connectors-graph-document-search](https://github.com/ConductionNL/integriq/issues/2541) | 1.16 | nothing |
| [integriq/connectors-graph-document-search-chat-threads](https://github.com/ConductionNL/integriq/issues/2552) | 1.16 | [integriq/connectors-graph-document-search](https://github.com/ConductionNL/integriq/issues/2541), `integriq/sources-per-user-oauth` |
| [integriq/zgw-catalogi-served](https://github.com/ConductionNL/integriq/issues/2542) | 17.18 | nothing |
