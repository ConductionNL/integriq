# http-call-engine — Delta: brokered source credentials

## Purpose

Extends the outbound HTTP call engine so a Source can reference an
OpenRegister-brokered credential (`credentialRef`) instead of embedding
secrets in its authentication configuration. Brokered sources dispatch
IN-PROCESS through `OCA\OpenRegister\Service\Credential\
CredentialBrokerService::request()` (constrained proxy: owner IDOR →
allowedApps → provider allowRules → host-lock, secret injected server-side);
the engine's CallLog, redaction, retention, rate-limit, and pagination
behaviour is unchanged. The consuming app never holds the secret.

@e2e exclude backend outbound call engine + credential broker plumbing (no browser UI) — covered by PHPUnit/Newman

## ADDED Requirements

### Requirement: credentialRef source authentication contract (REQ-SBC-001)

The engine MUST accept a `credentialRef` object under a Source's
`configuration.authentication` in exactly one of two shapes:
`{"credentialId": "<uuid>"}` (primary) or `{"credentialName": "<name>"}`
(convenience). When `credentialRef` is
present, the engine MUST reject the call as a hard config error (synthetic
409 CallLog via `saveEarlyErrorLog()`) if any sibling field exists under
`authentication` besides `credentialRef`, if both `credentialId` and
`credentialName` are set, or if the set value is empty. Embedded secret
fields MUST NOT be merged, rendered, or dispatched for a `credentialRef`
source under any circumstance. A `credentialName` MUST be resolved at call
time against the acting user's OR `brokeredcredential` metadata objects;
exactly one match resolves to its `credentialId`; zero or multiple matches
MUST be a hard config error naming the reference and the match count — never
a guess.

#### Scenario: a clean credentialId ref is accepted

- **GIVEN** a source whose `configuration.authentication` is exactly
  `{"credentialRef": {"credentialId": "00000000-0000-0000-0000-000000000000"}}`
- **WHEN** `CallService::call(...)` runs
- **THEN** the call SHALL proceed to brokered dispatch (REQ-SBC-002)
- **AND** no `authentication` material SHALL appear in the outbound request config
- @e2e exclude backend config validation — covered by PHPUnit

#### Scenario: sibling embedded secret next to credentialRef is a hard config error

- **GIVEN** `configuration.authentication = {"credentialRef": {"credentialId":
  "00000000-0000-0000-0000-000000000000"}, "client_secret": "YOUR_API_KEY_HERE"}`
- **WHEN** `call(...)` runs
- **THEN** a synthetic 409 `call_log` SHALL be persisted with an actionable
  message stating embedded secrets are forbidden alongside `credentialRef`
- **AND** no outbound request SHALL be dispatched (neither brokered nor Guzzle)
- @e2e exclude backend config validation — covered by PHPUnit

#### Scenario: ambiguous credentialName is a hard config error, never a guess

- **GIVEN** `credentialRef = {"credentialName": "doffin-subscription"}` AND the
  acting user owns two `brokeredcredential` objects with that name
- **WHEN** `call(...)` runs
- **THEN** a synthetic 409 `call_log` SHALL be persisted naming the reference
  and the match count (2)
- **AND** no outbound request SHALL be dispatched
- @e2e exclude backend config validation — covered by PHPUnit

### Requirement: Brokered dispatch through CredentialBrokerService (REQ-SBC-002)

`CallService::dispatchRequest()` MUST route a source whose merged
configuration carries `authentication.credentialRef` IN-PROCESS through
`CredentialBrokerService::request(credentialId, appId: 'openconnector',
method, path, headers, body)` instead of the internal Guzzle client, after
guarding availability via `class_exists` on the broker class AND
`IAppManager::isEnabledForUser('openregister')`. The engine MUST derive
`path` as the path + query-string portion of the composed URL (`location` +
`endpoint`, with `config['query']` serialised into the query string) — the
provider catalogue's host-lock is the sole authority for the target host.
The broker's `array{status, headers, body}` return MUST be adapted to a
PSR-7 response so `buildResponseData()`, `buildAndPersistCallLog()`, and
`sourceRateLimit()` operate unchanged; an upstream non-2xx status returned by
the broker is a completed call and MUST flow through as a normal CallLog with
that status. Pagination, rate-limiting, and retry logic MUST remain in
OpenConnector: each page fetched by
`SynchronizationService::fetchSinglePageData()` is one brokered request. In
v1 the engine MUST reject as a 409 config error: `credentialRef` on
`type: soap` sources, `asynchronous=true` dispatch, and `cert`/`ssl_key`
config alongside `credentialRef`.

#### Scenario: a brokered call bypasses Guzzle and persists a normal CallLog

- **GIVEN** a healthy source with a valid `credentialRef` AND the broker
  reachable in-process AND an upstream 200 response
- **WHEN** `call(...)` runs
- **THEN** `CredentialBrokerService::request(...)` SHALL be invoked with
  `appId = 'openconnector'` and the derived path + query
