## MODIFIED Requirements

### Requirement: STAM Koppelvlak Endpoint Registration (REQ-DSO-001)

The adapter MUST register a STAM-compliant inbound REST endpoint in Integriq that receives vergunningaanvragen, meldingen, and informatieverzoeken pushed from DSO-LV. The endpoint path follows `/api/dso/stam/verzoeken`. The endpoint accepts the DSO-verzoek payload (JSON or XML) and **cryptographically verifies the request signature against the trust configuration of the instance's one `dso-stam` consumer (PKIoverheid certificate chain, or HMAC shared secret in pre-production mode) via `DSOSignatureVerifierService`**. A request whose signature does not verify MUST be rejected with 401 before any payload parsing occurs. A verified request MUST be stored while acting as the consumer's configured Nextcloud account (REQ-DSO-070). The endpoint MUST answer HTTP 202 Accepted with the verzoekId only once the verzoek is stored. When the verzoek is not stored, for any reason other than a bad signature or a malformed payload, the endpoint MUST answer HTTP 503 and log the reason, so that DSO-LV delivers it again. A repeated delivery of a verzoek that is already stored MUST NOT create a second record.

@e2e exclude backend DSO/Omgevingsloket STAM integration: covered by PHPUnit, not browser UI

#### Scenario: Valid vergunningaanvraag accepted and enqueued
- **WHEN** a `dso-stam` consumer with valid trust and a valid account is configured, and DSO-LV pushes a signed vergunningaanvraag to the STAM endpoint
- **THEN** the adapter verifies the signature, stores the verzoek as the configured account, returns HTTP 202, and enqueues the verzoek for asynchronous processing

#### Scenario: Invalid webhook signature rejected
- **GIVEN** a request arrives at the STAM endpoint with an `X-DSO-Signature` header that does not verify against the consumer's trust (forged, expired certificate, or untrusted issuer)
- **WHEN** the adapter validates the signature
- **THEN** the adapter returns HTTP 401 Unauthorized with a descriptive error message, logs the failed attempt, and does NOT call `DSOParserService::parseRequest()`

#### Scenario: Missing signature header rejected
- **GIVEN** a request arrives at the STAM endpoint with no `X-DSO-Signature` header
- **WHEN** the adapter validates the signature
- **THEN** the adapter returns HTTP 401 Unauthorized without evaluating any payload content

#### Scenario: Pre-production request tagged
- **WHEN** the `dso-stam` consumer is in HMAC mode and a request arrives from the DSO-LV test environment with a valid HMAC signature
- **THEN** it is accepted and processed identically to production requests but tagged with `environment: pre-productie` in the verzoek record

#### Scenario: Malformed payload rejected
- **WHEN** DSO-LV sends a payload with a valid signature that does not conform to the STAM schema and schema validation fails
- **THEN** the adapter returns HTTP 400 Bad Request with field-level error details and does not create a verzoek record

#### Scenario: Concurrent verzoeken enqueued independently
- **WHEN** the STAM endpoint receives concurrent verzoeken as multiple DSO-LV pushes arrive simultaneously
- **THEN** each is enqueued independently using the JobService background job mechanism with unique verzoekIds, preventing duplicate processing

#### Scenario: A verzoek that was not stored is answered with 503
- **GIVEN** a signed, valid verzoek
- **WHEN** OpenRegister refuses the save, or ingest returns no stored object
- **THEN** the endpoint answers HTTP 503 with error `verzoek_not_stored` and no `status: ontvangen`
- **AND** the reason is logged at error level with the verzoekId
- **AND** no attachment job is queued

#### Scenario: No DSO connection configured
- **GIVEN** no `dso-stam` consumer exists
- **WHEN** a push arrives at the STAM endpoint
- **THEN** the endpoint answers HTTP 503 with error `dso_connection_not_configured`, stores nothing, and notifies the administrators

#### Scenario: A repeated delivery creates no second record
- **GIVEN** a verzoek with verzoekId "dso-123" is stored with status `mapped`
- **WHEN** DSO-LV delivers the same verzoek again
- **THEN** the endpoint answers HTTP 202 with the existing record's verzoekId
- **AND** exactly one `dso_verzoek` with verzoekId "dso-123" exists

### Requirement: PKIoverheid Certificate Authentication (REQ-DSO-050)

The adapter MUST authenticate with DSO-LV using PKIoverheid certificates for mutual TLS. It MUST validate incoming DSO-LV webhook signatures and support both pre-production and production certificate chains. The outbound certificates are stored on the DSO `source`. The inbound trust (HMAC secret or PKIoverheid chain) MUST be stored on the instance's one `dso-stam` consumer, write-only, and MUST NOT be read from app config once the migration of REQ-DSO-070 has run.

#### Scenario: Certificate used for outbound mTLS call
- **WHEN** a PKIoverheid certificate and private key are uploaded via the Integriq admin UI and the adapter makes an outbound call to DSO-LV
- **THEN** the certificate is written to a temporary file by CallService.getCertificate(), used for mTLS, and cleaned up after the request
- @e2e exclude mTLS with a real client certificate, and a signed inbound webhook. The e2e instance holds no DSO certificate and receives no signed callback, so neither side of the exchange can be driven from a browser session

