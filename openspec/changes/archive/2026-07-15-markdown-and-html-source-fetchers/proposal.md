---
kind: code
depends_on: []
---

# openconnector — markdown-list + HTML/CSS-selector source extraction (oc#107)

## Why

Authoring spectr's W2-catalog connectors surfaced a consolidated gap
(oc#107): ~11 keyless sources cannot be OpenConnector-native today because
the fetch/parse engine only ever attempts JSON, XML, and (as of oc#105)
gzip/JSONL against a fetched response body. Two of the five gap clusters in
that issue are the highest-leverage — each unblocks multiple drafted
sources, not just one:

1. **Markdown-list parsing** — `awesome_selfhosted` is a GitHub README whose
   entire dataset is a markdown bullet list (`- [Name](url) - description
   \`License\` \`Language\``). There is no JSON/XML representation at all.
2. **HTML/CSS-selector extraction** — `openalternative`, `don_oss_register`,
   and `wikipedia_comparisons` are plain web pages (no API, or a dead/absent
   one) whose data sits in an HTML `<table>` or a repeating list of card
   elements.

`saashub`, `sourceforge`, and `fedramp` (also named in oc#107) are NOT
addressed by this change — `saashub`/`sourceforge` need the same HTML
extractor plus per-item detail-page follow (N+1 fetch, gap cluster 5,
deferred) and `fedramp` is a client-side-rendered SPA with no discoverable
JSON (gap cluster 3, needs headless rendering, deferred). oc#107 itself
recommends this exact scope: "one design pass on 1-2 (HTML extractor +
markdown) as the highest-leverage; 3/4/5 are lower value."

## What Changes

- **`Source.configuration.format: "markdown"`** — `fetchSinglePageData()`
  parses the response body as a markdown bullet list: each `- [Name](url) -
  description \`Tag1\` \`Tag2\`` line becomes one record with `name`, `url`,
  `description`, and a positional `tags` array (variable count, 0 or more).
  Non-matching lines (headings, prose, blank lines, link-less list items)
  are silently skipped, not thrown.
- **`Source.configuration.format: "html"`** — paired with
  `Source.configuration.htmlSelector` (a CSS selector for the repeating
  record container, e.g. `table tbody tr`) and `Source.configuration.
  htmlFields` (a `fieldName => selector` map, supporting a `selector@attr`
  suffix to read an attribute — e.g. `a@href` — instead of trimmed text
  content). Each matched container becomes one record.
- Both are dispatched from the exact same point in `fetchSinglePageData()`
  that the oc#105 gzip/JSONL detection already occupies — before the
  existing `json_decode`-then-`simplexml_load_string` attempts, which are
  otherwise completely unchanged. Unlike the per-synchronization
  `sourceConfig.format: "jsonl"` key, markdown/html detection reads
  `Source.configuration.format` (a per-SOURCE property — the endpoint
  always returns this shape, regardless of which synchronization reads it).
- New dependency: `symfony/dom-crawler` + `symfony/css-selector` (pinned to
  `^7.2`, PHP 8.1+ compatible — the `^8.x` line requires PHP 8.4 and would
  have violated this app's `"php": "^8.3"` floor). MIT-licensed, standard,
  well-maintained — the conventional PHP CSS-selector-to-DOM library.

## Impact

- Affected specs: `synchronization-engine` (REQ-007, additive — existing
  REQ-002/REQ-006 prose and scenarios are unchanged).
- Affected code: `lib/Service/SynchronizationService.php`
  (`fetchSinglePageData()` + three new private methods:
  `parseMarkdownResponse()`, `parseHtmlResponse()`, `extractHtmlField()`),
  `composer.json`/`composer.lock` (new deps), unit tests.
- Not affected: JSON/XML/JSONL/gzip sources (no `configuration.format:
  "markdown"|"html"` signal present) take the exact pre-existing code path —
  proven by an explicit regression test alongside the pre-existing oc#97
  regression tests.
- Unblocks (follow-up, not this change): flipping spectr's
  `awesome_selfhosted.json` (→ `format: "markdown"`, no further config
  needed) and `openalternative.json`/`don_oss_register.json` (→ `format:
  "html"` + a `htmlSelector`/`htmlFields` config authored per source) from
  `isEnabled: false` draft to live.
