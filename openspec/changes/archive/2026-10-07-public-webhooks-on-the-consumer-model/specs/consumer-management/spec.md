## ADDED Requirements

### Requirement: A signed webhook acts as its consumer's account (REQ-CM-020)

Every signed public webhook in `WebhookProfiles` MUST authenticate a delivery against its own consumer (`authorizationType` per webhook), read with an engine read. It MUST verify the signature header named in the consumer's `authorizationConfiguration` over the exact raw body. It MUST run every OpenRegister write as the account in the consumer's `userId`, inside `runAs()`, and restore the previous user afterwards. A bad signature MUST answer 401. A missing connection, a missing, unknown or disabled account, or an account without the profile's rights on its schema MUST answer 503 with `<channel>_<code>`, write nothing and alert the administrators. A write that OpenRegister refuses MUST answer 503 too. An upgrade MUST move a webhook's legacy source secret into its consumer, without an account. Ruben approved this model on 2026-10-04.

#### Scenario: A signed delivery without a session is stored as the consumer's account

- GIVEN a webhook consumer whose account may write the webhook's schema
- WHEN a correctly signed delivery arrives without a Nextcloud session
- THEN it is stored
- AND every write runs as that account
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A connection without a usable account refuses with 503

- GIVEN a webhook consumer without an account
- WHEN a correctly signed delivery arrives
- THEN the answer is 503 `<channel>_account_unavailable`
- AND nothing is written
- AND the administrators get one notification that names the webhook
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A wrong signature is refused with 401

- GIVEN a webhook consumer
- WHEN a delivery arrives signed with another secret
- THEN the answer is 401 and nothing is written
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A refused write is answered 503

- GIVEN a webhook consumer whose account OpenRegister refuses to let write
- WHEN a correctly signed delivery arrives
- THEN the answer is 503 `<channel>_delivery_not_stored`, never 200
- @e2e exclude server-to-server webhook: covered by PHPUnit

#### Scenario: An upgrade moves the source trust into the consumer

- GIVEN an enabled source of a webhook's legacy type with a webhook secret
- WHEN the app is upgraded
- THEN one consumer of that webhook exists with that secret, scheme and header, and no account
- AND a second upgrade creates nothing
- @e2e exclude repair step: covered by PHPUnit and the live upgrade

#### Scenario: One consumer per webhook

- GIVEN a `peppol-webhook` consumer
- WHEN an administrator saves a second `peppol-webhook` consumer
- THEN the save is refused with an error saying only one Peppol connection is allowed
- @e2e exclude backend save guard: covered by PHPUnit

### Requirement: An administrator chooses each webhook's account (REQ-CM-021)

The integriq admin settings MUST list every webhook in `WebhookProfiles`, with whether it is configured, whether a secret is set and its account's state, and MUST never return a secret. An administrator MUST be able to save a webhook's scheme, secret, header and account. A blank secret MUST keep the stored one. An account that does not exist, is disabled or lacks the webhook's rights on its schema MUST be refused with a field error. An administrator account MUST save with a warning.

#### Scenario: The settings list every webhook without its secret

- GIVEN webhook consumers with secrets
- WHEN an administrator opens the webhook connections section
- THEN every webhook is listed with its account state
- AND no secret appears in the response
- @e2e exclude admin settings payload: covered by PHPUnit and the live settings GET

#### Scenario: An administrator chooses a webhook's account

- GIVEN a webhook consumer without an account
- WHEN an administrator chooses an account that may write the webhook's schema and saves
- THEN the consumer names that account and keeps its secret
- @e2e exclude admin settings payload: covered by PHPUnit and the live settings PUT

#### Scenario: An account that cannot write is refused

- GIVEN a webhook whose schema the account may not create in
- WHEN an administrator chooses that account
- THEN the save is refused with a field error naming the missing right
- @e2e exclude admin settings payload: covered by PHPUnit and the live settings PUT
