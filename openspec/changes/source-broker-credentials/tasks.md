# Tasks — source calls through the OpenRegister credential broker

> Implementation order follows the design: config contract first, then the
> brokered branch, then error mapping, then tests. Cross-repo prerequisites
> (OR `credential-doriath-leaf` acting-user param + guard-name surface, OR
> `credential-provider-doffin`) are tracked in openregister — the
> background-job task below feature-detects rather than assumes them.

- [ ] Define the `credentialRef` contract on source authentication config: accept `{credentialId}` or `{credentialName}` under `configuration.authentication.credentialRef`; document it in the source schema description in `lib/Settings/` register JSON (no schema shape change — `authentication` is already free-form object config)
- [ ] Add a config validator used at call time: `credentialRef` present → reject sibling secret-bearing fields under `authentication` (anything beyond `credentialRef`) as a hard config error; reject `credentialId` + `credentialName` both set; reject nil/empty values
- [ ] Create `lib/Service/BrokeredCallService.php`: broker availability check (`class_exists` on `OCA\OpenRegister\Service\Credential\CredentialBrokerService` + `IAppManager::isEnabledForUser('openregister')`), `\OCP\Server::get` resolution, and a typed `isBrokered(array $sourceData): bool` helper
- [ ] Implement `credentialName` → `credentialId` resolution against the acting user's OR `brokeredcredential` metadata objects; zero or >1 matches → config error carrying the name and match count
- [ ] Implement request derivation: compose URL as today (`location` + `endpoint`), extract path + query (serialising `config['query']` into the query string), pass normalised `config['headers']` and `config['body']`/JSON-encoded `config['json']` to `CredentialBrokerService::request(credentialId, 'openconnector', method, path, headers, body)`
- [ ] Adapt the broker's `array{status, headers, body}` return to a `GuzzleHttp\Psr7\Response` so `buildResponseData()` / `buildAndPersistCallLog()` / `sourceRateLimit()` run unchanged
- [ ] Wire the brokered branch into `CallService::dispatchRequest()` ahead of the Guzzle dispatch, selected on `authentication.credentialRef` in the merged source configuration
- [ ] Add the v1 scope guards as 409 config errors via `saveEarlyErrorLog()`: `credentialRef` on `type: soap` sources, `asynchronous=true`, and `cert`/`ssl_key` config alongside `credentialRef`
- [ ] Implement soft-fail: broker classes absent or openregister disabled → 409 config-error CallLog with an actionable message; assert there is NO code path that falls back to embedded authentication when `credentialRef` is set
- [ ] Thread the acting user: session user when present; in background context pass `actingUserId` = the credential's owner via the broker's optional acting-user parameter, feature-detected by reflection on the `request()` signature — parameter absent + no session → soft-fail config error, never a TypeError
- [ ] Map broker exceptions to CallLogs: `CredentialAccessDeniedException` → 403 with the broker-surfaced guard name and the "add openconnector to allowedApps" hint; `CredentialUpstreamException` → 502; both messages secret-free and payload-free
- [ ] Secret-hygiene audit of the brokered path: confirm the secret value cannot appear in source config, sync logs, CallLogs, or error messages (it never enters the process); extend the existing redaction unit tests with a brokered-call fixture
- [ ] Unit tests for `BrokeredCallService`: availability guard, name resolution (0/1/many), request derivation (path+query, headers, body), response adaptation (2xx, upstream non-2xx, header shapes)
- [ ] Unit tests for `CallService` brokered branch: branch selection, sibling-secret rejection, SOAP/async/cert scope guards, soft-fail without OR, 403/502 mapping, CallLog envelope parity with the Guzzle path
- [ ] Synchronization pagination test: a multi-page sync against a brokered source issues one brokered request per page and honours the engine's existing rate-limit tracking from returned headers
- [ ] Verify e2e on a dev instance with OR present: a source with `credentialRef` (nil-UUID fixture credential, `YOUR_API_KEY_HERE` secret in the vault fixture) round-trips through the broker; a 403 refusal logs the guard name; docs page under `website/docs` updated for source authentication
- [ ] Run `composer check:strict` and fix anything it flags in touched files

Acceptance criteria (plain bullets — verified by /opsx-verify):

- A source whose `configuration.authentication` is exactly `{"credentialRef": {"credentialId": "00000000-0000-0000-0000-000000000000"}}` dispatches through `CredentialBrokerService::request()` with `appId: 'openconnector'`; the Guzzle client is not invoked for that call
- The persisted CallLog for a brokered call is shape-identical to a Guzzle-path CallLog (request/response envelope, retention, redaction), with no secret material anywhere
- A `credentialRef` source with any sibling secret field, an ambiguous `credentialName`, a missing credential, SOAP type, async dispatch, or an absent broker produces a synthetic 409 config-error CallLog with an actionable, secret-free message — and never sends an outbound request with embedded secrets
- A broker 403 produces a 403 CallLog whose statusMessage names the failing guard, not the payload
- A background synchronization (no user session) against a brokered source succeeds by passing the credential owner as acting user, with allowedApps/allowRules/host-lock still enforced by the broker
- Sources without `credentialRef` behave byte-for-byte as before (legacy path regression suite green)
