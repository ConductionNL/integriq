## Context

The STAM intake today:

1. `DSOController::receiveRequest()` is a `#[PublicPage]` route. It reads the raw body and checks `X-DSO-Signature` with `DSOSignatureVerifierService`.
2. The verifier reads its trust from app config: `dso_pki_mode`, `dso_pki_hmac_secret`, `dso_pki_signing_certificate`, `dso_pki_intermediate_chain`, `dso_pki_root_ca`. The admin section `DsoPkiSettings` edits those keys.
3. `DsoIngestService::ingest()` saves the `dso_verzoek` (`received`), translates and maps it, saves it again (`mapped` or `failed`), and queues `FetchDsoAttachmentsJob`.
4. The job reads the DSO `source` through `RawSourceResolver` (`_rbac: true`), downloads the bijlagen and saves the outcome.

Nothing in that chain has a user. OpenRegister refuses every write. The live evidence is in the proposal.

Integriq has three ways to give inbound work an identity:

- **Consumers.** `AuthorizationService::authorizeApiKey()` matches a presented key to a `consumer` and sets that consumer's `userId` as the active user. The JWT issuer path does the same with `issuer.userId`.
- **Endpoint authentication rules.** An `authentication` rule maps a header credential (`apikey`, `jwt`, `basic`, `oauth`, `nc-session`) to a user, inside the endpoint runtime.
- **Flow owners.** `FlowOwner` runs a flow step as `context['triggeredBy']`, and refuses when that is empty.

## Goals / non-goals

Goals: every OpenRegister write of the intake and its job has a named owner and passes RBAC. A missing or wrong identity fails loud. An admin sets the identity, and integriq checks it.

Non-goals: moving the intake onto the endpoint runtime, changing the STAM wire format, the outbound status push (REQ-DSO-040), and the case handoff (it already runs as the calling user).

## Decisions

### D1. DSO-LV is a consumer with `authorizationType: dso-stam`

A consumer is integriq's record of "an outside system that may call us". It already has `authorizationType`, a write-only `authorizationConfiguration`, an admin-only lockdown (`99-consumer-lockdown.json`), a page in the app (Consumers), and a `userId` that `authorizeApiKey()` treats as the backing account.

The DSO consumer:

| Field | Value |
|---|---|
| `name` | `DSO-LV (STAM)` |
| `authorizationType` | `dso-stam` |
| `authorizationConfiguration.mode` | `hmac` or `pkioverheid` |
| `authorizationConfiguration.hmacSecret` | pre-production shared secret |
| `authorizationConfiguration.signingCertificate`, `intermediateChain`, `rootCa` | production trust chain, PEM |
| `userId` | the Nextcloud account the intake acts as |

There is at most one `dso-stam` consumer per instance. A second one is refused on save. One gemeente has one STAM koppeling per environment, and two consumers would make "which account" ambiguous.

`consumer.userId` is described today as "the user that created the consumer". The code already uses it as the backing identity (`authorizeApiKey()`). This change corrects the description to match the code. It does not add a second field.

### D2. `DsoConnection` does the work the controller should not

A new `Service\Dso\DsoConnection` has one public method, `authenticate(string $rawBody, ?string $signatureHeader): IUser`. It:

1. finds the `dso-stam` consumer. This is an engine read of admin configuration, done as the endpoint runtime reads rules: `_rbac: false`, `_multitenancy: false`, `_render: false`, so the write-only trust fields come back. It is a read, never a write;
2. verifies the signature with `DSOSignatureVerifierService::verify()`, which now takes the trust configuration as an argument instead of reading app config;
3. resolves `userId` with `IUserManager::get()` and checks the user is enabled;
4. checks the user's `create` and `update` rights on `dso_verzoek` with OpenRegister's `PermissionHandler::hasPermission()`.

It throws one of two exceptions:

- `DsoSignatureException` when step 2 fails. The controller answers 401, as today.
- `DsoConnectionUnavailableException` with a machine reason (`no_connection`, `no_account`, `account_unknown`, `account_disabled`, `account_lacks_rights`). The controller answers 503.

