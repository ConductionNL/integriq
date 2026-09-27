---
kind: code
depends_on: []
---

# Proposal: mapping-formats-and-lookups

## Summary

A mapping in integriq reads JSON, falls back to XML when a source answers in
XML, and always writes JSON. It cannot read CSV from an ordinary HTTP source,
cannot write XML or CSV, and cannot translate a code by looking it up in a
register. This change adds CSV as a source format, XML and CSV as mapping
output formats, and a `lookup()` function inside a mapping that reads an
administrator-approved register schema.

## Why

Three matrix rows, all rated by the matrix with no demand row.

`integriq:map-csv`, "Read or write CSV while mapping." Rated `partial`,
`built.state` `built`. The matrix note: "The placeholder text in
SyncConfigWidget.vue promises csv as a source response format, but the parser
backing it does not implement that branch". Competitors rated `yes`:

- n8n (`n8n`), source read at n8n@2.40.7:
  "packages/nodes-base/nodes/Files/ExtractFromFile/ExtractFromFile.node.ts:38
  reads CSV into items and
  packages/nodes-base/nodes/Files/ConvertToFile/ConvertToFile.node.ts:38 writes
  items back to CSV". No evidence URL is recorded for this cell.
- MuleSoft Anypoint (`mulesoft`), evidence
  https://docs.mulesoft.com/dataweave/latest/dataweave-formats-csv.md: "The
  DataWeave reader for CSV input supports the following parsing strategies"
  and a CSV writer with header and separator properties.
- Frank!Framework (`frank`), source read at v10.2.0:
  "core/src/main/java/org/frankframework/pipes/CsvParserPipe.java:49 reads CSV
  into XML for mapping". No evidence URL is recorded for this cell.

`integriq:map-lookup`, "Look up a value in a register while mapping, such as
translating a code." Rated `no`, `built.state` `none`. Competitors rated
`yes`:

- n8n (`n8n`), source read at n8n@2.40.7:
  "packages/nodes-base/nodes/DataTable/actions/row/Row.resource.ts:6 'get' and
  :4 rowExists operations look up a row in an n8n Data Table". No evidence URL
  is recorded for this cell.
- MuleSoft Anypoint (`mulesoft`), evidence
  https://docs.mulesoft.com/dataweave/latest/dw-mule-functions-lookup.md:
  lookup(flowName, payload) "enables you to execute a flow within a Mule app
  and retrieve the resulting payload" from inside a mapping, and
  https://docs.mulesoft.com/dataweave/latest/dataweave-cookbook-csv-lookup.md
  looks values up in a CSV file.

`integriq:map-xml`, "Turn XML into JSON and back while mapping." Rated
`partial`, `built.state` `built`. The matrix note: "Inbound XML-to-array is
automatic and generic; outbound array-to-XML only exists for the specific StUF
message shapes, not as a mapping-editor feature." Six competitors rate `yes`;
with an evidence URL, MuleSoft Anypoint (`mulesoft`),
https://docs.mulesoft.com/dataweave/latest/dataweave-formats-xml.md: "the
DataWeave XML reader and writer parse and produce application/xml". The
others are source reads with no evidence URL: n8n
(`packages/nodes-base/nodes/Xml/Xml.node.ts:33` "jsonToxml" and `:38`
"xmlToJson"), Tyk (`apidef/api_definitions.go:1812` xmlMarshal), Apache APISIX
(`apisix/plugins/body-transformer.lua:124` and `:82`), WSO2 API Manager (the
`jsonToXML_v1.json` and `xmlToJson_v1.j2` operation policies) and
Frank!Framework (`core/src/main/java/org/frankframework/pipes/JsonPipe.java:166`).

This change covers three rows: `integriq:map-csv`, `integriq:map-lookup` and
`integriq:map-xml`.

## What integriq already has

- CSV is read only as a migration file:
  `lib/Migration/Source/FileMigrationSource.php:200` (`parse()`) splits on line
  breaks and calls `str_getcsv()` per line at `:207` and `:214`, so a quoted
  field that holds a line break is split in two.
- The synchronization fetch path handles `sourceConfig.format: jsonl` at
  `lib/Service/SynchronizationService.php:7009`, `markdown` and `html` from the
  source at `:7027` to `:7037`, then tries JSON and falls back to XML through
  `SafeXmlParser` at `:7075`. There is no CSV branch, while
  `src/views/Synchronization/SyncConfigWidget.vue:194` offers
  `json | xml | csv` as the placeholder of a free-text field.
- A synchronization writes to an API target as JSON only:
  `lib/Service/SynchronizationService.php:8135` reads "@TODO For now only JSON
  APIs are supported".
- An array to XML serializer exists for endpoint responses:
  `lib/Http/XMLResponse.php:157` (`arrayToXml()`), with `@root`, `@attributes`
  and `#text` handling specified in `openspec/specs/xml-response/spec.md`.
- Mapping Twig functions are listed at `lib/Twig/MappingExtension.php:79`:
  `generateUuid`, `executeMapping`, `getFileContents`, `getFiles`,
  `getTargetIdByOriginId`, `getOriginIdByTargetId`. None reads a register.
  The note at `:66` explains why `callSource` was removed: an outbound call
  from a template is an SSRF path.

## What this change builds

1. A CSV codec shared by the migration source and the synchronization fetch
   path, which keeps quoted line breaks and takes a delimiter, an enclosure and
   a header flag.
2. `sourceConfig.format: csv` on a synchronization, and a format select in the
   synchronization settings in place of the free-text field.
3. `outputFormat` (`json`, `xml` or `csv`) and `outputOptions` on a mapping.
   The mapping still produces an array; the output format decides how it is
   written when integriq sends it.
4. A synchronization whose target mapping declares XML or CSV sends that body
   with the matching content type instead of JSON.
5. The mapping test and the mapping detail preview show the rendered XML or
   CSV text next to the array.
6. `lookup(schema, filters, field, default)` inside a mapping, reading only
   schemas on an administrator-kept lookup allowlist, cached per run.

## Out of scope

- XSD-driven typed XML output, the `Json2XmlValidator` shape. Validation
  against a declared XSD is `mapping-message-schema-validation`.
- Lookups against an external API from inside a mapping. `callSource` stays
  removed.
- Spreadsheet formats such as XLSX.
