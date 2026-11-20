## Context

Before this change the Open Formulieren intake worked like this:

1. `OpenFormulierenController::inbound()` is a `#[PublicPage]` route.
2. It called `OpenFormulierenIntakeService::resolveActiveSource()`: a `findAll` on the `source` schema under the caller's RBAC.
3. It verified the HMAC with `WebhookSignatureService` against `configuration.webhookSignature` of that source.
4. `ingest()` saved the submission (`received`), applied the form mapping, fetched the attachments with `FileService::addFile()`, and saved it again (`mapped` or `failed`).

The `source` schema is admin-only (`99-source-lockdown.json`). An anonymous caller reads nothing, so step 2 returned no source and step 3 answered 401. Had the source been readable, step 4 would have failed next: OpenRegister refuses an anonymous write (#1955). The live evidence is in the proposal.

`dso-intake-through-an-integriq-connection` solved the same problem for DSO-LV. Its design is the reference for this one; only the differences are written down here.

## Ruben's decision (2026-10-04)

Open Formulieren runs on the same consumer model as DSO, after DSO. Prove the failure live first. The four DSO decisions apply unchanged: a consumer whose `userId` is the account, 503 on a missing or unusable account, rights checked with `PermissionHandler::hasPermission()`, engine reads of admin configuration only.

## Decisions

### D1. Open Formulieren is a consumer with `authorizationType: open-formulieren`

| Field | Value |
|---|---|
| `name` | `Open Formulieren` |
| `authorizationType` | `open-formulieren` |
| `authorizationConfiguration.scheme` | `openconnector` (default), `stripe`, `github` or `teams` |
| `authorizationConfiguration.secret` | the shared webhook secret |
| `authorizationConfiguration.header` | `X-OpenFormulieren-Signature` (default) |
| `authorizationConfiguration.toleranceSeconds` | 300 (default) |
| `userId` | the Nextcloud account the intake acts as |

The trust keys are the keys `configuration.webhookSignature` used on the source, so the migration copies them one to one. At most one `open-formulieren` consumer exists per instance; `DsoStamConsumerListener` now refuses a second one of either type. One DSO and one Open Formulieren connection may exist side by side.

### D2. Reuse the DSO building blocks, do not build a second mechanism

`OpenFormulierenConnection` holds only what differs: the consumer type, the schema, the signature verifier and the messages. Everything else is the DSO code with a parameter added:

| Block | Change |
|---|---|
| `DsoConnection::findConsumers()` | takes the `authorizationType` (default `dso-stam`) |
| `DsoConnection::runAs()` | used as is |
| `DsoAccountRights::missing()` | takes the schema slug (default `dso_verzoek`) |
| `DsoIdentity` | used as is |
| `DsoConnectionUnavailableException` | takes a channel; it prefixes the error code (`openformulieren_account_unavailable`) |
| `DsoConnectionAlerts::notify()` | takes a channel; the throttle key and the subject carry it, the DSO keys keep their names |
| `DsoConnectionNotifier` | renders the Open Formulieren text when the channel says so |
| `DsoSignatureException` | used as is for a bad signature |

The `Dso` prefix on shared classes is now historical. Renaming them is a follow-up; it would touch every DSO file the parallel `dso-activity-mapping-table` change edits.

### D3. Ingest runs inside `ObjectService::runAs()`

The controller authenticates, then runs `ingest()` inside `runAs($account, …)`. Every write, the attachment files included, has the account as owner. The previous user (none, for a webhook) is restored in a `finally`. `ingest()` records `receivedVia: {consumer, account}`.

### D4. Failing loud

| Situation | Answer | Notification |
|---|---|---|
| Signature does not verify | 401 `invalid signature` (unchanged body) | none |
| No `open-formulieren` consumer, or two | 503 `openformulieren_connection_not_configured` | admins |
| `userId` empty, unknown or disabled | 503 `openformulieren_account_unavailable` | admins |
| Account lacks `create` or `update`, or rights cannot be checked | 503 `openformulieren_account_lacks_rights` | admins |
| OpenRegister refuses a write anyway | 503 `submission_not_stored` | admins |
| `form.slug` missing | 400 (unchanged) | none |

A missing connection used to answer 401. It now answers 503: it is a configuration problem an admin fixes, and the sender should deliver again. Open Formulieren retries a webhook that fails with a 5xx.

### D5. A retry finds its record

`ingest()` first looks up a submission with the same `submissionUuid`, under the account's own rights. The account owns what it stored, so OpenRegister's owner rule lets it find its own earlier delivery. Found and `mapped`, `failed` or `handed_off`: answer with it, write nothing. Found and `received`: finish it. Not found: create it. A submission without a uuid is always created.

### D6. The handoff and the copy to the case

The `submission-to-case` handoff is unchanged: a handler triggers it with their own session (REQ-004). The copy of the attachments onto the created case runs inside that request, so it runs as the handler, the same account as the handoff. It never switches to the intake account; doing so in a handler's request would write the case files under the wrong owner. The handler reads the submission through the read rule of `bsn-intake-records-access-rules`.

**Open: the handler cannot see the intake account's files.** OpenRegister stores an object's files in the home folder of the account that stored them. The live run (run-19) listed the two attachments of an intake submission through OpenRegister's files API: 2 for the intake account, 0 for a handler and 0 for an administrator. `FileService::copyFile()` reads the source file through that same folder, so the post-handoff copy will not find the file when a handler triggers the handoff. Running the copy as the intake account would write the case files under the wrong owner. This needs an OpenRegister answer (object files readable by whoever may read the object). The same holds for DSO bijlagen. It is not fixed here.

### D7. Migration

Repair step `MigrateOpenFormulierenConnection`, idempotent: when no `open-formulieren` consumer exists and an enabled `open-formulieren` source carries `configuration.webhookSignature.secret`, it creates the consumer with that trust and an empty account, and notifies the admins once ("Choose the account the Open Formulieren intake acts as"). The source is left as it is. The source read is an engine read; the consumer write runs in OpenRegister's system operation context, as the DSO migration does. Until an admin picks the account, submissions answer 503.

## Contract gaps

The same two as `dso-intake-through-an-integriq-connection`: `PermissionHandler::hasPermission()` is not a published contract, and engine reads use `_rbac: false` on the concrete `ObjectService`. This change adds two read sites: the `open-formulieren` consumer, and the source in the repair step. No write skips RBAC.

## Rejected alternatives

The DSO design rejects `runAsSystem()`, `_rbac: false` on writes, an app-config service user, a public `create` grant and the endpoint runtime. Each reason holds here unchanged.

### Rejected: keep the trust on the source and read it as the engine

It would fix the 401 in one line. It leaves the account question open: a second store for "who does this webhook act as", invisible on the Consumers page, and different from DSO.