- **AND** the internal Guzzle client SHALL NOT be invoked
- **AND** a `call_log` SHALL be persisted with the same envelope shape as a
  Guzzle-path call (statusCode 200, headers, body, responseTime, retention)
- @e2e exclude backend dispatch plumbing — covered by PHPUnit

#### Scenario: each synchronization page is one brokered request

- **GIVEN** a synchronization against a brokered source whose upstream
  paginates across 3 pages
- **WHEN** the synchronization runs
- **THEN** the broker SHALL be invoked exactly 3 times (one per page)
- **AND** rate-limit headers returned by the broker SHALL feed the engine's
  existing `sourceRateLimit()` tracking
- @e2e exclude backend sync pagination — covered by PHPUnit

#### Scenario: upstream 404 through the broker is a completed call

- **GIVEN** a brokered source AND the provider upstream returns 404
- **WHEN** `call(...)` runs
- **THEN** a `call_log` SHALL be persisted with `statusCode = 404` (not a
  broker refusal, not a config error)
- @e2e exclude backend dispatch plumbing — covered by PHPUnit

### Requirement: Acting user for sessionless brokered calls (REQ-SBC-003)

Interactive brokered calls MUST rely on the broker's session-derived owner
guard. Background executions (cron `JobTask` → synchronizations — no user
session) MUST pass `actingUserId` = the referenced credential's owner via the
broker's optional acting-user parameter for in-process trusted callers
(cross-repo: specced in openregister change `credential-doriath-leaf`). The
acting-user parameter substitutes only the session identity: allowedApps
(which MUST include `openconnector`), provider allowRules, and host-lock
remain enforced by the broker. When the deployed broker does not yet expose
the acting-user parameter, a sessionless brokered call MUST soft-fail as a
409 config error (feature-detected — never a PHP type error).

#### Scenario: a background sync brokered call passes the credential owner

- **GIVEN** a synchronization job running from cron with no user session AND a
  source with a valid `credentialRef`
- **WHEN** the job dispatches a page request
- **THEN** the broker SHALL be invoked with `actingUserId` = the credential's
  owner
- **AND** the broker's allowedApps / allowRules / host-lock guards SHALL still
  apply
- @e2e exclude backend cron execution — covered by PHPUnit

#### Scenario: older broker without acting-user support soft-fails sessionless calls

- **GIVEN** a deployed OpenRegister whose `request()` has no acting-user
  parameter AND a sessionless brokered call
- **WHEN** the job dispatches
- **THEN** a synthetic 409 `call_log` SHALL be persisted with an actionable
  message about the OpenRegister version requirement
- **AND** no fallback dispatch SHALL occur
- @e2e exclude backend cron execution — covered by PHPUnit

### Requirement: Secret hygiene and refusal logging for brokered calls (REQ-SBC-004)

The brokered credential's secret value MUST NEVER appear in source
configuration, synchronization logs, call logs, or error messages — with
brokering the secret never enters the OpenConnector process. Broker refusals
(`CredentialAccessDeniedException`) MUST be persisted as 403 CallLogs whose
statusMessage carries the broker-surfaced guard name (e.g. `allowedApps`,
with the actionable hint that the credential's allowedApps must include
`openconnector`) and MUST NOT carry the request payload. Broker transport
failures (`CredentialUpstreamException`) MUST be persisted as 502 CallLogs.
When the broker classes are absent, the openregister app is disabled, or the
referenced credential no longer exists, the call MUST fail with a clear 409
config-error CallLog; the engine MUST NOT fall back to embedded secrets.

#### Scenario: a broker 403 logs the guard name, not the payload

- **GIVEN** a brokered source whose credential's allowedApps does not include
  `openconnector`
- **WHEN** `call(...)` runs
- **THEN** a `call_log` SHALL be persisted with `statusCode = 403` and a
  statusMessage naming the failing guard and the allowedApps remedy
- **AND** neither the request payload nor any secret material SHALL appear in
  the log or error message
- @e2e exclude backend error mapping — covered by PHPUnit

#### Scenario: broker absent soft-fails with a config error, no fallback

- **GIVEN** a source with a `credentialRef` AND the openregister app disabled
- **WHEN** `call(...)` runs
- **THEN** a synthetic 409 `call_log` SHALL be persisted stating the credential
  broker is unavailable
- **AND** no outbound request SHALL be dispatched with embedded secrets
- @e2e exclude backend error mapping — covered by PHPUnit

#### Scenario: deleted credential soft-fails with a config error

- **GIVEN** a source referencing `credentialId =
  00000000-0000-0000-0000-000000000000` that no longer exists
- **WHEN** `call(...)` runs
- **THEN** a synthetic 409 `call_log` SHALL be persisted naming the missing
  reference
- **AND** no outbound request SHALL be dispatched
- @e2e exclude backend error mapping — covered by PHPUnit
