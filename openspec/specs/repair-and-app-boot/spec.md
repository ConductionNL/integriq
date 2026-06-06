# repair-and-app-boot Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-24-repair-and-app-boot. Update Purpose after archive.

@e2e exclude backend install/upgrade repair steps + provider registration (OR import, runs at app boot, no browser UI) — covered by PHPUnit

## Requirements
### Requirement: Register descriptor import via OR ConfigurationService on install/upgrade (REQ-001)

`InitializeRegister::run(IOutput $output)` MUST attempt to import openconnector's
register descriptor (`lib/Settings/openconnector_register.json`) into OpenRegister
via `ConfigurationService::importFromApp(appId, data, version)` on every invocation.
The method is invoked by Nextcloud's repair-step framework on both first install
(`<install>` block in `appinfo/info.xml`) and on every subsequent `occ upgrade`
(`<post-migration>` block).

The method MUST resolve OR's `ConfigurationService` lazily from the server DI
container so that openconnector can be installed before openregister without crashing
the install. When OR's `ConfigurationService` class is not loadable (OR not enabled),
the method MUST emit an `$output->warning` and a `LoggerInterface::warning` then
return cleanly — install MUST continue.

The descriptor's `version` argument is the value of the
`openconnector / installed_version` app-config key (defaults to `"1.0.0"` if unset)
so that OR's `importFromApp` can short-circuit when the existing register is up to
date.

Every failure path (container resolution, missing descriptor file, descriptor JSON
parse failure, OR import failure) is observed to be a `$output->warning` +
`LoggerInterface::error` + early return — the repair step NEVER throws and NEVER
fails the upgrade.

#### Scenario: install with OR present imports the register

- **GIVEN** openregister is enabled before openconnector
- **AND** `lib/Settings/openconnector_register.json` exists on disk
- **WHEN** `InitializeRegister::run(...)` runs as part of `occ app:enable openconnector`
- **THEN** OR's `ConfigurationService::importFromApp` is called with
  `appId='openconnector'`, `data=<parsed descriptor>`, `version=<installed_version
  app-config>`
- **AND** an `$output->info('register descriptor imported …')` message is emitted

#### Scenario: install without OR succeeds with a warning

- **GIVEN** openregister is NOT enabled at the time openconnector installs
- **WHEN** `InitializeRegister::run(...)` runs
- **THEN** the method emits `$output->warning('OpenRegister is not installed or enabled…')`
- **AND** the method returns without throwing
- **AND** the openconnector install completes

#### Scenario: import failure does not fail the upgrade

- **GIVEN** OR is enabled but `importFromApp` throws (e.g. transient DB error)
- **WHEN** `InitializeRegister::run(...)` runs
- **THEN** the exception is caught, `$output->warning('register import failed: …')`
  is emitted, and `LoggerInterface::error` is logged
- **AND** the method returns without rethrowing
- **AND** the upgrade reports success

#### Notes

- The "install appears green when it actually didn't" property is OBSERVED, not a
  desired behaviour. Operators reading `occ` output need to look for `warning`
  lines; `occ` exit status alone is not sufficient. Tightening this (e.g.
  reraising on `importFromApp` failure for non-fresh-installs) is a separate
  hardening change.
- The class docblock advertises a `storage_migrated` IAppConfig guard for a legacy
  → OR row migration, but `run()` itself reads no such key. Treat the docstring
  as drift until a follow-up either implements the migration or removes the
  reference.

---

### Requirement: IntegrationProvider boot-time registration with OR IntegrationRegistry (REQ-002)

`Application::registerIntegrationProviders(IBootContext $context)` MUST be called
from `Application::boot()` on every request and MUST register openconnector's
`SynchronizationContractProvider` with OR's `IntegrationRegistry` so that
SyncContract leaves render in OR object sidebars per ADR-019.

The method MUST short-circuit cleanly with no log emission when
`IntegrationRegistry::class` is not loadable (OR not enabled / earlier in boot
order). When the class is loadable but resolution from the DI container throws,
the method MUST log a `LoggerInterface::warning` with the message
`openconnector: failed to register IntegrationProviders with OR — <reason>` and
return without rethrowing — boot MUST NOT crash.

Provider registration is observed to happen exactly once per app instance per
boot invocation (called from `boot()`, which Nextcloud invokes once per request
after `register()`).

#### Scenario: OR present registers the SynchronizationContractProvider

- **GIVEN** openregister is enabled and `OCA\OpenRegister\Service\IntegrationRegistry`
  is loadable
- **WHEN** `Application::registerIntegrationProviders($context)` runs
- **THEN** the server container resolves `IntegrationRegistry`
- **AND** `$registry->addProvider(<SynchronizationContractProvider instance>)` is
  invoked
- **AND** the method returns without throwing

#### Scenario: OR absent short-circuits silently

- **GIVEN** `OCA\OpenRegister\Service\IntegrationRegistry` is NOT loadable
- **WHEN** `Application::registerIntegrationProviders($context)` runs
- **THEN** the method returns early
- **AND** no log entry is emitted
- **AND** the openconnector boot completes

#### Scenario: provider resolution failure logs and continues

- **GIVEN** OR is loadable but `$container->get(SynchronizationContractProvider::class)`
  throws (e.g. constructor dependency missing)
- **WHEN** `Application::registerIntegrationProviders($context)` runs
- **THEN** the outer `\Throwable` catch logs
  `openconnector: failed to register IntegrationProviders with OR — <message>`
  via the server container's `LoggerInterface`
- **AND** if logger resolution itself fails, the inner catch swallows it
- **AND** the method returns without rethrowing
- **AND** the openconnector boot completes (SyncContract leaves simply will not
  appear in OR sidebars on this request)

#### Notes

- The inner `try { logger->warning } catch (\Throwable)` swallows logger
  resolution failures so the boot path is exception-safe even on a broken DI
  container. Defensible for early-boot resilience but masks misconfigured
  containers in dev.
- Provider list is currently one entry (`SynchronizationContractProvider`).
  Future additions land here; this REQ scopes the registration pattern, not the
  catalogue.

