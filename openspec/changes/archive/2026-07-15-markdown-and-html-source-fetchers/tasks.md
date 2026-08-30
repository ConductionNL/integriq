# Tasks — markdown-list + HTML/CSS-selector source extraction (oc#107)

> Verified the exact fetch/parse flow at HEAD before touching it (see
> design.md `Context`) — this is the same dispatch point oc#105's
> gzip/JSONL work occupies, inside `fetchSinglePageData()`.

- [x] Add `symfony/dom-crawler` + `symfony/css-selector` (pinned `^7.2` —
  PHP 8.1+ compatible; the unpinned `^8.x` resolution requires PHP 8.4 and
  would have broken this app's `"php": "^8.3"` floor)
- [x] Add `parseMarkdownResponse()`: regex-based markdown list-item parser
  (`- [Name](url) - description \`Tag1\` \`Tag2\``) → `name`/`url`/
  `description`/`tags` records; skips non-matching lines gracefully
- [x] Add `parseHtmlResponse()` + `extractHtmlField()`: CSS-selector-driven
  extraction via `Symfony\Component\DomCrawler\Crawler`, keyed off
  `configuration.htmlSelector` (record container) and `configuration.
  htmlFields` (per-field sub-selectors, `selector@attr` syntax for
  attribute extraction)
- [x] Wire `Source.configuration.format === "markdown"|"html"` into
  `fetchSinglePageData()`, feeding both parsers' output through the
  existing `getAllObjectsFromArray()` (so `resultsPosition: "_root"`
  behaves identically to every other source shape)
- [x] Unit tests (`SynchronizationServiceTest`): markdown fixture (2-tag
  item, 1-tag item, 0-tag item, a heading, a blank line, a link-less bullet
  — 3 records expected out of 7 lines), HTML fixture (a 2-row table with
  both text-content and `@href` attribute extraction)
- [x] Regression test: a plain JSON source with neither `format` value is
  unaffected
- [x] Full existing PHPUnit suite green (420/420) — no regressions from
  this change
- [x] `composer phpcs` + `composer phpstan` clean on the touched file (via
  `php:8.3-cli` container, matching the CI gate's image)
- [x] `@spec` PHPDoc tags added to `fetchSinglePageData()` and all three new
  methods, pointing at this change's spec delta

Acceptance criteria (plain bullets — verified by /opsx-verify):

- A source with `configuration.format: "markdown"` and an
  awesome-selfhosted-shaped body extracts one record per matching list item,
  with correct `name`/`url`/`description`/`tags`, skipping non-list lines
- A source with `configuration.format: "html"` + `htmlSelector` +
  `htmlFields` extracts one record per matched container, correctly reading
  both trimmed text content and `selector@attr`-style attribute values
- A source with neither `format` value is provably unaffected — the
  existing JSON/XML/JSONL/gzip regression tests all still pass unchanged
- `awesome_selfhosted` is unblockable with `format: "markdown"` alone (no
  further config); `openalternative`/`don_oss_register` are unblockable with
  `format: "html"` + a per-source `htmlSelector`/`htmlFields` config
