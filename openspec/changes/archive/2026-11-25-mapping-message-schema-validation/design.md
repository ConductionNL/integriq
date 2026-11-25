# Design: mapping-message-schema-validation

Kind: code. A message schema is an OpenRegister object. One validator service
runs it. Three places call the validator: the endpoint pipeline, the
synchronization fetch path and the synchronization target write. Each place
refuses or records, as the administrator chose.

## Where it fits

- Schema: a fragment `lib/Settings/register.d/mapping-message-schema-validation.json`
  (ADR-037) declares `message_schema` with `name`, `kind` (`json-schema`,
  `xsd`, `openapi`, `register-schema`), `document` (text), `registerSchema`
  (a register and schema slug, for `register-schema`) and `version`. It merges a
  `validation` object onto `endpoint`: `request` and `response`, each a
  `message_schema` uuid with an optional OpenAPI `operationId`, and `mode`
  (`refuse` or `record`, default `record`). Synchronizations keep theirs in
  `sourceConfig.validation` and `targetConfig.validation`, the free objects the
  fetch path and the target write already read.
- Service: a new `lib/Service/MessageValidationService.php` with
  `validate(messageSchema, payload, context): ValidationOutcome`. Three
  checkers behind it:
  - JSON Schema: Opis JSON Schema, which OpenRegister ships and integriq
    already calls at `lib/Controller/MappingsController.php:278`. A
    `register-schema` kind goes through OpenRegister's validate handler the way
    the mapping test does at `:269`, so a register's own rules apply unchanged.
  - XSD: `DOMDocument::schemaValidateSource()` on a document loaded through
    `lib/Util/SafeXmlParser.php:121` (`loadDom()`), with network access off.
  - OpenAPI: the operation is found by `operationId`, or by the request method
    and path; its request or response body schema is checked with the JSON
    Schema checker. OpenAPI 3.0 `nullable` is rewritten to a JSON Schema type
    union before the check.
- Endpoint: `lib/Service/EndpointService.php:436` (`doHandleRequest()`)
  validates the request before any rule or dispatch runs, and validates the
  proxied answer returned by `handleSourceRequest()` at `:2174`.
- Synchronization source: `lib/Service/SynchronizationService.php:7009`
  onwards, after a page is parsed, validates each object before it reaches the
  mapping. A refused object goes to
  `lib/Service/SyncItemDeadLetterService.php:119` (`recordFailure()`), the
  per-item isolation of `synchronization-engine` REQ-008.
- Synchronization target: `lib/Service/SynchronizationService.php:8136`,
  before `callSourceObject()`, validates the body that is about to be sent.
- Pages: a manifest fragment `src/manifest.d/mapping-message-schema-validation.json`
  adds a `MessageSchemas` index page over `message_schema`. The validation
  section goes on `src/modals/v2/EndpointFormFields.vue` and on the
  synchronization settings in `src/views/Synchronization/SyncConfigWidget.vue`.

## D1. A message schema is its own object

The same XSD is used by an endpoint and by the synchronization that feeds it,
and a partner sends a new version of it. Storing the document once and
referencing it by uuid makes an update one edit. The alternative was to paste
the schema into each endpoint. Rejected: two copies drift, and the drift is
exactly the mismatch this change is meant to catch.

## D2. Record first, refuse when ready

Mode `record` checks every message and writes the errors to the log without
refusing anything. An administrator turns it on, reads a week of logs, fixes
the partner or the schema, and then switches to `refuse`. The alternative was
refuse-only, the Frank!Framework and MuleSoft default. Rejected as the only
mode: switching on validation in front of a live partner that has always sent
slightly wrong messages stops the integration on the first message.

## D3. Refusals say why, in the shape of the channel

An inbound request that fails answers 400 with an `application/problem+json`
body listing the first twenty errors with their paths. A proxied answer that
fails answers 502, because the fault is upstream. A synchronization object
that fails is dead-lettered with the errors, and stays replayable after the
schema or the data is fixed. A target body that fails is not sent. Each case
writes the errors to the call log or the synchronization log.

## D4. No new OpenAPI library

An OpenAPI validator library would be a new composer dependency under the
ADR-093 cooldown, and the part integriq needs is small: find an operation and
check a body against a JSON Schema, which Opis already does. The alternative
was to add a PSR-7 OpenAPI validator. Rejected for now; the checker is behind
one interface, so it can be swapped if parameter and header validation is
asked for later.

## Declarative versus imperative

The schema and the mode are declared on objects. Validation is imperative and
runs in the pipeline, because the messages are not OpenRegister objects: an
inbound request body, a partner's source page and an outbound body never pass
through OpenRegister's save, where its own validation lives.

## Seed data

The fragment seeds two message schemas: `example-person-json`, a small JSON
Schema, and `example-person-xsd`, the XSD for the same shape. No seeded
endpoint or synchronization gets a `validation` block, so nothing changes
behaviour on upgrade.

## Risks

- XSD documents with `xs:import` or `xs:include` of a remote location. With
  network access off they fail to load; the message schema form says so and
  asks for the imported schema to be stored as its own message schema, which
  the XSD checker resolves by `schemaLocation` name.
- Validation cost on a high-volume endpoint. Compiled schemas are cached per
  message schema version for the request.
- A partner's answer that is valid but described by a wrong OpenAPI document
  would be refused. `record` mode is the default for that reason.
