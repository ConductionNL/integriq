# Tasks — source calls through the OpenRegister credential broker

> Implementation order follows the design: config contract first, then the
> brokered branch, then error mapping, then tests. Cross-repo prerequisites
> (OR `credential-doriath-leaf` acting-user param + guard-name surface, OR
> `credential-provider-doffin`) are tracked in openregister — the
> background-job task below feature-detects rather than assumes them.

> **Re-verified against `origin/development` HEAD (2026-07-15, worktree
> `wip/build-source-broker-credentials`).** Tasks 1-15 and 17 were already
> implemented and merged into `development` across three commits before this
> build pass: `e4cde590` (feat(call-engine): brokered source credentials via
> OpenRegister credentialRef), `1ecf84af` (feat(call-engine): owner-pinned
> acting-user for sessionless brokered syncs), `3c7b8d7e` (feat(source):
> app-side credential injection via authentication placeholders). This pass
> re-verified each against the current `lib/Service/BrokeredCallService.php`
> / `lib/Service/CallService.php` / `tests/Unit/Service/BrokeredCallServiceTest.php`
> / `tests/Unit/Service/CallServiceTest.php`, found the wiring intact and
> `composer check:strict` clean except for one newly-surfaced `CallService::call()`
> PHPMD complexity violation (NPath 288 > 200, from the accumulated Phase 7b/7c
> branching) — fixed in this pass by extracting `resolveCallCredentials()` (a
> behaviour-neutral consolidation of the existing `resolveBrokeredDispatch()` +
> `hydrateInjectedCredentials()` calls into one short-circuit check), plus an
> unrelated pre-existing PHPStan "$response might not be defined" finding in
> `dispatchWithRetry()` (retry-policy feature, not part of this change) fixed
> with an explicit null-init + defensive post-loop guard. Both fixes are
> non-behavioural — full baseline suite (1407 tests) is green before and after,
> identical pass/skip count.

