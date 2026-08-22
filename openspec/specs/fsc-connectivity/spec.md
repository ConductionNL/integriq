# fsc-connectivity Specification

## Purpose
TBD - created by archiving change fsc-connectivity. Update Purpose after archive.
## Requirements
### Requirement: FSC provider abstraction with log and REST bindings (REQ-001)

Integriq MUST define an `FscConnectivityProviderInterface`
(`lib/Service/Fsc/FscConnectivityProviderInterface.php`) with
`getProviderId()`, `getConfigSchema()`, `resolveService(directoryConfiguration,
organisation, service)`, and `call(directoryConfiguration, resolution,
method, payload)`. A source's `configuration.provider` (`log`|`rest`)
selects the binding at runtime — mirroring
`IwmoIjwProviderInterface`/`SmsProviderInterface`. `log` MUST remain usable
with no configuration and MUST be the default when `configuration.provider`
is absent. `rest` (`FscDirectoryClient`) MUST authenticate every downstream
`call()` request with `Authorization: Bearer <token>`, decrypting
`configuration.authentication.encryptedToken` via `OCP\Security\ICrypto`
in-process for the instant needed to build the header — never logged, never
persisted decrypted.

#### Scenario: the interface is the single seam for adding a compatible alternative transport
- GIVEN a future alternative FSC-compatible transport (e.g. one backed by a real Outway sidecar with client-certificate auth)
- WHEN it implements `FscConnectivityProviderInterface`
- THEN it SHALL be selectable via `configuration.provider` with no change to `FscCallService` or `FscController`
- @e2e exclude backend provider seam — covered by PHPUnit

#### Scenario: the log provider performs no network call
- GIVEN a source with `configuration.provider: log` (or absent)
- WHEN `resolveService()` and `call()` are invoked
- THEN neither SHALL make an outbound HTTP call, and no credential SHALL be read
- @e2e exclude backend provider binding — covered by PHPUnit

#### Scenario: the REST provider sends the expected bearer auth header on call
- GIVEN a source with `configuration.provider: rest` and a valid `encryptedToken`
- WHEN `call()` is dispatched against an already-resolved endpoint
- THEN the request SHALL carry `Authorization: Bearer <decrypted-token>`
- @e2e exclude backend provider binding — covered by PHPUnit

### Requirement: Directory resolution (REQ-002)

`resolveService()` MUST resolve an organisation+service pair to a routable
endpoint and required auth context (`grantRequired`, at minimum) via the
configured directory. A resolution for an unpublished organisation MUST
raise `FscDirectoryException` naming "organisation" BEFORE any downstream
call is attempted. A resolution for a known organisation but an unpublished
service MUST raise `FscDirectoryException` naming "service". The `log`
provider MUST reproduce this same found/unknown-organisation/unknown-service
behaviour against a locally configured `directory.knownServices` list, with
no network call, so the whole resolution contract is testable without a live
FSC Directory.

#### Scenario: a known organisation and service resolve to a routable endpoint
- GIVEN a directory configuration with `organisation: "00000001823288444000"` publishing service `"brp-bevragen"`
- WHEN `resolveService()` is called with that organisation and service
- THEN a resolution SHALL be returned carrying a non-empty `endpoint`
- @e2e exclude backend directory resolution — covered by PHPUnit

#### Scenario: an unknown organisation is rejected before any call is attempted
- GIVEN a directory configuration with no entry for organisation `"unknown-org"`
- WHEN `resolveService()` is called with that organisation
- THEN `FscDirectoryException` SHALL be raised naming "organisation", and no downstream call SHALL be attempted
- @e2e exclude backend directory resolution — covered by PHPUnit

#### Scenario: a known organisation with an unknown service is rejected
- GIVEN a directory configuration where the organisation exists but does not publish service `"unknown-service"`
- WHEN `resolveService()` is called
- THEN `FscDirectoryException` SHALL be raised naming "service"
- @e2e exclude backend directory resolution — covered by PHPUnit

### Requirement: Call routing through the provider seam (REQ-003)

The system MUST provide `FscCallService::callService(organisation, service,
method, payload)` which resolves the target via the configured provider's
`resolveService()`, caches the resolution as an `fsc_service` record, then
dispatches the call via the same provider's `call()`. A transport failure
during `call()` MUST persist an `fsc_call` record with `status: failed` and
the failure detail, then rethrow. A resolution failure (unknown
organisation/service) MUST propagate `FscDirectoryException` without
persisting a routable `fsc_service` cache entry. No active `type=fsc` source
MUST produce a clean, distinguishable failure — never an unhandled crash.
Each `callService()` invocation MUST be isolated: one call's failure MUST
NOT impede or corrupt a subsequent, independent call.

