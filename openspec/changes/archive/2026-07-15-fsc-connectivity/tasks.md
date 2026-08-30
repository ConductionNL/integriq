# Tasks — fsc-connectivity

## 1. Data model

### Task 1: Declare the `fsc_service` and `fsc_call` schemas
- **spec_ref**: `openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#requirement-persistence-and-observability-fsc_service-cache-and-fsc_call-log-req-004`
- **files**: `lib/Settings/openconnector_register.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the register loads THEN an `fsc_service` schema exists with `organisation`, `service`, `endpoint`, `grantRequired`, `resolvedVia`, `resolvedAt`
  - GIVEN a fresh install WHEN the register loads THEN an `fsc_call` schema exists with `organisation`, `service`, `method`, `status`, `ref`, `error`, `syncedAt`
  - GIVEN the register's schemas list WHEN compared to `components.schemas` THEN both new schema slugs are listed
  - GIVEN `source.type`'s documented recognised values WHEN read THEN `fsc` is listed alongside `kiss`/`iwmo-ijw`/`sms`/`peppol`/`psd2`/`payment`
- [x] Implement
- [x] Test

## 2. Provider abstraction + directory resolution

### Task 2: Add the FSC provider interface with log and REST bindings
- **spec_ref**: `openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#requirement-fsc-provider-abstraction-with-log-and-rest-bindings-req-001`
- **files**: `lib/Service/Fsc/FscConnectivityProviderInterface.php`, `lib/Service/Fsc/LogFscConnectivityProvider.php`, `lib/Service/Fsc/FscDirectoryClient.php`, `lib/Exception/FscConnectivityException.php`, `lib/Exception/FscDirectoryException.php`
- **acceptance_criteria**:
  - GIVEN `configuration.provider: log` (or absent) WHEN `resolveService()`/`call()` are called THEN no HTTP call and no credential read occur
  - GIVEN `configuration.provider: rest` WHEN `resolveService()` is called THEN a GET request against the configured `directoryUrl` is made
  - GIVEN `configuration.provider: rest` WHEN `call()` is called THEN `Authorization: Bearer <decrypted-token>` is sent
  - GIVEN a non-2xx/404 or transport failure WHEN either method is called THEN `FscDirectoryException`/`FscConnectivityException` is raised with a secret-free message
- [x] Implement
- [x] Test

### Task 3: FscDirectoryClient resolves found / unknown-organisation / unknown-service
- **spec_ref**: `openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#requirement-directory-resolution-req-002`
- **files**: `lib/Service/Fsc/FscDirectoryClient.php`, `lib/Service/Fsc/LogFscConnectivityProvider.php`
- **acceptance_criteria**:
  - GIVEN a known organisation+service WHEN resolved THEN the routable endpoint + auth context are returned
  - GIVEN an unknown organisation WHEN resolved THEN `FscDirectoryException` naming "organisation" is raised
  - GIVEN a known organisation but unknown service WHEN resolved THEN `FscDirectoryException` naming "service" is raised
  - GIVEN the `log` provider WHEN resolving THEN the same found/unknown-organisation/unknown-service behaviour is demonstrable against `configuration.directory.knownServices`, with no network call
- [x] Implement
- [x] Test

## 3. Routing

### Task 4: Add FscCallService (resolve, cache, dispatch, persist)
- **spec_ref**: `openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#requirement-call-routing-through-the-provider-seam-req-003`
- **files**: `lib/Service/FscCallService.php`
- **acceptance_criteria**:
  - GIVEN a successful call WHEN `callService()` completes THEN an `fsc_call` record is persisted `status: sent` with the provider's `ref`, AND an `fsc_service` cache record exists for the resolved organisation+service
  - GIVEN a provider transport exception WHEN `callService()` runs THEN a `status: failed` `fsc_call` record is persisted with `error` set, and the exception propagates to the controller
  - GIVEN an unresolvable organisation/service WHEN `callService()` runs THEN no `fsc_call`/`fsc_service` record with a routable endpoint is created for it, and `FscDirectoryException` propagates
  - GIVEN two independent `callService()` invocations, one failing WHEN both run THEN the failure of one does not affect the other's own resolution/dispatch (per-call isolation)
  - GIVEN no active `type=fsc` source WHEN `callService()`/`listResolvableServices()` run THEN a clean `not_configured` failure / empty result is produced, no HTTP attempted
- [x] Implement
- [x] Test

## 4. REST surface

### Task 5: Add FscController (list + call) + routes
- **spec_ref**: `openspec/changes/fsc-connectivity/specs/fsc-connectivity/spec.md#requirement-rest-surface-for-sibling-apps-req-005`
- **files**: `lib/Controller/FscController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an authenticated session and a configured source WHEN `GET /api/fsc/services` is called THEN the current `fsc_service` cache is returned
  - GIVEN no active source WHEN `GET /api/fsc/services` is called THEN an empty list is returned (never a 500)
  - GIVEN an authenticated session and a configured source WHEN `POST /api/fsc/call` is called with `{organisation, service}` THEN `{ref, statusCode, body}` is returned
  - GIVEN a missing `organisation`/`service` WHEN posted THEN HTTP 400 `missing_fields` is returned
  - GIVEN no active source WHEN posted THEN HTTP 503 `not_configured` is returned
  - GIVEN an unknown organisation/service WHEN posted THEN HTTP 404 `unknown_service` is returned
  - GIVEN a transport failure WHEN posted THEN HTTP 502 `fsc_call_failed` is returned
  - GIVEN both routes wired in `appinfo/routes.php` AND a test proves each controller method actually invokes `FscCallService` THEN the orphaned-capability rule is satisfied (not just declared)
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate --strict` passes (this change only)
- [x] Manual testing against acceptance criteria (sandbox `log` provider path) — exercised via the PHPUnit suite
- [x] Code review against spec requirements — self-reviewed; see Deviations below
- [x] `composer lint`, `composer cs:check`, `phpmd`, `phpstan` clean on the new files; full suite diffed against the pristine `origin/development` baseline

## Deviations

- **No client-certificate (mTLS) / Outway-Inway transport.** The real FSC
  transport is mutual TLS between organisation-provisioned Outway/Inway
  gateway processes, not a bearer token an application builds itself.
  `FscDirectoryClient` implements token auth only — documented explicitly in
  design.md "Outway/mTLS deviation", not a silent omission. The provider
  seam isolates adding a real Outway-backed binding later to
  `FscDirectoryClient` alone.
- **No live FSC Directory instance was available to verify the resolution
  API shape against** — every endpoint/field/response assumption is
  documented in design.md "Directory API shape", grounded in the published
  FSC/Common Ground concept model and this app's own
  `kiss-kcc-bridge`/`iwmo-ijw-adapter`/`peppol-access-point-connector`
  precedent.
- **Credential storage does not use `credentialRef`/`BrokeredCallService`**
  — same reasoning as `kiss-kcc-bridge`'s/`iwmo-ijw-adapter`'s identical,
  already-accepted deviation (self-containment, no optional-class
  dependency). Full analysis in design.md.
- **`source.type = 'fsc'`** was added as a new recognised (free-form, per the
  schema's own documented extensibility) value.
- **`fsc_service` cache invalidation/expiry is not implemented** — documented
  explicitly in design.md's Open Questions.
- **Grant provisioning/verification is not implemented** — only a read-only
  `grantRequired` flag is surfaced.
