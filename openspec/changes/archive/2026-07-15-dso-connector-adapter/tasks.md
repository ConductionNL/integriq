# Tasks — dso-connector-adapter

## 1. Persistence: dso_verzoek / dso_message schemas

- [x] Add `dso_verzoek` schema to `lib/Settings/openconnector_register.json`
      (inbound Verzoek lifecycle: `verzoekId`, `bronorganisatie`, `type`,
      `indieningsdatum`, `rawVerzoek`, `requester`, `mappedTitle`,
      `mappedSummary`, `mappedChannel`, `mappedPriority`, `status`,
      `errorDetail`, `correlationId`, `targetCase`, `receivedAt`), declaring
      `x-openregister-handoff` (`verzoek-to-case` → `ns#Case`).
- [x] Add `dso_message` schema (outbound audit: `ref`, `type`, `status`,
      `payload`, `verzoekUuid`, `error`, `syncedAt`).
- [x] Register both slugs in `components.registers.openconnector.schemas`.
- [x] Update `tests/Unit/Settings/RegisterDescriptorTest.php` (32 → 34
      schemas).

## 2. Inbound translation (literal-leak guard)

- [x] `lib/Service/Dso/DsoVerzoekTranslator.php` — translate a parsed
      Verzoek into `mappedTitle`/`mappedSummary`/`mappedChannel`/
      `mappedPriority`/`requester`; throw `DsoTranslationException` on
      missing `verzoekId` or an unrecognised `type` — never fabricate a
      correlation reference.
- [x] `lib/Exception/DsoTranslationException.php`.
- [x] Unit tests: full Verzoek, partial Verzoek (safe fallbacks), no
      activiteiten at all, missing/empty `verzoekId` (literal-leak guard),
      unrecognised type, priority resolution per type
      (`tests/Unit/Service/Dso/DsoVerzoekTranslatorTest.php`).

## 3. Outbound provider seam

- [x] `lib/Service/Dso/DsoConnectorProviderInterface.php`.
- [x] `lib/Service/Dso/LogDsoConnectorProvider.php` — sandbox default,
      `MOCK-DSO-<n>`, no network call.
- [x] `lib/Service/Dso/DsoClient.php` — generic REST binding
      (`/statussen`/`/besluiten`, Bearer-token auth via
      `configuration.authentication.encryptedToken` decrypted through
      `OCP\Security\ICrypto`); documented mTLS gap (see design.md "Open
      Questions").
- [x] `lib/Exception/DsoProviderException.php`.
- [x] Unit tests: auth header (default + configured scheme), endpoint
      routing per message type, ref extraction (JSON + locally-derived
      fallback), non-2xx failure, missing baseUrl/token/unknown type
      (`tests/Unit/Service/Dso/DsoClientTest.php`,
      `tests/Unit/Service/Dso/LogDsoConnectorProviderTest.php`).

## 4. DsoIngestService — persistence, handoff, outbound orchestration

- [x] `lib/Service/DsoIngestService.php`:
  - `ingest()` — persist `received`, translate, `mapped`|`failed`
    (per-verzoek isolation).
  - `getVerzoek()` / `listVerzoeken()` — read surface.
  - `handoff()` — authenticated `verzoek-to-case` handoff execution via
    OpenRegister's `HandoffService::execute()`; rejects a not-yet-`mapped`
    verzoek; marks `failed` + rethrows on engine failure.
  - `postOutbound()` — resolve active `type=dso` source + provider
    (`log` default, `rest` = `DsoClient`), dispatch, persist `dso_message`
    per attempt (both success and failure).
  - `resolveActiveSource()` / `resolveProvider()`.
- [x] Unit tests: ingest reaches mapped, missing-verzoekId fails closed +
      per-verzoek isolation, list filters by status, handoff success/
      not-yet-mapped/failure-isolation, outbound not-configured/success-via-
      log/failure-persists-and-rethrows/unknown-type-rejected
      (`tests/Unit/Service/DsoIngestServiceTest.php`).

## 5. Controller + routes

- [x] Wire `DSOController::receiveVerzoek()` to call
      `DsoIngestService::ingest()` after `parseVerzoek()` — fixes the
      pre-existing gap where every verzoek was logged and dropped.
      Persistence/mapping failure is caught and logged, never turns the
      webhook's 202 Accepted into an error (async-processing contract
      preserved).
- [x] Add `DSOController::listVerzoeken()` (`GET /api/dso/verzoeken`,
      authenticated, `?status=` filter).
- [x] Add `DSOController::status()` (`GET /api/dso/verzoeken/{id}`,
      authenticated).
- [x] Add `DSOController::handoff()` (`POST /api/dso/verzoeken/{id}/handoff`,
      authenticated — real actor only, mirrors
      `OpenFormulierenController::handoff()`'s error mapping
      (400/403/404/409/502)).
- [x] Add `DSOController::postOutbound()`
      (`POST /api/dso/verzoeken/{id}/status`, authenticated,
      `type=status|besluit` — 503 when unconfigured, 502 on transport
      failure).
- [x] `ActionAuthService` action strings: `dso.list`, `dso.status`,
      `dso.handoff`, `dso.status-post` (matrix defaults to admin-only, no
      code change needed in `ActionAuthService` itself — config-driven).
- [x] Update `appinfo/routes.php`.
- [x] Update `tests/Unit/Controller/DSOControllerTest.php` for the new
      constructor + new endpoint tests (list/status/handoff/outbound,
      401-when-unauthenticated, ingest-persists, ingest-failure-does-not-
      break-the-202-ack).
- [x] `composer check:routes` — all 159 routes point at existing controller
      methods.

## 6. Quality gates

- [x] Full PHPUnit suite green: 1326/1326, 0 failures (only the
      pre-existing, unrelated "deprecated phpunit.xml schema" runner
      notice — not a test failure).
- [x] `composer phpcs` — 0 errors on every new/modified `lib/` file
      (`DSOController.php`, `DsoIngestService.php`, `Dso/*.php`,
      `Exception/Dso*.php`); new test files match the exact, already-merged
      sibling-adapter test convention (verified `OpenFormulierenIntakeServiceTest.php`/
      `IStandaardenClientTest.php` carry the identical "named parameters"/
      "missing short description" sniff violations at HEAD — pre-existing,
      fleet-wide test-layer debt, not introduced by this change).
- [x] `composer phpmd` — 0 new violations (baseline diff: every reported
      line is in a pre-existing, untouched file).
- [x] `composer psalm` — 0 new errors (2 pre-existing `IMetricsProvider`
      errors, unrelated).
- [x] `composer phpstan` — 0 errors on all new/modified files (caught + fixed
      one real issue: an injected-but-unused `IL10N $l` on
      `DsoIngestService`, removed — the service layer doesn't translate
      user-facing strings, mirrors `OpenFormulierenIntakeService`'s
      identical no-`IL10N` constructor).

## Acceptance criteria

- Every DSO Verzoek received on the STAM koppelvlak is now persisted as a
  `dso_verzoek` OR record (previously: logged and dropped, zero persistence).
- A `mapped` verzoek can be handed off to `ns#Case` by an authenticated
  caller; `HandoffService` v1's no-system-user-lane constraint is honoured
  identically to `open-formulieren-intake`.
- An outbound `status`/`besluit` post is dispatched via the provider seam
  (`log` default = zero network calls, safe for dev/CI) and audited in
  `dso_message` on both success and failure.
- Full PHPUnit suite green; `composer check:routes` passes; no new
  phpcs/phpmd/psalm/phpstan issues in any touched/new file.