Step 4 runs before the first write. That closes the gap PR #2488 left open: without it, a user with `create` but not `update` leaves a `received` record behind, and the retry adds a second one.

### D3. Ingest runs inside `ObjectService::runAs()`

```php
$account = $this->connection->authenticate(rawBody: $rawBody, signatureHeader: $header);
$stored = $this->objectService->runAs($account, fn () => $this->ingestService->ingest(parsedRequest: $request, actingUserId: $account->getUID()));
```

`runAs()` sets the active user with `setVolatileActiveUser()` and restores the previous one in a `finally`. Nothing is written into the PHP session. This is the canonical scoped identity of ADR-099; `FlowOwner::runAs()` is a duplicate due for retirement and is not used here.

The OpenRegister audit trail records the account on every create and update. `dso_verzoek` also gains `receivedVia: {consumer: <uuid>, account: <uid>}`, so a case worker sees on the record itself which connection delivered it.

### D4. A retry finds its record

DSO-LV identifies a verzoek by its verzoeknummer, and STAM adds a volgnummer. `ingest()` first looks up a `dso_verzoek` with the same `verzoekId` (and `volgnummer`, when the payload has one) under the account's own rights:

- found and `mapped` or `failed`: answer 202 with the existing record, write nothing;
- found and `received`: finish it (translate, map, save, queue);
- not found: create it, as today.

### D5. The job carries the account and runs as it

`FetchDsoAttachmentsJob` gets `['requestUuid' => …, 'actingUserId' => …]`. On run it resolves the user and calls the fetcher inside `ObjectService::runAs()`.

When the user no longer resolves, or is disabled, the job writes nothing. It logs an error naming the verzoek and the account, and sends the admin notification of D7. The entries stay `pending`, so a rerun after the fix completes them.

The job does not read the consumer's current account instead. ActorForwardedJob in OpenRegister sets the rule: re-establish the identity that did the work, never a newer authority.

The DSO `source` is admin-only (`99-source-lockdown.json`). Its comment states the engine rule: "it is the engine, not the user, that needs the source". The attachment path follows it: the source read becomes an engine read (`_rbac: false`, `_render: false`), like the sync engine's. The account does not need to be an admin. Every write of the job stays under the account's RBAC.

### D6. The account is checked when it is set

The DSO connection settings (`DsoPkiSettingsController::setConfig()`, admin only) gain `userId`. On save it refuses, with a field error:

- a user that does not exist;
- a disabled user;
- a user without `create` and `update` on `dso_verzoek`.

It warns, without refusing, when the user is in the `admin` group. A dedicated service account keeps the audit trail readable and the blast radius small.

The admin section shows the current state in one line: "Intake acts as {display name}", or "No account set: DSO-LV pushes are refused with 503".

### D7. Failing loud