#### Scenario: Expiring certificate triggers warning
- **WHEN** the PKIoverheid certificate expires in 30 days and the daily health check runs
- **THEN** a warning notification is sent to the Nextcloud admin with the certificate expiry date and renewal instructions
- @e2e exclude mTLS with a real client certificate, and a signed inbound webhook. The e2e instance holds no DSO certificate and receives no signed callback, so neither side of the exchange can be driven from a browser session

#### Scenario: Incoming webhook signature validated
- **WHEN** an incoming webhook from DSO-LV includes a signature header and the adapter validates it against the `dso-stam` consumer's trust configuration
- **THEN** requests with valid signatures are processed and requests with invalid signatures are rejected with HTTP 401
- @e2e exclude mTLS with a real client certificate, and a signed inbound webhook. The e2e instance holds no DSO certificate and receives no signed callback, so neither side of the exchange can be driven from a browser session

## ADDED Requirements

### Requirement: The STAM intake acts as the DSO connection's account (REQ-DSO-070)

Every OpenRegister write of the STAM intake and of its attachment job MUST run as the Nextcloud account named in the `dso-stam` consumer's `userId`, inside OpenRegister's `ObjectService::runAs()`. The intake MUST check that the account exists, is enabled, and holds `create` and `update` on `dso_verzoek` before its first write, and MUST answer HTTP 503 and notify the administrators when it does not. The intake and the job MUST NOT write with `_rbac: false` and MUST NOT call `runAsSystem()`. The account MUST be set by an administrator and validated when it is saved. A repair step MUST migrate an existing app-config trust configuration into the consumer and MUST leave the account empty.

@e2e exclude backend identity resolution of an anonymous signed push and a cron job: covered by PHPUnit and the live proof in tasks.md, no browser surface

#### Scenario: The verzoek is stored as the configured account
- **GIVEN** the `dso-stam` consumer names account `dso-intake`, which holds `create` and `update` on `dso_verzoek`
- **WHEN** DSO-LV pushes a signed verzoek without a Nextcloud session
- **THEN** the `dso_verzoek` is created and updated as `dso-intake`
- **AND** OpenRegister's audit trail names `dso-intake` for both writes
- **AND** the record's `receivedVia` holds the consumer's uuid and `dso-intake`
- **AND** after the request the active user is what it was before

#### Scenario: A missing account fails loud
- **GIVEN** the `dso-stam` consumer has an empty `userId`, or names a user that does not exist or is disabled
- **WHEN** DSO-LV pushes a signed verzoek
- **THEN** the endpoint answers HTTP 503 with error `dso_account_unavailable`
- **AND** nothing is written to OpenRegister
- **AND** the administrators get one notification naming the problem

#### Scenario: An account without rights writes nothing half
- **GIVEN** the account holds `create` but not `update` on `dso_verzoek`
- **WHEN** DSO-LV pushes a signed verzoek
- **THEN** the endpoint answers HTTP 503 with error `dso_account_lacks_rights`
- **AND** no `dso_verzoek` is created

#### Scenario: The attachment job runs as the account that stored the verzoek
- **GIVEN** a verzoek with bijlagen was stored as `dso-intake`
- **WHEN** cron runs the queued `FetchDsoAttachmentsJob`
- **THEN** the downloads and the outcome save run as `dso-intake`
- **AND** the job reads the DSO source as an engine read, so `dso-intake` needs no admin rights

#### Scenario: The attachment job refuses a vanished account
- **GIVEN** a queued `FetchDsoAttachmentsJob` whose account was deleted
- **WHEN** cron runs it
- **THEN** the job writes nothing, logs an error naming the verzoek and the account, and notifies the administrators
- **AND** every entry stays `pending`

#### Scenario: Existing configuration migrates without an account
- **GIVEN** an instance with the `dso_pki_*` app config keys set and no `dso-stam` consumer
- **WHEN** the repair step runs
- **THEN** one `dso-stam` consumer exists with that trust configuration and an empty `userId`
- **AND** the administrators get one notification asking them to choose the account
- **AND** running the repair step again creates nothing

### Requirement: The DSO connection's account is chosen and checked by an administrator (REQ-DSO-071)

The DSO connection settings in the Integriq admin section MUST let an administrator choose the account the STAM intake acts as. On save they MUST refuse a user that does not exist, a disabled user, and a user without `create` and `update` on `dso_verzoek`, each with a field error. They MUST warn, without refusing, when the user is a member of the `admin` group. The section MUST show which account the intake acts as, or that pushes are refused because no account is set.

#### Scenario: Choosing a valid account
- **GIVEN** an administrator opens the DSO connection settings
- **WHEN** they choose account `dso-intake`, which holds the required rights, and save
- **THEN** the setting is stored and the section reads "Intake acts as dso-intake"
- @e2e tests/e2e/dso-connection-settings.spec.ts

#### Scenario: An account without rights is refused
- **GIVEN** an administrator opens the DSO connection settings
- **WHEN** they choose a user without `create` on `dso_verzoek` and save
- **THEN** the save is refused with a field error naming the missing right
- **AND** the stored account is unchanged
- @e2e tests/e2e/dso-connection-settings.spec.ts

#### Scenario: No account set is shown plainly
- **GIVEN** the `dso-stam` consumer has no account
- **WHEN** an administrator opens the DSO connection settings
- **THEN** the section reads "No account set: DSO-LV pushes are refused with 503"
- @e2e tests/e2e/dso-connection-settings.spec.ts
