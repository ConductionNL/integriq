# zgw-version-translation Specification

**Status**: planned
**Scope**: openconnector
**OpenSpec changes**:
- zgw-version-translation

## Purpose

OpenConnector gains a ZGW version-translation shim so municipalities on a
different ZGW API version than the one procest/OpenRegister emit today
(canonical version `1.0`) can still integrate. Per ADR-022 integrations
live in openconnector, not per leaf app. Two VNG tracks land in 2026: an
incremental `1.6` (stability line) and an early-stage next-generation
standard with no stable OAS yet. This change ships pure translators for the
fleet's 7 core ZGW resources between `1.0` and `1.6`, a version-negotiation
layer defaulting to passthrough, a `POST /api/zgw-translate` REST surface,
and a light translation log.

## ADDED Requirements

### Requirement: Per-resource translator seam with a literal-leak guard (REQ-001)

OpenConnector MUST define a `ZgwResourceTranslatorInterface`
(`lib/Service/ZgwVersion/ZgwResourceTranslatorInterface.php`) with
`getResource(): string`, `translateToV16(array $payload): array`, and
`translateToV1x(array $payload): array`. One implementation MUST exist per
fleet ZGW resource — `zaak`, `zaaktype`, `enkelvoudiginformatieobject`,
`besluit`, `rol`, `status`, `resultaat` — each independently testable per
direction. Every translator MUST guard against literal-leak: a required
field missing from its output, an enum value outside the resource's
documented value set, or a field structurally required to be an array
carrying a bare scalar instead MUST raise `ZgwLiteralLeakException` rather
than forward the unresolved/malformed value.

#### Scenario: the interface is the single seam for adding a resource translator
- GIVEN a future 8th ZGW resource needing version translation
- WHEN it implements `ZgwResourceTranslatorInterface`
- THEN it SHALL be selectable by `ZgwVersionTranslationService` by its `getResource()` slug with no change to the negotiation or orchestration services
- @e2e exclude backend translator seam — covered by PHPUnit

#### Scenario: resultaat translates the verified resultaattoelichting delta both directions
- GIVEN a `resultaat` payload in `1.0` shape carrying only `toelichting`
- WHEN `ResultaatTranslator::translateToV16()` is called
- THEN the `1.6` output SHALL NOT carry a `resultaattoelichting` key
- WHEN `ResultaatTranslator::translateToV1x()` is called on a `1.6` payload
- THEN the legacy output SHALL carry `resultaattoelichting` mirroring `toelichting`
- @e2e exclude backend translator matrix — covered by PHPUnit

#### Scenario: rol's betrokkeneType gap is a documented, lossy best-effort translation
- GIVEN a `rol` payload in `1.0` shape with `betrokkeneIdentificatie` set and no `betrokkeneType`
- WHEN `RolTranslator::translateToV16()` is called
- THEN the output SHALL carry `betrokkeneType: "natuurlijk_persoon"` as a documented default
- WHEN `RolTranslator::translateToV1x()` is called on that same `1.6` payload
- THEN the output SHALL NOT carry `betrokkeneType`, matching the fleet's own scalar shape
- @e2e exclude backend translator matrix — covered by PHPUnit

#### Scenario: a structurally malformed zaaktype array field is rejected, never forwarded
- GIVEN a `zaaktype` payload where `besluittypen` is the bare scalar string `"decisionTypes"` instead of an array
- WHEN `ZaakTypeTranslator::translateToV16()` or `::translateToV1x()` is called
- THEN `ZgwLiteralLeakException` SHALL be raised and no payload SHALL be returned
- @e2e exclude backend literal-leak guard — covered by PHPUnit

#### Scenario: an out-of-set enum value is rejected, never forwarded
- GIVEN a `zaak` payload where `vertrouwelijkheidaanduiding` is `"top-secret"` (not one of the 8 documented values)
- WHEN `ZaakTranslator::translateToV16()` is called
- THEN `ZgwLiteralLeakException` SHALL be raised
- @e2e exclude backend literal-leak guard — covered by PHPUnit

#### Scenario: a payload missing a required field is rejected, never forwarded
- GIVEN a `besluit` payload missing `besluittype`
- WHEN `BesluitTranslator::translateToV16()` is called
- THEN `ZgwLiteralLeakException` SHALL be raised naming the missing field
- @e2e exclude backend literal-leak guard — covered by PHPUnit

#### Scenario: round-trip translation is lossless for structurally-identical resources
- GIVEN a conformant `status` payload in `1.0` shape
- WHEN it is translated `translateToV16()` then the result is translated `translateToV1x()`
- THEN the final payload SHALL equal the original payload
- @e2e exclude backend translator matrix — covered by PHPUnit

### Requirement: Version negotiation with passthrough default (REQ-002)

`ZgwVersionNegotiationService` MUST resolve a caller's `fromVersion`/
`toVersion` in this precedence: an explicit request-body field, else an
`X-ZGW-Version` request header, else the `Accept` header's `version=`
media-type parameter, else default `"1.0"`. `"1.0"` and `"1.6"` MUST be
recognised as implemented; `"2.0"` (the next-generation placeholder) MUST
be recognised as known but unimplemented; any other value MUST be rejected
as unknown. `fromVersion === toVersion` MUST short-circuit to returning the
input payload unchanged, without invoking any translator or its guards.

#### Scenario: no version signal at all is a full passthrough
- GIVEN a translate request with no `fromVersion`/`toVersion` body fields, no `X-ZGW-Version` header, and no `Accept` version parameter
- WHEN `ZgwVersionTranslationService::translate()` is called
- THEN the payload SHALL be returned byte-for-byte unchanged
- @e2e exclude backend negotiation — covered by PHPUnit

