# Design — Retrofit repair-and-app-boot

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

Two app-lifecycle hooks that run before any user-facing controller is invoked:

1. `lib/Repair/InitializeRegister.php::run()` — an `IRepairStep` wired in
   `appinfo/info.xml` under both `<install>` and `<post-migration>`. Imports the
   openconnector register descriptor (`lib/Settings/openconnector_register.json`,
   15 schemas) into OpenRegister via `ConfigurationService::importFromApp()`.
2. `lib/AppInfo/Application.php::registerIntegrationProviders()` — runs from
   `boot()` on every request. Registers openconnector's
   `SynchronizationContractProvider` with OR's
   `IntegrationRegistry` (ADR-019 pluggable integration registry) so the SyncContract
   leaf surfaces in OR object sidebars.

Both hooks are intentionally soft-fail: they catch every `\Throwable`, write a
warning to `IOutput` (repair step) or the PSR-3 logger (boot), and return cleanly.
This is the canonical fleet pattern documented in the memory note
[`reference_or-register-import-via-repair-step.md`] — repair steps run after every
enabled app's autoloader is wired, which `Migration::postSchemaChange` does not
guarantee on a cold `occ app:enable`.

## Observed-but-suspicious behaviour (flagged, not fixed)

| Site | Issue | Severity |
|---|---|---|
| `InitializeRegister::run` catch blocks | every `\Throwable` becomes a `$output->warning()` + `LoggerInterface::error()`; the repair step never fails the upgrade. Install can complete with the register missing and the user only sees a warning line in `occ` output. | medium — install appears green when it actually didn't |
| `InitializeRegister::run::class_exists` | `class_exists('\\OCA\\OpenRegister\\Service\\ConfigurationService') === false` returns silently with `$output->warning`. No alternative bootstrap path — if OR is disabled at install time and enabled later, openconnector's register never imports until the next `occ upgrade` or manual repair. | medium — fleet pattern but worth pinning in spec |
| `registerIntegrationProviders` double-catch | the inner `try { logger->warning } catch (\Throwable)` swallows logger-resolution failures so the boot path is exception-safe even when no logger is wired. Defensible, but masks misconfigured DI containers. | low |
| `InitializeRegister::run` no `storage_migrated` guard | the class docblock (line 47) advertises a `storage_migrated` IAppConfig guard for "the legacy → OR row migration", but `run()` itself never reads or sets that key. Legacy-row migration is implemented elsewhere (or not at all); the docblock is misleading. | low — docstring drift |

These are documented in REQ Notes rather than silently fixed via spec text.

## REQ → method map

| REQ | Methods |
|---|---|
| REQ-001 | `InitializeRegister::run` |
| REQ-002 | `Application::registerIntegrationProviders` |

## What the spec deliberately does NOT cover

- `InitializeRegister::__construct` / `getName` — DI plumbing and a static label
  string.
- `Application::__construct` / `register()` — event-listener wiring is a separate
  concern (cluster `events-cloudevents` covers the listener contracts).
- The descriptor file contents (`openconnector_register.json`) — the schema /
  register list is configuration data, not behaviour.

## Validation

After archive, `openspec validate repair-and-app-boot --strict` MUST pass.
