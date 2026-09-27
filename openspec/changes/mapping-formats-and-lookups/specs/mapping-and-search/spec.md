# mapping-and-search Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- mapping-formats-and-lookups

## Purpose

An integration engineer reads CSV from an ordinary HTTP source, writes a
mapping's output as XML or CSV, and translates a code by looking it up in a
register an administrator approved. Matrix rows `integriq:map-csv`,
`integriq:map-lookup` and `integriq:map-xml`.

## ADDED Requirements

### Requirement: A synchronization reads a CSV source response (REQ-MFL-001)

A synchronization whose `sourceConfig.format` is `csv` MUST parse the source
response as CSV with the configured delimiter, enclosure and header flag, and
MUST hand one array per row to the mapping. A quoted field containing a line
break MUST stay one field. The synchronization settings MUST offer the format
as a select of `json`, `jsonl`, `xml` and `csv`.

#### Scenario: a municipal code list served as CSV
- GIVEN a source answering `code;naam` with two rows, and a synchronization with format `csv` and delimiter `;`
- WHEN an integration engineer runs the synchronization test
- THEN two objects reach the mapping with keys `code` and `naam`
- e2e: tests/e2e/mapping-formats-and-lookups.spec.ts

#### Scenario: a quoted line break survives
- GIVEN a CSV row whose address field is quoted and holds a line break
- WHEN it is decoded
- THEN the row has one address value containing the line break
- @e2e exclude a parser rule; covered by PHPUnit on CsvCodec

### Requirement: A mapping declares its output format (REQ-MFL-002)

A mapping MUST accept `outputFormat` of `json`, `xml` or `csv`, defaulting to
`json`, with `outputOptions` for the XML root element and the CSV delimiter and
header. XML output MUST follow the `@root`, `@attributes` and `#text`
conventions of `xml-response`. The mapping test MUST return the rendered text
next to the array, and the mapping detail page MUST show it.

#### Scenario: an engineer previews XML output
- GIVEN the mapping `person-to-xml` with root element `persoon`
- WHEN an integration engineer enters a sample input on the mapping detail page
- THEN the preview shows an XML document with root `persoon` next to the mapped array
- e2e: tests/e2e/mapping-formats-and-lookups.spec.ts

#### Scenario: CSV output with a header row
- GIVEN a mapping with `outputFormat: csv`, delimiter `;` and header on
- WHEN it is rendered for two rows
- THEN the text has a header line and two data lines separated by `;`
- @e2e exclude a writer rule; covered by PHPUnit on MappingService::renderOutput

### Requirement: A synchronization sends the mapping's output format to an API target (REQ-MFL-003)

When a synchronization writes to an API target and the target mapping declares
`xml` or `csv`, integriq MUST send the rendered text as the request body with
`application/xml` or `text/csv` as its content type, and MUST NOT send JSON.
A mapping without `outputFormat` MUST be sent as JSON, as before.

#### Scenario: an XML API receives XML
- GIVEN a synchronization to an API target whose mapping declares `xml`
- WHEN an object is written
- THEN the call log shows a request with `Content-Type: application/xml` and an XML body
- @e2e exclude an outbound call; covered by PHPUnit on SynchronizationService with a mocked CallService

### Requirement: A mapping can look up a value in an allowed register schema (REQ-MFL-004)

A mapping template MUST be able to call `lookup(schema, filters, field, default)`.
It MUST return `field` of the single object in `schema` matching `filters`, and
`default` when none match. When more than one object matches it MUST return
`default` and record a warning on the execution trace. It MUST refuse any
schema not on the lookup allowlist, and MUST NOT make an outbound HTTP call.
Results MUST be cached for the duration of one mapping run.

#### Scenario: a gemeentecode becomes a name
- GIVEN `codelijsten/gemeenten` on the lookup allowlist holding an object with `code` `0363` and `naam` `Amsterdam`
- WHEN a mapping rule `{{ lookup('codelijsten/gemeenten', {'code': gemeente}, 'naam', '') }}` runs on input `gemeente: 0363`
- THEN the output value is `Amsterdam`
- e2e: tests/e2e/mapping-formats-and-lookups.spec.ts

#### Scenario: a schema off the allowlist is refused
- GIVEN a schema that is not on the lookup allowlist
- WHEN a mapping calls `lookup` on it
- THEN the mapping fails with a message naming the schema and no object is read
- @e2e exclude a refusal; covered by PHPUnit on MappingRuntime

#### Scenario: two matches do not pick one
- GIVEN two objects in an allowed schema matching the filters
- WHEN `lookup` runs
- THEN it returns the default and the trace step carries a warning naming the filters
- @e2e exclude a trace warning; covered by PHPUnit on MappingRuntime

### Requirement: The lookup allowlist is administered and every change is recorded (REQ-MFL-005)

Only an administrator MUST be able to add or remove a schema on the lookup
allowlist, through `/api/admin/mapping-lookups`, and every change MUST be
recorded with the administrator and the time.

#### Scenario: an administrator allows a code list
- GIVEN an administrator on the integriq admin page
- WHEN they add `codelijsten/gemeenten` to the lookup allowlist
- THEN it is listed and the change is recorded with their user id
- e2e: tests/e2e/mapping-formats-and-lookups.spec.ts

#### Scenario: a non-administrator cannot change the allowlist
- GIVEN a signed-in user who is not an administrator
- WHEN they post to `/api/admin/mapping-lookups`
- THEN the response is 403 and the allowlist is unchanged
- @e2e exclude an authorization refusal; covered by PHPUnit on the controller
