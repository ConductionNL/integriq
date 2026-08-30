# synchronization-engine — Delta: markdown-list + HTML/CSS-selector source extraction

## Purpose

Extends `SynchronizationService::fetchSinglePageData()` (REQ-002's source
fetching, alongside REQ-006's gzip/JSONL handling) so a source with no
JSON/XML API at all — a markdown "awesome list" README, or a plain HTML
page whose data sits in a table/card layout — can be parsed into records
instead of yielding zero objects forever. Composes with the existing
`resultsPosition` extraction; does not change pagination, mapping, gzip
detection, JSONL parsing, or any JSON/XML source's behaviour.

@e2e exclude backend source-fetching internals — covered by PHPUnit, not browser UI

## ADDED Requirements

### Requirement: Markdown and HTML source extraction (REQ-007)

`SynchronizationService::fetchSinglePageData()` MUST detect
`Source.configuration.format === "markdown"` (case-insensitive) and, when
present, parse the response body as a markdown bullet list: each line
matching `- [Name](url) - description \`Tag1\` \`Tag2\`` (a leading
`-`/`*`/`+` list marker, a `[name](url)` link, and an optional trailing
free-text description followed by zero or more backtick-wrapped tags)
becomes one record with `name`, `url`, `description`, and a positional
`tags` array; a line that does not match this shape (a heading, blank line,
prose, or a link-less list item) MUST be skipped without aborting the page.

`fetchSinglePageData()` MUST also detect `Source.configuration.format ===
"html"` (case-insensitive) and, when present, extract records via CSS
selectors: `Source.configuration.htmlSelector` identifies the repeating
record container (each match becomes one record), and
`Source.configuration.htmlFields` (a `fieldName => selector` map) extracts
one value per field relative to that container — a selector suffixed with
`@attributeName` (e.g. `a@href`) MUST extract that DOM attribute instead of
the (default) trimmed text content. A `htmlSelector` matching nothing MUST
yield zero records; a `htmlFields` sub-selector matching nothing within a
container MUST yield a `null` value for that field, without aborting the
container or the page.

Both branches MUST feed their resulting records array through the existing
`getAllObjectsFromArray()` / `resultsPosition` extraction unchanged, and
both MUST be evaluated using `Source.configuration.format` (a per-source
property) — NOT `Synchronization.sourceConfig.format` (the existing,
distinct per-synchronization key REQ-006 uses for `"jsonl"`). A source
presenting neither `configuration.format` value MUST take the exact
pre-existing JSON-then-XML-fallback (and REQ-006 gzip/JSONL) code path,
unchanged.

#### Scenario: a markdown "awesome list" source is parsed into one record per list item

- **GIVEN** a source with `configuration.format: "markdown"`
- **AND** a response body containing markdown list items of the form
  `- [Name](url) - description \`Tag1\` \`Tag2\``, interspersed with
  headings, blank lines, and a link-less bullet
- **WHEN** `fetchSinglePageData()` runs
- **THEN** each matching list item becomes one record with the correct
  `name`, `url`, `description`, and `tags` (an array of 0 or more entries)
- **AND** the non-matching lines produce no records and do not raise an
  error

#### Scenario: an HTML source extracts records via CSS selectors, including attribute values

- **GIVEN** a source with `configuration.format: "html"`, a
  `configuration.htmlSelector` matching a repeating row/card element, and
  `configuration.htmlFields` mapping field names to selectors — at least one
  of which uses the `selector@attr` syntax
- **WHEN** `fetchSinglePageData()` runs
- **THEN** one record is returned per matched container
- **AND** each field is populated with either the sub-selected node's
  trimmed text content, or — for a `selector@attr` field — the named
  attribute's value

#### Scenario: a markdown list item pointing at an in-document anchor or relative URL is skipped

- **GIVEN** a source with `configuration.format: "markdown"`
- **AND** a response body containing a `- [Name](url)` list item whose `url`
  is an in-document anchor (e.g. `#software`) or otherwise carries no URI
  scheme (a relative link) — the shape of a table-of-contents entry or a
  "back to top" navigation link, as opposed to a data record
- **WHEN** `fetchSinglePageData()` runs
- **THEN** that list item produces no record
- **AND** a sibling list item whose `url` is an absolute URI (carries a
  scheme, e.g. `https://...`) still produces its record unaffected
- **AND** no error is raised

#### Scenario: a source with neither new `format` value is unaffected

- **GIVEN** a source with `configuration.format` absent, or set to
  anything other than `"markdown"`/`"html"`
- **WHEN** `fetchSinglePageData()` runs
- **THEN** the result is byte-identical to the pre-existing behaviour
  (REQ-002's JSON/XML parsing, and REQ-006's gzip/JSONL handling where
  applicable)
- @e2e exclude backend regression — covered by PHPUnit

**Notes:**

- `tags` is intentionally positional and unlabelled — this requirement does
  not assign semantic meaning to tag position (e.g. "position 0 is always a
  license"). A source's downstream mapping/rules configuration is
  responsible for naming positional tags, not the fetch-engine parser.
- The markdown line pattern is a single built-in regex tuned to the
  awesome-selfhosted README shape; it is not source-configurable in this
  requirement (no second markdown-shaped source was in scope to validate a
  configurable pattern against).
- Methods added: `parseMarkdownResponse()`, `parseHtmlResponse()`,
  `extractHtmlField()` (all private, alongside the existing
  `fetchSinglePageData()`). Uses `Symfony\Component\DomCrawler\Crawler`
  (`symfony/dom-crawler` + `symfony/css-selector`, pinned `^7.2` for PHP 8.3
  compatibility).
