# Design: mapping-formats-and-lookups

Kind: code. Formats are handled at the edges: a codec turns CSV into an array
before the mapping runs, and a writer turns the mapping's array into XML or CSV
when integriq sends it. The mapping engine keeps working on arrays. A lookup is
one new Twig function behind an allowlist.

## Where it fits

- Codec: a new `lib/Util/CsvCodec.php` with `decode(string, array $options): array`
  and `encode(array $rows, array $options): string`. It reads through
  `fgetcsv()` on a `php://temp` stream so a quoted field with a line break
  stays one field. `lib/Migration/Source/FileMigrationSource.php:200`
  (`parse()`) and `:234` (`headers()`) call it instead of splitting lines.
- XML writer: the body of `lib/Http/XMLResponse.php:157` (`arrayToXml()`)
  moves to a new `lib/Util/ArrayToXml.php`; `XMLResponse` calls it, so the
  thirteen scenarios of `openspec/specs/xml-response/spec.md` keep guarding the
  same code.
- Fetch path: `lib/Service/SynchronizationService.php:7009` gains a `csv`
  branch beside the `jsonl` branch, reading `sourceConfig.format`,
  `sourceConfig.csvDelimiter`, `sourceConfig.csvEnclosure` and
  `sourceConfig.csvHeader`.
- Settings screen: `src/views/Synchronization/SyncConfigWidget.vue:192` turns
  the free-text "File format" field into a select of `json`, `jsonl`, `xml`
  and `csv`, with the three CSV options shown for `csv`.
- Mapping schema: a fragment `lib/Settings/register.d/mapping-formats-and-lookups.json`
  merges `outputFormat` (enum `json`, `xml`, `csv`, default `json`) and
  `outputOptions` (`rootElement`, `csvDelimiter`, `csvHeader`) onto the
  `mapping` schema at `lib/Settings/integriq_register.json:1414`.
- Rendering: a new `MappingService::renderOutput(mapping, array): array`
  returns `{contentType, body}`. `lib/Service/SynchronizationService.php:8135`
  (the JSON-only TODO) and `:8183` (the update mapping) use it: a JSON mapping
  keeps `targetConfig['json']`, an XML or CSV mapping sets `targetConfig['body']`
  and the `Content-Type` header.
- Test and preview: `lib/Controller/MappingsController.php:145` (`test()`)
  adds `renderedOutput` to its answer; the preview pane of
  `src/views/wrappers/MappingDetailPage.vue` shows it read-only when the output
  format is not JSON.
- Lookup: `lib/Twig/MappingExtension.php:79` registers `lookup` for
  integriq's own engine, and `lib/Listener/MappingFunctionRegistrationListener.php:68`
  contributes it to OpenRegister's engine next to the three functions it
  already contributes, so a mapping behaves the same in both;
  `lib/Twig/MappingRuntime.php:73` gains OpenRegister's object service and a
  lookup allowlist. The allowlist is administered like the expression-source
  allowlist: `lib/Expression/EnvironmentAllowlist.php` for storage and audit,
  and routes beside `expressionSource#index` at `appinfo/routes.php:659`
  (`mappingLookup#index`, `mappingLookup#add`, `mappingLookup#remove` under
  `/api/admin/mapping-lookups`), admin only.

## D1. Formats at the edges, arrays in the middle

Every mapping rule, cast and unset in integriq works on arrays, and
`executeMapping()` returns one (`lib/Service/MappingService.php:277`). Parsing
CSV before and writing XML or CSV after keeps that contract, so no rule
changes meaning. The alternative was an XML-aware mapping language, the
XSLT shape Frank!Framework uses. Rejected: it is a second mapping language to
learn and to secure, for output a writer can produce from the array.

## D2. One CSV codec, and it keeps quoted line breaks

`FileMigrationSource::parse()` splits on `\R` first, which breaks a quoted
address field that holds a newline. A second copy of that code in the fetch
path would copy the bug. The codec reads through `fgetcsv()` and both callers
use it. OpenRegister's `ImportService` and `ExportService` handle CSV for a
whole register through PhpSpreadsheet and are bound to a schema; they cannot
take a mapping payload, so ADR-011 reuse does not apply and the codec says so
in an `@see`.

## D3. XML output reuses the endpoint serializer

`XMLResponse::arrayToXml()` already writes `@root`, `@attributes` and `#text`
and is specified by `xml-response`. Extracting it gives mapping output the
same conventions an endpoint response has, so an engineer who wrote one knows
the other. The alternative was a new writer with a different attribute
convention. Rejected: two conventions for the same array shape in one app.

## D4. A lookup reads only what an administrator allowed

`lookup('codelijsten/gemeenten', {'code': source.gemeente}, 'naam', '')` returns a
field of the single object matching the filters in an allowed schema. Mapping
templates run in a system context during a synchronization, so a lookup that
could read any schema would let anyone who can edit a mapping read any
register. The allowlist is kept by an administrator on the admin page and
every change is recorded, the pattern `allowlisted-expression-sources` set.
More than one match returns the default and records a warning on the trace
step; picking the first match silently would make a code translation depend on
insertion order. Results are cached per mapping run, so a thousand items with
the same code make one query.

The alternative was to use `callSource` with a host allowlist. Rejected: the
row asks for a register lookup, and an outbound call from a template is the
SSRF path the note at `lib/Twig/MappingExtension.php:66` closed in integriq's
engine. That the listener still contributes `callSource` to OpenRegister's
engine is recorded in the proposal and left to its own change.

## Declarative versus imperative

The formats are declared on the synchronization and the mapping. Parsing and
writing are imperative and live in the codec and the writer. A lookup is an
imperative read at mapping time; OpenRegister has no declarative relation that
resolves a code from an arbitrary field during another app's transform.

## Seed data

The fragment seeds one mapping, `person-to-xml`, with `outputFormat: xml` and
`rootElement: persoon`, next to the three seeded mappings in
`lib/Settings/integriq_seed_data.json:268`. The seeded mappings keep no
`outputFormat` and so stay JSON. The lookup allowlist starts empty.

## Risks

- A CSV file from a partner in a different encoding. The codec accepts an
  `encoding` option and converts to UTF-8 before parsing; the default is UTF-8.
- A large CSV response is held in memory, as JSON responses are today. The
  JSONL branch already streams line by line; CSV follows the same guard on the
  whole-body ceiling.
- A target that expects SOAP needs an envelope around the XML. The root
  element option covers a plain XML API; a SOAP envelope stays with the StUF
  and Digikoppeling adapters.
