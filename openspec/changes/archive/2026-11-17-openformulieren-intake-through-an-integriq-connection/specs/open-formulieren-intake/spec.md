## MODIFIED Requirements

### Requirement: Signed inbound submission webhook (REQ-001)

Integriq MUST expose `POST /api/open-formulieren/submissions` as a `#[PublicPage]` endpoint (no Nextcloud session) gated by HMAC verification via the existing `WebhookSignatureService`. The shared secret, scheme, header and tolerance MUST come from the `authorizationConfiguration` of the instance's one `open-formulieren` consumer, read as an engine read of admin configuration. A missing or invalid signature MUST return 401 with an undifferentiated error body before any state change. A missing or ambiguous connection MUST return 503 with error `openformulieren_connection_not_configured` before any state change, so the sender delivers again.

#### Scenario: Valid signature is accepted

- GIVEN an `open-formulieren` consumer with a configured secret and a usable account
- WHEN a submission POST arrives with a valid HMAC signature over the exact raw body bytes
- THEN the request is accepted and processing proceeds as that account
- @e2e exclude backend HMAC verification: covered by PHPUnit and the live proof, a browser cannot sign a webhook

#### Scenario: Invalid signature is rejected

- GIVEN an `open-formulieren` consumer with a configured secret
- WHEN a submission POST arrives with a signature computed against a different secret
- THEN the endpoint returns HTTP 401 with no state change and no submission record is created
- @e2e exclude backend HMAC verification: covered by PHPUnit

#### Scenario: Missing signature is rejected

- GIVEN an `open-formulieren` consumer with a configured secret
- WHEN a submission POST arrives with no signature header at all
- THEN the endpoint returns HTTP 401 with no state change
- @e2e exclude backend HMAC verification: covered by PHPUnit

#### Scenario: No active source configured fails closed

The trust no longer lives on a source: the "source" of this scenario is now the `open-formulieren` consumer. It still fails closed, with 503 instead of 401, so the sender delivers again.

- GIVEN no `open-formulieren` consumer exists, or two exist
- WHEN a submission POST arrives
- THEN the endpoint returns HTTP 503 with error `openformulieren_connection_not_configured`
- AND nothing is written
- AND the administrators get one notification
- @e2e exclude backend webhook refusal: covered by PHPUnit and the live proof

## ADDED Requirements

### Requirement: The intake acts as the Open Formulieren connection's account (REQ-006)

Every OpenRegister write of the intake MUST run as the `userId` of the `open-formulieren` consumer, inside OpenRegister's `ObjectService::runAs()`, with the previous user restored afterwards. That covers the submission's create and updates and the stored attachment files. Before the first write the intake MUST check that the account exists, is enabled, and holds `create` and `update` on `openformulieren_submission`. When any check fails, or cannot run, the endpoint MUST answer 503 with a named error, write nothing, log an error and notify the administrators at most once per reason per hour. The stored submission MUST record `receivedVia: {consumer, account}`. A repeated delivery of the same `submission.uuid` MUST NOT create a second record. The intake MUST NOT use `runAsSystem()` or `_rbac: false` on a write.

#### Scenario: The submission is stored as the configured account

- GIVEN an `open-formulieren` consumer with `userId` `of-intake`, an account with `create` and `update` on `openformulieren_submission`
- WHEN a correctly signed submission arrives without a login
- THEN the submission is stored and mapped with owner `of-intake`
- AND its attachments are stored as `of-intake`
- AND `receivedVia` names the consumer and `of-intake`
- @e2e exclude backend identity: covered by PHPUnit through the real path and by the live proof

#### Scenario: A missing account fails loud

- GIVEN an `open-formulieren` consumer whose `userId` is empty, unknown or disabled
- WHEN a correctly signed submission arrives
- THEN the endpoint answers 503 with error `openformulieren_account_unavailable`
- AND nothing is written
- AND the administrators are told the Open Formulieren connection has no usable account
- @e2e exclude backend identity: covered by PHPUnit and the live proof

#### Scenario: An account without rights writes nothing half

- GIVEN an account with `create` but not `update` on `openformulieren_submission`, or rights that cannot be checked
- WHEN a correctly signed submission arrives
- THEN the endpoint answers 503 with error `openformulieren_account_lacks_rights`
- AND no `received` record is left behind
- @e2e exclude backend identity: covered by PHPUnit and the live proof

#### Scenario: A submission that was not stored is answered with 503

- GIVEN a usable account
- WHEN OpenRegister refuses a write of the submission anyway
- THEN the endpoint answers 503 with error `submission_not_stored`
- AND the administrators get one notification
- @e2e exclude backend refusal path: covered by PHPUnit

#### Scenario: A repeated delivery creates no second record

- GIVEN a submission with `submission.uuid` U was stored and mapped
- WHEN the same submission arrives again
- THEN the endpoint answers with the stored record
- AND nothing is written and no attachment is fetched again
- @e2e exclude backend retry matching: covered by PHPUnit and the live proof

#### Scenario: Existing configuration migrates without an account

- GIVEN no `open-formulieren` consumer and an enabled `open-formulieren` source with `configuration.webhookSignature.secret`
- WHEN the repair step runs
- THEN one `open-formulieren` consumer exists with that trust and an empty `userId`
- AND the administrators get one notification to choose the account
- AND a second run creates nothing
- @e2e exclude repair step: covered by PHPUnit and the live proof

### Requirement: The Open Formulieren connection's account is chosen and checked by an administrator (REQ-007)

The integriq admin settings MUST hold an Open Formulieren connection section that edits the one `open-formulieren` consumer: scheme, secret, header, tolerance and account. The secret MUST never be returned; a blank secret keeps the stored one. On save the account MUST be refused with a field error when it does not exist, is disabled, or lacks `create` and `update` on `openformulieren_submission`. An administrator account MUST save with a warning. The section MUST show in one line which account the intake acts as, or that none is set.

#### Scenario: Choosing a valid account

- GIVEN an administrator in the Open Formulieren connection section
- WHEN they choose an account that holds the rights and save
- THEN the consumer stores that account
- AND the section reads "Intake acts as" that account

#### Scenario: An account without rights is refused

- GIVEN an administrator in the Open Formulieren connection section
- WHEN they choose an account that does not exist, is disabled or cannot store submissions
- THEN the save is refused with a field error naming the account
- AND the stored account is unchanged

#### Scenario: No account set is shown plainly

- GIVEN an `open-formulieren` consumer without an account
- WHEN an administrator opens the section
- THEN it reads "No account set: Open Formulieren submissions are refused with 503"
