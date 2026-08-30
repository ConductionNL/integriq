Closes #8

## Summary

Implements the openconnector OR-abstraction adoption spec (openspec/changes/openconnector-adopt-or-abstractions).
Renamed the connector-specific `ObjectService` to `SourceMappingService` to eliminate the cognitive collision with OR's generic `ObjectService`, moved two hardcoded PHP constants to admin-config, removed triplicated retention constants (fixing a 3-day vs 30-day drift bug in SynchronizationService), and added lifecycle/archival annotations to the register schemas. Phases gated on OR prerequisites (RegisterResolverService, lifecycle/notification runtime, nc-vue multi-tenancy-context) are documented in tasks.md with their gating conditions.

## Spec Reference

- Issue: #8
- Spec: `openspec/changes/openconnector-adopt-or-abstractions/design.md`

## Changes

- `lib/Service/SourceMappingService.php` — new canonical name for the MongoDB/OR facade (previously ObjectService); resolves naming collision with OR's generic ObjectService
- `lib/Service/ObjectService.php` — now a deprecated alias extending SourceMappingService; fires E_USER_DEPRECATED on instantiation per ADR-022 deprecation policy
- `lib/Controller/EndpointsController.php`, `LogsController.php`, `MappingsController.php` — updated `use` statements and type hints to reference SourceMappingService
- `lib/Service/EndpointService.php`, `SynchronizationService.php`, `RuleService.php`, `SoftwareCatalogueService.php` — updated type hints
- `lib/EventListener/ViewDeletedEventListener.php`, `lib/Twig/MappingRuntime.php`, `lib/Twig/MappingRuntimeLoader.php` — updated `use` statements
- `lib/Service/EndpointCacheService.php` — `CACHE_TTL` constant removed; TTL now read from admin-config `openconnector.endpoint_cache.ttl_seconds` (default 3600)
- `lib/Service/SoftwareCatalogueService.php` — `SUFFIX` constant removed; suffix now read from admin-config `openconnector.software_catalogue.suffix` (default `-sc`)
- `lib/Service/JobService.php`, `CallService.php`, `SynchronizationService.php` — removed `DEFAULT_SUCCESS_LOG_RETENTION` / `DEFAULT_ERROR_LOG_RETENTION` constants; SynchronizationService error retention unified from 259200000ms (3 days, bug) to 2592000000ms (30 days) matching the archival annotation
- `lib/Settings/openconnector_register.json` — added `x-openregister-lifecycle` to `event` schema (pending/processed/failed) and `synchronization_contract` schema (active/inactive/error); added `level` enum field to `synchronization_log`; enabled and scoped `sync-failed` notification
- `openspec/manifest.yaml` — new; declares tier: 2, dependencies: [openregister], or_min_version: ^v0.2.10, consumes list
- `openspec/changes/openconnector-adopt-or-abstractions/tasks.md` — phases 2-8 and 10 marked complete; phases 1 and 9 remain gated
- `lib/Controller/EndpointsController.php` — removed redundant `@NoAdminRequired` docblock from `#[PublicPage]` CORS method (hydra gate-7 fix)

## Test Coverage

- `tests/Unit/Service/SourceMappingServiceTest.php` — 5 tests: constructor wiring, BASE_OBJECT constant preservation, ObjectService is subclass of SourceMappingService, getOpenRegisters returns null when not installed, deprecated alias fires E_USER_DEPRECATED pointing to SourceMappingService

## Gated Work (not in this PR)

- Phase 1 (RegisterResolverService consumption): gated on OR shipping RegisterResolverService; PROP_* constants retained
- Phase 9 (multi-tenancy + i18n): gated on nc-vue `multi-tenancy-context` + OR i18n specs shipping
