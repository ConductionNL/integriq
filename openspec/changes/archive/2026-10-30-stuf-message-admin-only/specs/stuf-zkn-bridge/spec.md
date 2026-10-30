# stuf-zkn-bridge Specification (delta: stuf_message is admin-only)

**Status:** proposed
**Scope:** integriq

@e2e exclude server-to-server webhook and register authorization: covered by PHPUnit.

## MODIFIED Requirements

### Requirement: The inbound endpoint acts as the StUF-ZKN connection's account (REQ-020)

`POST /api/stuf-zkn/inbound` MUST authenticate a delivery against the `stuf-zkn-webhook` consumer, as REQ-CM-020 of `consumer-management` describes, and MUST run the zaak and document writes as that consumer's account. The `stuf_message` audit rows are integriq's own records: the schema is admin-only on every verb, they are written and read with `_rbac: false`, and the account therefore needs no right on `stuf_message`. A body over 2 MiB (`StufZknRequestBody::MAX_BYTES`) MUST answer 413 before the signature or the XML is read, and write nothing. A bad signature MUST answer 401. A missing connection or account MUST answer 503 and write nothing. A zaak or document write that OpenRegister refuses MUST be recorded as a `failed` `stuf_message` row and answered with a Fo03 (the HTTP status of that answer is 200, as `receiveInbound()` shapes every internal failure into a Fo03). Ruben approved this model on 2026-10-04.

#### Scenario: A signed delivery without a session is stored as the connection's account

- GIVEN a `stuf-zkn-webhook` consumer whose account may write the target zaak schema
- WHEN the StUF-ZKN sender posts a correctly signed delivery without a session
- THEN it is stored
- AND the zaak or document write runs as that account
- AND the `stuf_message` row is written by the engine, not as that account
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A write the account may not make is answered with a Fo03

- GIVEN a `stuf-zkn-webhook` consumer whose account may not write the target zaak schema
- WHEN a correctly signed delivery arrives
- THEN the answer is a Fo03
- AND a `failed` `stuf_message` row records it
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A connection without a usable account answers 503

- GIVEN a `stuf-zkn-webhook` consumer without an account
- WHEN a correctly signed delivery arrives
- THEN the answer is 503 and nothing is written
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A body over the ceiling answers 413

- GIVEN a `stuf-zkn-webhook` consumer
- WHEN a delivery of more than 2 MiB arrives
- THEN the answer is 413 before the signature is checked, and nothing is written
- @e2e exclude server-to-server webhook: covered by XmlWebhooksConsumerTest::testAnOversizedStufZknKennisgevingAnswers413

#### Scenario: A wrong signature answers 401

- GIVEN a `stuf-zkn-webhook` consumer
- WHEN a delivery arrives signed with another secret
- THEN the answer is 401 and nothing is written
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof
