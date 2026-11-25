---
kind: code
depends_on: []
---

# Proposal: mapping-message-schema-validation

## Summary

Integriq validates a write to a register against the register's JSON Schema,
and the mapping test panel can check a result against a schema. A proxied
request, a source page, and a message integriq sends to a partner are passed
through unchecked, and there is no XSD or OpenAPI validation at all. This
change lets an administrator declare the schema a message must match (JSON
Schema, XSD or an OpenAPI operation) on an endpoint, a synchronization source
and a synchronization target, and choose whether a mismatch is refused or only
recorded.

## Why

Matrix row `integriq:map-message-validation`, "Check every message against its
declared schema, such as XSD, JSON Schema or OpenAPI, and refuse it when it
does not match." The matrix rates integriq `partial` with `built.state`
`built`.

The row's origin is a changelog,
https://github.com/frankframework/frankframework/pull/10459. The matrix note:
"Frank!Framework 10.1.0 (2026-04-25) added an OpenAPI validator next to its XML
and JSON validators." A changelog counts as the competitor it names, so this is
the Frank!Framework signal below and not a separate demand row.

Competitors rated `yes`:

- MuleSoft Anypoint (`mulesoft`), evidence
  https://docs.mulesoft.com/xml-module/latest/index.md, "Validate documents
  against an XSD schema";
  https://docs.mulesoft.com/json-module/latest/json-schema-validation.md
  validates against a JSON Schema; and
  https://docs.mulesoft.com/gateway/latest/policies-included-schema-validation.md,
  "Validates incoming traffic against a supplied API schema" (OpenAPI),
  answering 400 on a mismatch.
- WSO2 API Manager (`wso2`), source read at v4.7.0: "Schema Validation" per
  API runs
  "carbon-apimgt/components/apimgt/org.wso2.carbon.apimgt.gateway/src/main/java/org/wso2/carbon/apimgt/gateway/handlers/security/SchemaValidator.java:37
  against the OpenAPI definition", with `jsonValidator_v1.j2` (JSON Schema)
  and `xmlValidator_v1.j2` (XSD) policies. No evidence URL is recorded for this
  cell.
- Frank!Framework (`frank`), source read at v10.2.0:
  "core/src/main/java/org/frankframework/pipes/XmlValidator.java:82 and
  :554-568 validate against an XSD,
  core/src/main/java/org/frankframework/pipes/JsonValidator.java validates JSON
  Schema, core/src/main/java/org/frankframework/pipes/OpenApiValidator.java:54
  and :154 validate against an OpenAPI definition". No evidence URL is
  recorded for this cell.

This change covers one row: `integriq:map-message-validation`.

## What integriq already has

- A register-backed endpoint turns OpenRegister's `ValidationException` into a
  refusal: `lib/Service/EndpointService.php:2033` to `:2039`.
- The mapping test validates a result against a register schema through
  OpenRegister's validate handler: `lib/Controller/MappingsController.php:181`
  to `:192` resolve the schema and `:269` calls `validateObject()`; errors are
  formatted with Opis JSON Schema at `:278`.
- A proxied endpoint passes the request through unvalidated:
  `lib/Service/EndpointService.php:2174` (`handleSourceRequest()`).
- A synchronization parses a source page at
  `lib/Service/SynchronizationService.php:7009` to `:7075` and sends a target
  write at `:8136` without a schema check.
- XML is parsed safely with a pinned entity loader and no network:
  `lib/Util/SafeXmlParser.php:82` (`parse()`) and `:121` (`loadDom()`).
- A failed synchronization item can be dead-lettered through
  `lib/Service/SyncItemDeadLetterService.php:119` (`recordFailure()`).

## What this change builds

1. A `message_schema` object holding a JSON Schema, an XSD or an OpenAPI
   description, or pointing at a register schema.
2. One validator service with three checks: JSON Schema, XSD and an OpenAPI
   operation's request or response body.
3. A `validation` setting on an endpoint (request and response), on a
   synchronization source (each object before mapping) and on a synchronization
   target (the rendered output before sending), each with mode `refuse` or
   `record`.
4. Refusals that say why: a 400 problem answer on an inbound request, a 502 on
   a proxied answer that breaks its schema, a dead-lettered item on a
   synchronization, and in every case the errors on the call log or the
   synchronization log.
5. A message schemas page and a validation section on the endpoint and
   synchronization forms.

## Out of scope

- Validating event deliveries and event payloads. `events-cloudevents` keeps
  its own CloudEvent envelope checks.
- Generating a JSON Schema or XSD from sample messages.
- Schematron and other rule languages beyond structure.