#### Scenario: a successful call persists a sent record and caches the resolution
- GIVEN a complete call against the `log` provider with a known organisation+service
- WHEN `callService()` completes
- THEN an `fsc_call` record SHALL be persisted with `status: sent` and the provider-returned `ref`
- AND an `fsc_service` record SHALL exist for that organisation+service
- @e2e exclude backend persistence — covered by PHPUnit

#### Scenario: a failed transport call persists a failed record and rethrows
- GIVEN a `rest` provider whose `call()` raises `FscConnectivityException`
- WHEN `callService()` is invoked
- THEN an `fsc_call` record SHALL be persisted with `status: failed` and `error` set, and the exception SHALL propagate
- @e2e exclude backend persistence — covered by PHPUnit

#### Scenario: no active source produces a clean not-configured failure
- GIVEN no active `type=fsc` source is configured
- WHEN `callService()` or `listResolvableServices()` is invoked
- THEN a clean, distinguishable failure/empty result SHALL be produced, with no HTTP attempted
- @e2e exclude backend not-configured behaviour — covered by PHPUnit

#### Scenario: one call's failure does not affect an independent second call
- GIVEN two independent `callService()` invocations against different organisation/service pairs, the first of which fails transport
- WHEN both are invoked in sequence
- THEN the second call's own resolution and dispatch SHALL succeed unaffected by the first's failure
- @e2e exclude backend per-call isolation — covered by PHPUnit

### Requirement: REST surface for sibling apps (REQ-005)

`GET /api/fsc/services` MUST let an authenticated NC session list the
current `fsc_service` cache, returning an empty list (never an error) when
unconfigured. `POST /api/fsc/call` MUST let an authenticated NC session
invoke a service, returning `{ref, statusCode, body}` on success, HTTP 400
`missing_fields` when `organisation`/`service` is absent, HTTP 503
`not_configured` when no active `type=fsc` source exists, HTTP 404
`unknown_service` when the organisation/service does not resolve, and HTTP
502 `fsc_call_failed` on any other transport/config failure — never an
unhandled 500. Both routes MUST be gated by `ActionAuthService` action
authorization (`fsc.list`, `fsc.call`) and MUST be wired in
`appinfo/routes.php`, with a test proving each controller method actually
invokes `FscCallService` (orphaned-capability rule: routes wired AND
test-proven invocation, not just declared).

#### Scenario: listing services returns the current cache
- GIVEN an authenticated session and a configured `fsc` source with cached resolutions
- WHEN `GET /api/fsc/services` is called
- THEN the current `fsc_service` cache SHALL be returned
- @e2e exclude backend REST surface — covered by PHPUnit

#### Scenario: listing services when unconfigured returns an empty list, not an error
- GIVEN no active `type=fsc` source
- WHEN `GET /api/fsc/services` is called
- THEN an empty list SHALL be returned with no error status
- @e2e exclude backend REST surface — covered by PHPUnit

#### Scenario: a valid call request returns ref, statusCode, and body
- GIVEN an authenticated session and a configured `log` or `rest` FSC source
- WHEN `POST /api/fsc/call` is called with `{organisation, service}`
- THEN HTTP 200 SHALL be returned with `{ref, statusCode, body}`
- @e2e exclude backend REST surface — covered by PHPUnit

#### Scenario: a call request with no active source returns not_configured
- GIVEN no active `type=fsc` source
- WHEN `POST /api/fsc/call` is called
- THEN HTTP 503 `not_configured` SHALL be returned, no HTTP attempted
- @e2e exclude backend REST surface — covered by PHPUnit

#### Scenario: a call request for an unknown organisation or service returns 404
- GIVEN a configured source whose directory does not know the requested organisation/service
- WHEN `POST /api/fsc/call` is called
- THEN HTTP 404 `unknown_service` SHALL be returned
- @e2e exclude backend REST surface — covered by PHPUnit

### Requirement: Persistence and observability — `fsc_service` cache and `fsc_call` log (REQ-004)

Every successful `resolveService()` MUST upsert one `fsc_service` OR record
(`organisation`, `service`, `endpoint`, `grantRequired`, `resolvedVia`,
`resolvedAt`) keyed by `organisation`+`service` (never duplicated). Every
`callService()` attempt MUST persist one `fsc_call` OR record
(`organisation`, `service`, `method`, `status`, `ref`, `error`, `syncedAt`),
success or failure, never merged into a single mutable row across attempts.

#### Scenario: repeated resolution of the same organisation+service updates one cache row
- GIVEN two successful `resolveService()` calls for the same organisation+service
- WHEN both complete
- THEN exactly one `fsc_service` record SHALL exist for that pair, reflecting the most recent resolution
- @e2e exclude backend persistence — covered by PHPUnit

#### Scenario: every call attempt produces its own fsc_call record
- GIVEN two `callService()` invocations, one succeeding and one failing
- WHEN both complete
- THEN two distinct `fsc_call` records SHALL exist, each reflecting its own outcome
- @e2e exclude backend persistence — covered by PHPUnit

