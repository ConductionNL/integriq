# Design — markdown-list + HTML/CSS-selector source extraction (oc#107)

## Context

Verified against HEAD (`origin/development`, `f73eb1bb`, which already
carries oc#105's gzip/JSONL work at `f121113d`):

- `SynchronizationService::fetchSinglePageData()` (`lib/Service/
  SynchronizationService.php`) is the single dispatch point for every
  response-body parse strategy: gzip detection/decompression, `.tar.gz`
  refusal, `sourceConfig.format === "jsonl"` line parsing, then (falling
  through) `json_decode()` and, if empty, `simplexml_load_string()` +
  `xmlToArray()`. All of the oc#105 additions live BEFORE the JSON/XML
  attempt and each `return` early with `['objects' => ..., 'result' =>
  ...]` once they've produced a result — this change follows the identical
  shape: two more early-return branches, inserted directly after the
  existing JSONL branch, still before the JSON/XML fallback.
- Two distinct config namespaces already coexist in this method:
  `Source.configuration` (read via `$source['configuration']`, e.g. the
  oc#97 `decompress` hint) and `Synchronization.sourceConfig` (read via
  `$synchronization['sourceConfig']`, e.g. `format: "jsonl"`,
  `resultsPosition`). The prior change used `sourceConfig.format` for
  JSONL because JSONL-vs-not can, in principle, vary per synchronization of
  the same bulk endpoint. Markdown/HTML shape is different: it is a fixed
  property of the fetched URL itself (an awesome-selfhosted README is
  always markdown, regardless of what synchronization reads it), so this
  change deliberately keys off `Source.configuration.format` instead —
  matching how oc#107 itself names the config surface ("Source.
  configuration.format") and avoiding a shared `format` key across two
  different objects meaning two different things to one reader.
- `getAllObjectsFromArray()` treats `sourceConfig.resultsPosition ===
  "_root"` as "return the decoded array as-is" — exactly what a flat array
  of markdown/HTML records needs, no different from how `format: "jsonl"`
  sources already pair with `resultsPosition: "_root"`.
- `composer.json` had no HTML/DOM library beyond the stock `ext-dom`/
  `ext-libxml`/`ext-simplexml` (already used for the XML fallback via raw
  `simplexml_load_string`). `symfony/dom-crawler` + `symfony/css-selector`
  were added (see Decisions below) rather than driving `DOMDocument`/
  `DOMXPath` by hand, since a CSS-selector config surface
  (`htmlSelector`/`htmlFields`) is explicitly what oc#107 asks for.

## Decisions

- **HTML library: `symfony/dom-crawler` + `symfony/css-selector`, pinned to
  `^7.2`.** These are the standard, actively-maintained PHP libraries for
  CSS-selector-driven DOM traversal (MIT-licensed, EUPL-1.2-compatible).
  `composer require symfony/dom-crawler symfony/css-selector` (unpinned)
  resolves to the `^8.1` line, which requires PHP `>=8.4.1` — this app's
  `composer.json` floors on `"php": "^8.3"`, so an unpinned require would
  have silently raised the platform floor for every consumer. Pinning to
  `^7.2` (PHP 8.1+ compatible, last major before the 8.x line) avoids that;
  confirmed installable via `composer require "symfony/dom-crawler:^7.2"
  "symfony/css-selector:^7.2"` against a `php:8.3-cli` container (the same
  image the `pre-merge-check-strict` CI gate uses).
- **Config shape**: `Source.configuration.format: "html"` (dispatch
  signal) + `Source.configuration.htmlSelector` (CSS selector, string,
  required — matches the repeating record container) +
  `Source.configuration.htmlFields` (object, `fieldName => selector`,
  optional — a `format: "html"` source with no `htmlFields` still returns
  one empty-array record per matched container, which is valid but not
  useful; documented as "add htmlFields to get data out").
- **`selector@attr` syntax for attribute extraction.** A field selector
  ending in `@attributeName` (e.g. `a@href`) extracts that DOM attribute
  instead of trimmed text content; a selector with no `@` extracts trimmed
  text. An EMPTY selector before the `@` (i.e. a field selector that is
  JUST `@attr`) targets the container element itself rather than a
  descendant — covers containers that ARE the link (e.g. `htmlSelector:
  "li.item a"` making the container the anchor itself).
- **Markdown line pattern**: a single regex,
  `^[-*+]\s*\[(?P<name>[^\]]+)\]\((?P<url>[^)\s]+)[^)]*\)(?:\s*[-:—]?\s*(?P<rest>.*))?$`,
  applied per-line (`preg_split` on any line ending). `$rest` (everything
  after the link) is then decomposed: `description` = the text before the
  first backtick, trimmed; `tags` = every `` `...` `` fragment, in order,
  via `preg_match_all`. This works out-of-the-box for
  awesome-selfhosted's exact shape (`- [Name](url) - description \`License\`
  \`Language\``) with zero extra config beyond `format: "markdown"` — no
  regex/field-mapping config was added because no second markdown-shaped
  source was in scope to validate a configurable pattern against; a future
  source needing a different list shape is a documented extension point,
  not implemented speculatively here.
- **`tags` is positional and unlabelled.** This method assigns no semantic
  meaning to tag position (e.g. "tag 0 is always the license") — it
  documents that awesome-selfhosted-shaped sources conventionally put
  license first and language second, but leaves mapping a positional tag to
  a named field to the synchronization's own mapping/rules layer, not to
  the fetch-engine parser. This avoids over-fitting the generic parser to
  one source's convention.
- **Both new branches skip gracefully, never throw.** A markdown line that
  doesn't match the list-item pattern (heading, prose, blank, link-less
  bullet) is silently dropped. An HTML `htmlSelector` that matches nothing,
  or a `htmlFields` sub-selector that matches nothing within a container,
  yields `[]` / `null` respectively rather than an exception — a partially
  malformed page should degrade gracefully (fewer/incomplete records), not
  abort the whole synchronization.

## Backward compatibility

Both new branches are gated behind an explicit, opt-in signal:
`Source.configuration.format === "markdown"` or `"html"` (case-insensitive).
A source with neither — every existing JSON, XML, JSONL, and gzip source in
the fleet today — never enters `parseMarkdownResponse()` or
`parseHtmlResponse()` at all: `$sourceFormat` evaluates to `''`, both `if`
checks are false, and control falls straight through to the pre-existing
`sourceConfig.format === "jsonl"` check (also unaffected — different config
object) and then the original `json_decode` → XML-fallback sequence,
unchanged. Proven by an explicit regression test
(`testExistingJsonSourceWithoutMarkdownOrHtmlFormatIsUnaffected`) alongside
the pre-existing oc#97 JSON/XML regression tests, all green in the same
PHPUnit run (420/420, see tasks.md).