#### Scenario: an explicit body field takes precedence over headers
- GIVEN a request with body `fromVersion: "1.0", toVersion: "1.6"` and header `X-ZGW-Version: 1.0`
- WHEN version negotiation resolves
- THEN the resolved `toVersion` SHALL be `"1.6"` (the body field wins)
- @e2e exclude backend negotiation — covered by PHPUnit

#### Scenario: an unknown version is rejected before any translator runs
- GIVEN a request with `toVersion: "0.9"`
- WHEN `ZgwVersionTranslationService::translate()` is called
- THEN `ZgwUnknownVersionException` SHALL be raised, and no translator SHALL be invoked
- @e2e exclude backend negotiation — covered by PHPUnit

#### Scenario: the next-generation placeholder version is recognised but not implemented
- GIVEN a request with `toVersion: "2.0"`
- WHEN `ZgwVersionTranslationService::translate()` is called
- THEN `ZgwVersionNotImplementedException` SHALL be raised, distinguishable from an unknown-version error
- @e2e exclude backend negotiation — covered by PHPUnit

#### Scenario: an unresolvable expand hint is stripped, not silently honoured
- GIVEN a query parameter set containing `expand=hoofdzaak`
- WHEN `ZgwVersionNegotiationService::stripUnsupportedExpandHint()` is called
- THEN the returned parameter set SHALL NOT contain `expand`
- @e2e exclude backend negotiation — covered by PHPUnit

### Requirement: REST surface for sibling apps and external consumers (REQ-003)

`POST /api/zgw-translate` MUST accept `{resource, fromVersion?, toVersion?,
payload}` from an authenticated NC session, gated by `ActionAuthService`
action `zgw-version.translate`, and return `{resource, fromVersion,
toVersion, payload}` on success. It MUST return HTTP 400 `missing_fields`
when `resource` or `payload` is absent, HTTP 400 `unknown_resource` for a
resource outside the 7 supported, HTTP 400 `unknown_version` for an
unrecognised version, HTTP 501 `not_implemented` for the next-generation
placeholder version, HTTP 422 `literal_leak` when a translator's guard
rejects the payload, and HTTP 401 when unauthenticated — never an
unhandled 500. The route MUST be wired in `appinfo/routes.php` with a test
proving the controller method actually invokes
`ZgwVersionTranslationService` (orphaned-capability rule: routes wired AND
test-proven invocation, not just declared).

#### Scenario: a valid translate request returns the translated payload
- GIVEN an authenticated session
- WHEN `POST /api/zgw-translate` is called with `{resource: "status", fromVersion: "1.0", toVersion: "1.6", payload: {...conformant status...}}`
- THEN HTTP 200 SHALL be returned with the translated payload under `payload`
- @e2e exclude backend REST surface — covered by PHPUnit

#### Scenario: a missing resource field returns 400
- GIVEN an authenticated session
- WHEN `POST /api/zgw-translate` is called with no `resource` field
- THEN HTTP 400 `missing_fields` SHALL be returned
- @e2e exclude backend REST surface — covered by PHPUnit

#### Scenario: an unknown resource returns 400
- GIVEN an authenticated session
- WHEN `POST /api/zgw-translate` is called with `resource: "besluittype"` (not one of the 7 supported)
- THEN HTTP 400 `unknown_resource` SHALL be returned
- @e2e exclude backend REST surface — covered by PHPUnit

#### Scenario: an unauthenticated request is rejected
- GIVEN no authenticated session
- WHEN `POST /api/zgw-translate` is called
- THEN HTTP 401 SHALL be returned and `ZgwVersionTranslationService` SHALL NOT be invoked
- @e2e exclude backend REST surface — covered by PHPUnit

#### Scenario: a literal-leak rejection surfaces as a clean typed error
- GIVEN an authenticated session
- WHEN `POST /api/zgw-translate` is called with a `zaak` payload carrying an out-of-set `vertrouwelijkheidaanduiding`
- THEN HTTP 422 `literal_leak` SHALL be returned, never a 500
- @e2e exclude backend REST surface — covered by PHPUnit

### Requirement: Persistence and observability — zgw_version_translation_log (REQ-004)

Every `ZgwVersionTranslationService::translate()` attempt MUST persist one
`zgw_version_translation_log` OR record (`resource`, `fromVersion`,
`toVersion`, `status` [`success`|`passthrough`|`failed`], `error`,
`translatedAt`) — success, passthrough, or failure alike.

#### Scenario: a successful translation logs status success
- GIVEN a conformant `status` payload translated `1.0` → `1.6`
- WHEN the translation completes
- THEN a `zgw_version_translation_log` record SHALL exist with `status: "success"`
- @e2e exclude backend persistence — covered by PHPUnit

#### Scenario: a passthrough call logs status passthrough
- GIVEN a request with `fromVersion === toVersion`
- WHEN the translation completes
- THEN a `zgw_version_translation_log` record SHALL exist with `status: "passthrough"`
- @e2e exclude backend persistence — covered by PHPUnit

#### Scenario: a rejected translation logs status failed with the error
- GIVEN a payload that fails a translator's literal-leak guard
- WHEN the translation attempt completes
- THEN a `zgw_version_translation_log` record SHALL exist with `status: "failed"` and `error` set, and the exception SHALL still propagate to the caller
- @e2e exclude backend persistence — covered by PHPUnit
