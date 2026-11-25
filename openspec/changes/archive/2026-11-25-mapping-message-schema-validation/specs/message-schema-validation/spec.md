# message-schema-validation Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- mapping-message-schema-validation

## Purpose

An administrator declares the schema a message must match, as JSON Schema, XSD
or an OpenAPI operation, on an endpoint, a synchronization source and a
synchronization target, and integriq refuses or records every message that
does not match. Matrix row `integriq:map-message-validation`.

## ADDED Requirements

### Requirement: A message schema is stored once and referenced (REQ-MSV-001)

Integriq MUST persist a message schema as an OpenRegister object of schema
`message_schema` with a `kind` of `json-schema`, `xsd`, `openapi` or
`register-schema`, and a document or a register schema reference. The message
schemas page MUST let an administrator create, edit and delete one, and MUST
refuse to save a document that does not parse for its kind.

#### Scenario: an administrator stores a partner's XSD
- GIVEN an administrator on the message schemas page
- WHEN they create a message schema of kind `xsd` with a partner's XSD and save
- THEN it is listed with its kind and version
- e2e: tests/e2e/message-schema-validation.spec.ts

#### Scenario: a broken document is refused
- GIVEN an administrator entering an XSD that is not well-formed XML
- WHEN they save
- THEN the save is refused with the parser's message and nothing is stored
- e2e: tests/e2e/message-schema-validation.spec.ts

### Requirement: An endpoint validates its request and its proxied answer (REQ-MSV-002)

When an endpoint declares `validation.request`, integriq MUST validate the
request body before any rule or dispatch runs. When it declares
`validation.response`, integriq MUST validate the answer of a proxied source.
In mode `refuse`, a failing request MUST be answered 400 with an
`application/problem+json` body listing the errors with their paths, and a
failing answer MUST be answered 502. In mode `record`, the message MUST pass
and the errors MUST be written to the call log.

#### Scenario: a request missing a required field is refused
- GIVEN an endpoint in mode `refuse` whose request schema requires `bsn`
- WHEN a consumer posts a body without `bsn`
- THEN the answer is 400, the problem body names the path `/bsn`, and the target is not called
- @e2e exclude an inbound API call; covered by Newman in `tests/postman/` and PHPUnit on EndpointService

#### Scenario: record mode lets it through and writes the error
- GIVEN the same endpoint in mode `record`
- WHEN a consumer posts a body without `bsn`
- THEN the request is dispatched and the call log carries the validation error
- @e2e exclude an inbound API call; covered by PHPUnit on EndpointService

#### Scenario: an OpenAPI operation checks the body
- GIVEN an endpoint whose request schema is an OpenAPI description with operation `createZaak`
- WHEN a consumer posts a body whose `startdatum` is not a date
- THEN in mode `refuse` the answer is 400 naming `/startdatum`
- @e2e exclude an inbound API call; covered by PHPUnit on MessageValidationService

### Requirement: A synchronization validates source objects and target bodies (REQ-MSV-003)

A synchronization with `sourceConfig.validation` MUST validate each source
object before it is mapped. A synchronization with `targetConfig.validation`
MUST validate the body before it is sent. In mode `refuse` a failing source
object MUST be dead-lettered with its errors and MUST NOT be mapped, and a
failing target body MUST NOT be sent and MUST be dead-lettered. In mode
`record` the errors MUST be written to the synchronization log and processing
MUST continue.

#### Scenario: one bad object does not stop the run
- GIVEN a synchronization in mode `refuse` whose source page has ten objects, one missing a required field
- WHEN it runs
- THEN nine objects are written, one is in the dead-letter list with the validation error, and the log says so
- e2e: tests/e2e/message-schema-validation.spec.ts

#### Scenario: an invalid outbound body is not sent
- GIVEN a synchronization in mode `refuse` whose target schema is an XSD
- WHEN the rendered body does not match it
- THEN no call is made to the target and the item is dead-lettered
- @e2e exclude an absence of an outbound call; covered by PHPUnit with a mocked CallService

### Requirement: XML is validated without network access (REQ-MSV-004)

The XSD checker MUST load both the message and the schema with external entity
loading and network access disabled. A schema that imports a remote location
MUST fail to load with a message that names the location.

#### Scenario: a remote import is not fetched
- GIVEN an XSD with `xs:import schemaLocation="https://example.org/other.xsd"`
- WHEN it is used to validate a message
- THEN no request is made to example.org and the validation reports the unresolved import
- @e2e exclude a network absence claim; covered by PHPUnit on the XSD checker