- [x] Define the `credentialRef` contract on source authentication config: accept `{credentialId}` or `{credentialName}` under `configuration.authentication.credentialRef`; document it in the source schema description in `lib/Settings/` register JSON (no schema shape change — `authentication` is already free-form object config)
- [x] Add a config validator used at call time: `credentialRef` present → reject sibling secret-bearing fields under `authentication` (anything beyond `credentialRef`) as a hard config error; reject `credentialId` + `credentialName` both set; reject nil/empty values
- [x] Create `lib/Service/BrokeredCallService.php`: broker availability check (`class_exists` on `OCA\OpenRegister\Service\Credential\CredentialBrokerService` + `IAppManager::isEnabledForUser('openregister')`), `\OCP\Server::get` resolution, and a typed `isBrokered(array $sourceData): bool` helper
- [x] Implement `credentialName` → `credentialId` resolution against the acting user's OR `brokeredcredential` metadata objects; zero or >1 matches → config error carrying the name and match count
- [x] Implement request derivation: compose URL as today (`location` + `endpoint`), extract path + query (serialising `config['query']` into the query string), pass normalised `config['headers']` and `config['body']`/JSON-encoded `config['json']` to `CredentialBrokerService::request(credentialId, 'openconnector', method, path, headers, body)`
- [x] Adapt the broker's `array{status, headers, body}` return to a `GuzzleHttp\Psr7\Response` so `buildResponseData()` / `buildAndPersistCallLog()` / `sourceRateLimit()` run unchanged
- [x] Wire the brokered branch into `CallService::dispatchRequest()` ahead of the Guzzle dispatch, selected on `authentication.credentialRef` in the merged source configuration
- [x] Add the v1 scope guards as 409 config errors via `saveEarlyErrorLog()`: `credentialRef` on `type: soap` sources, `asynchronous=true`, and `cert`/`ssl_key` config alongside `credentialRef`
- [x] Implement soft-fail: broker classes absent or openregister disabled → 409 config-error CallLog with an actionable message; assert there is NO code path that falls back to embedded authentication when `credentialRef` is set
- [x] Thread the acting user: session user when present; in background context pass `actingUserId` = the credential's owner via the broker's optional acting-user parameter, feature-detected by reflection on the `request()` signature — parameter absent + no session → soft-fail config error, never a TypeError
- [x] Map broker exceptions to CallLogs: `CredentialAccessDeniedException` → 403 with the broker-surfaced guard name and the "add openconnector to allowedApps" hint; `CredentialUpstreamException` → 502; both messages secret-free and payload-free
- [x] Secret-hygiene audit of the brokered path: confirm the secret value cannot appear in source config, sync logs, CallLogs, or error messages (it never enters the process); extend the existing redaction unit tests with a brokered-call fixture
- [x] Unit tests for `BrokeredCallService`: availability guard, name resolution (0/1/many), request derivation (path+query, headers, body), response adaptation (2xx, upstream non-2xx, header shapes)
- [x] Unit tests for `CallService` brokered branch: branch selection, sibling-secret rejection, SOAP/async/cert scope guards, soft-fail without OR, 403/502 mapping, CallLog envelope parity with the Guzzle path
- [x] Synchronization pagination test: a multi-page sync against a brokered source issues one brokered request per page and honours the engine's existing rate-limit tracking from returned headers
- [ ] Verify e2e on a dev instance with OR present: a source with `credentialRef` (nil-UUID fixture credential, `YOUR_API_KEY_HERE` secret in the vault fixture) round-trips through the broker; a 403 refusal logs the guard name — **docs half done** (`docs/sources.md` + `docs/features/sources.md` already document the `credentialRef` contract, the operator recipe, and the sessionless-migration failure mode — this repo has no `website/docs` path, `docs/` is canonical); **live e2e half blocked**: the shared dev Nextcloud instance (`nextcloud` container) already runs this exact code (`BrokeredCallService.php` md5-identical to the deployed `custom_apps/openconnector` checkout) and OpenRegister's `CredentialBrokerService` on that instance already exposes `resolveInjectable()` and the `actingUserId` parameter (confirmed via in-container reflection), but `occ status` reports `maintenance: true, needsDbUpgrade: true` — the instance needs an `occ upgrade` before any occ/API-driven fixture setup would work, and running that on a shared instance with unknown concurrent agent activity is out of scope for this task per the "no deploy to shared dev instance" safety rule. Left unticked — needs a human or a dedicated maintenance window to run the upgrade, then create the nil-UUID fixture credential and re-run this check.
- [x] Run `composer check:strict` and fix anything it flags in touched files — re-verified this pass: `check:no-legacy-types` PASS, `check:routes` PASS (161 routes), `lint` PASS, `phpcs`/`phpmd`/`psalm`/`phpstan` clean on `lib/Service/BrokeredCallService.php`, `lib/Service/CallService.php`, `lib/Exception/BrokeredCallConfigurationException.php` (after the two fixes noted above), full `phpunit -c phpunit-unit.xml` suite green (1407 tests, 1 pre-existing skip, identical before/after)

Acceptance criteria (plain bullets — verified by /opsx-verify):

- A source whose `configuration.authentication` is exactly `{"credentialRef": {"credentialId": "00000000-0000-0000-0000-000000000000"}}` dispatches through `CredentialBrokerService::request()` with `appId: 'openconnector'`; the Guzzle client is not invoked for that call
- The persisted CallLog for a brokered call is shape-identical to a Guzzle-path CallLog (request/response envelope, retention, redaction), with no secret material anywhere
- A `credentialRef` source with any sibling secret field, an ambiguous `credentialName`, a missing credential, SOAP type, async dispatch, or an absent broker produces a synthetic 409 config-error CallLog with an actionable, secret-free message — and never sends an outbound request with embedded secrets
- A broker 403 produces a 403 CallLog whose statusMessage names the failing guard, not the payload
- A background synchronization (no user session) against a brokered source succeeds by passing the credential owner as acting user, with allowedApps/allowRules/host-lock still enforced by the broker
- Sources without `credentialRef` behave byte-for-byte as before (legacy path regression suite green)
