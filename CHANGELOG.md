# Changelog

## [Unreleased]
### Added
- LTI 1.3 / LTI Advantage adapter: OIDC third-party-initiated login + signed-JWT
  launch validation (Tool role), Platform-role launch initiation, Deep Linking 2.0
  (both directions), Assignment & Grade Services (RFC 7523 service-token issuance,
  inbound score → `nl.conduction.lti.ags.score.received` CloudEvent, outbound score
  publish/result read), Names & Role Provisioning Services (inbound roster read via
  the ADR-008 `register/schema` dispatch, outbound roster pull), and per-registration
  signing-key lifecycle (generate/rotate/retire with a 7-day grace window) + JWKS
  publish. Three new OpenRegister schemas (`lti_platform`, `lti_tool`,
  `lti_deployment`). New `lib/Service/Lti/*` namespace, `LtiController`, dedicated
  `/api/lti/*` + `/.well-known/lti/*` routes, `LtiKeyRetirementJob` background job.
  Tenant-wide key-management UI (Beheer > Authenticatie) and the Adapters catalogue
  card are NOT yet built — backend contract only in this pass.
  (lti-13-platform)

- Pre-flight `storage_migrated` assertion in `Application::register()`: the app now
  fails fast with a `\LogicException` (naming the `occ openconnector:migrate-storage`
  runbook command) when the legacy→OpenRegister storage migration has not run.
  Bypassable in CI/test via `OPENCONNECTOR_SKIP_STORAGE_MIGRATED_ASSERT=1`.
  (openconnector-services-direct-or-usage, Task 1)
- VNG Klantinteracties adapter: five dialect-agnostic gateway mechanics — a
  composite transactional fan-out Rule type (`lib/Rule/CompositeFanoutRule.php`),
  `referentienummer` generation (`lib/Rule/ReferentienummerRule.php`), an AVG BSN
  policy Rule that hashes inbound BSNs (11-proef + SHA-256) and guards against
  raw BSNs outbound (`lib/Rule/AvgBsnPolicyRule.php`), VNG list-filter
  (`field__icontains`, `field__gte`, ...) and `partijIdentificator` filter
  translation plus bounded-depth `expand=` relation embedding
  (`MappingService::translateVngFilterOperators`/`expandRelations`), and an
  absolute self-URL/HAL `_links` output helper plus opt-in PUT-all-mandatory
  vs PATCH-partial enforcement (`EndpointService::renderSelfUrlAndHal`/
  `checkPutMandatoryFields`) — plus the packaged VNG Klantinteracties
  configuration set (`configuration/vng-klantinteracties.oas.json`) mapping
  `klantcontacten`/`partijen`/`betrokkenen`/`digitaleadressen` and the
  composite `maak-klantcontact` onto pipelinq's canonical schema.org CRM
  schemas. (vng-klantinteracties-adapter)

## 0.1.7 – 2024-09-19
### Added
- New features for this release

### Changed
- Changes in existing functionality for this release

### Fixed
- Bug fixes for this release

## 0.1.6 – 2024-09-07
### Added
- New features for this release

### Changed
- Changes in existing functionality for this release

### Fixed
- Bug fixes for this release

### Added
- Initial release