| Situation | Answer | Log | Notification |
|---|---|---|---|
| Signature does not verify | 401 `invalid_signature` | warning | none |
| No `dso-stam` consumer | 503 `dso_connection_not_configured` | error | admins |
| `userId` empty, unknown or disabled | 503 `dso_account_unavailable` | error | admins |
| Account lacks `create` or `update` | 503 `dso_account_lacks_rights` | error | admins |
| OpenRegister refuses a write anyway | 503 `verzoek_not_stored` (PR #2488) | error | admins |

Why 503 and not 4xx for configuration problems: the Digikoppeling Koppelvlakstandaard ebMS2 (5.11.2) treats a 503 as recoverable and a 4xx as a final error. A missing account is something an admin fixes in minutes. DSO-LV should keep the verzoek and deliver again, not give up.

Notifications go to the `admin` group through Nextcloud's `INotificationManager`, at most one per reason per hour. The event is "nothing was stored", so there is no object event for an `x-openregister-notifications` declaration (ADR-031) to hang on. This is the one imperative notification in the change, and the notification-dialect gate will report it as a warning.

### D8. Migration

Repair step `MigrateDsoStamConnection`, idempotent:

1. When no `dso-stam` consumer exists and at least one `dso_pki_*` key is set, create the consumer with that trust configuration. `userId` stays empty.
2. Send the admins one notification: "Choose the account the DSO intake acts as".
3. Leave the app config keys in place for one release, unread. A later release removes them.

The repair step writes the app's own configuration on nobody's behalf. That is the use the `runAsSystem()` docblock allows ("installation, migration, repair, and seeding of the application's own shipped data"). It is the only `runAsSystem()` call in this change, and it never runs during a request.

On an instance with no `dso_pki_*` keys nothing is created. The admin creates the connection in the settings section.

## Rejected alternatives

### Rejected: `runAsSystem()` around ingest

It would make the 403 go away in one line. OpenRegister's docblock on `ObjectService::runAsSystem()` rules it out:

> 🔴 REACHABILITY BOUNDARY (ADR-099). This is code-initiated only. It MUST NOT be reachable from: a flow node, a tool invoked by an agent, the handling of an inbound request.

and

> An escape hatch that turns a refusal into a success gets taken. Where an identity cannot be resolved the correct outcome is a refusal naming what is missing; escalating to a userless principal instead answers a different question than the one that was asked.

The STAM intake is the handling of an inbound request. It also leaves the audit trail without an owner.

### Rejected: `_rbac: false` on the writes

Same effect as `runAsSystem()`, with less ceremony. The audit trail has no owner, RBAC is skipped for data that carries BSNs, and the next reader copies the pattern. Reads of admin configuration (D2, D5) keep the existing engine pattern; writes never do.

### Rejected: an app-config key with a service user id

`dso_intake_user` in `IAppConfig` would work. It adds a second identity store next to the consumer model, invisible on the Consumers page, with its own validation and its own UI. `authorizeApiKey()` already resolves consumer to user; this change uses the same path.

### Rejected: make `dso_verzoek` creatable by the public

An `authorization.create: ["public"]` block lets anonymous calls through RBAC. Anyone who reaches the URL could then create verzoeken that bypass the signature check, by calling OpenRegister's own object API. The audit trail still has no owner.

### Rejected: move the intake onto the endpoint runtime

A seeded `endpoint` with a `webhook_signature` rule, an `authentication` rule and an ingest step is closer to "everything is an integriq connection". It does not fit today:

- the `webhook_signature` rule only gates. It resolves no identity;
- every `authentication` rule type reads a credential from a header. None verifies a signature over the body, and none knows PKIoverheid;
- an endpoint must target a register/schema (plain CRUD) or a source (proxy). Ingest is neither: it parses, translates, maps, matches retries and queues a job;
- the pipeline is admin-editable. Removing the signature rule from a statutory intake would be one click;
- the push URL would move to `/api/endpoint/...`, which means a CPA and adapter change at every gemeente.

A consumer gives the identity, the admin page and the audit trail now. When the endpoint runtime gains a service target and a signature-to-identity rule, the intake can move with its consumer intact.

### Rejected: let a flow supply the identity

`FlowOwner` runs a step as the run's `triggeredBy` and refuses an unattributed run, on purpose. An inbound push has no `triggeredBy`. A flow can run after the identity is known, but it cannot be the source of it.

## Risks / trade-offs

- **`PermissionHandler::hasPermission()` is not a published OpenRegister contract.** `lib/Contract/` has `ObjectServiceInterface` and the slug resolver only. The check is resolved lazily and pinned by a unit test, the same treatment `FileService` gets in `dso-attachments-on-the-request`. When the handler is absent the check reports "unknown", the save warns, and the write itself still runs under RBAC, so the worst case is a 503 at intake, never a silent pass.
- **One account for all DSO verzoeken.** Every record shows the same owner. `receivedVia` records the connection; the initiatiefnemer stays in `requester`.
- **The account can lose its rights later.** D2 step 4 catches that per push, with a 503 and a notification.
- **Engine reads with `_rbac: false`.** The consumer and source reads skip RBAC on purpose, as the endpoint runtime and sync engine already do. They are reads of admin configuration, scoped to one known slug.

## Migration

See D8. After the upgrade an admin opens the DSO connection section, picks the account, saves. Until then DSO-LV gets 503 and retries. How long it keeps retrying is set per koppeling in the CPA (`Retries` and `RetryInterval`, ebMS2 5.5.4). We do not know the DSO-LV values, so the upgrade note tells admins to set the account straight after upgrading.
