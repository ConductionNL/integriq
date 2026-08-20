# Changelog

## [Unreleased]
### Added
- Read-only MCP tool surface (ADR-063): 8 schemas (endpoint, job, mapping,
  synchronization, synchronization_contract, call_log, job_log,
  synchronization_log) declare an `x-openregister-mcp` dialect exposing only
  `search` and `get` verbs, so AI agents can query integration state via MCP.
  Credential-bearing schemas (source, consumer) are deliberately excluded; no
  write/destructive verbs are exposed.
- LTI Tool-role governance layer: a `status` (`pending | approved | suspended`)
  trust gate on `lti_platform`/`lti_tool` — a registration cannot complete a
  login/launch/token-issuance flow until an admin-gated `approve()` action
  transitions it, and a `pending`/`suspended` registration is rejected with
  the exact same HTTP shape as an unregistered issuer (no status-enumeration
  side channel). New `lti_identity_link` schema + `LtiIdentityLinkService`
  resolving a validated launch's `sub` claim to a Nextcloud `userId` under a
  conservative `manualLinkOnly` default (no email/name matching, ever) or an
  explicit per-platform `autoProvisionAsRole` opt-in bounded to a named
  group. `lti_deployment.resourceLinkMappings[]` +
  `LtiLaunchService::resolveResourceMapping()` — a per-resource-link routing
  seam mirroring `gradeSink`/`rosterSource`'s shape. All three sit strictly
  after the existing cryptographic launch-validation chain; no existing
  REQ-LTI-001..010 protocol behaviour is modified.
  (lti-tool-provider-role)
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

### Changed
- i18n(schema): re-authored the 24 remaining Dutch-language schema property
  titles to English across `event_subscription` (the `notificaties` action
  block), `ris_sync_record`, `dso_verzoek`/`dso_message`,
  `notificaties_abonnement`, `kiss_klantcontact`, `iwmo_ijw_message` and
  `stuf_message` — titles are the canonical UI label source and must be
  English-authored; Dutch display is now carried by `l10n/nl.json`/`nl.js`.
  Property keys and all other schema fields are unchanged. Register
  `info.version` bumped 1.1.0 → 1.1.1.

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
