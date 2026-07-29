## MODIFIED Requirements

### Requirement: Log pages render via the shared declarative logs-page component (REQ-SHELLUI-003)

Every log route MUST be declared in the manifest as a `"type": "logs"` page resolved by the shared `CnLogsPage` component.

Specifically, `SourceLogs`, `EndpointLogs`, `JobLogs`, `SynchronizationLogs`,
and `CloudEventLogs` MUST each be declared in the manifest
(`src/manifest.json` / `src/manifest.d/*.json`) with a `{ register, schema }`
config, resolved by `@conduction/nextcloud-vue`'s shared `CnLogsPage`
component. OpenConnector MUST NOT ship its own bespoke log-index Vue component
or per-`logType` pinia store wiring for this purpose — that responsibility
belongs to the shared nc-vue component per ADR-036.

#### Scenario: All five log routes resolve through the manifest, not a bespoke wrapper

- **GIVEN** the manifest declares `SourceLogs`, `EndpointLogs`, `JobLogs`,
  `SynchronizationLogs`, and `CloudEventLogs` as `"type": "logs"` pages
- **WHEN** a user navigates to any of these five routes
- **THEN** the page renders via `CnLogsPage` reading directly from the
  declared OR `{register, schema}` — no openconnector-owned wrapper component
  is in the render path

#### Scenario: No dead per-logType wrapper code ships in the repo

- **GIVEN** the fleet's dead/stub-code review (`hydra-gate-stub-scan`)
- **WHEN** it scans `src/views/` for components not referenced by any manifest
  entry
- **THEN** it finds no orphaned log-index wrapper component
