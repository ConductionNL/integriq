---
kind: code
depends_on: []
---

# Proposal: sources-github-publiccode

## Summary

Rotterdam wants OpenCatalogi to find every `publiccode.yml` on GitHub, not one organisation's. The harvest flow ships in OpenCatalogi. Integriq owes it two things: a GitHub source an administrator only has to give a token, and a way to read a YAML file that a call returns.

## Why

The harvest was proven by hand on 2026-08-13 against a dev instance: twelve shards over GitHub code search. It left three engine fixes behind (`884a57052`, `485df3834`, `b4c3df182`): offset paging, a page count read from the `Link` header, and a rate limit that suspends a run instead of ending it. It never shipped a source, and nothing in `lib/` decodes YAML, although `symfony/yaml` has been in `composer.json` for months.

A `publiccode.yml` arrives in one of two shapes:

- raw from `raw.githubusercontent.com`, served as `text/plain`;
- through the contents API, as JSON with the file base64-encoded in `content`.

Today `openconnector.source-call` hands either one on as a string. A flow cannot map a string onto the `publiccode` schema.

## What integriq already has

- Source templates seeded from `lib/Settings/register.d/*-source.json` and listed by `CatalogRegistryService::collectFromSeedFragments()`.
- `openconnector.source-call` (`lib/Flow/SourceCallNode.php`), which decodes JSON only (`decodeBody()`).
- The synchronization page path (`SynchronizationService::fetchPage` area, around line 7180), which reads JSON, XML, JSONL, markdown and HTML, but not YAML.
- Paging by `Link` `rel="last"` and a rate-limit suspension in `SynchronizationRunNode` and `SourcePaginateNode`. The suspension only fires on a 429 without a body, or when the source's counter is already at zero before the call. GitHub answers an exhausted quota with a 403 that carries `X-RateLimit-Remaining: 0`, which today ends as a failed page.
- OpenRegister's credential broker with a `github` provider (`api.github.com`, `Authorization: token {secret}`).

## What this change builds

1. Two source templates: `github-api` (api.github.com, token through the broker, dormant) and `github-raw` (raw.githubusercontent.com, no credential).
2. A response decoder, `OCA\Integriq\Service\ResponseDecoder`, for `json`, `yaml`, `base64+yaml`, `base64+json`, `text` and `auto`, chosen by step configuration or by the response content type.
3. A `decode` option on `openconnector.source-call`. A file that does not parse fails that item with kind `decode`, and the run reports it.
4. YAML in the synchronization page path, by `Source.configuration.format: "yaml"` or a YAML content type.
5. A 403 or 429 page that says the quota is spent suspends the run, like a 429 already does.

## Out of scope

- The harvest flow, the shard queries and the mapping onto `publiccode` (OpenCatalogi `publiccode-github-harvest`).
- The authenticated crawl against real GitHub: it needs Ruben's token, and the coordinator runs it.
- Adding `GET /search/code` to OpenRegister's `github` broker provider. Without it the broker refuses code search; this is requested of OpenRegister.

## Impact

- New: `lib/Settings/register.d/github-source.json`, `lib/Service/ResponseDecoder.php`, `lib/Exception/ResponseDecodeException.php`, fixtures under `tests/fixtures/publiccode/`.
- Changed: `lib/Flow/SourceCallNode.php`, `lib/Flow/SourceCallConfigGuard.php`, `lib/Service/SynchronizationService.php`, `lib/Service/CatalogRegistryService.php`.

## Risks

- YAML can carry tags that build PHP objects. The decoder parses with no object or constant flags, and refuses custom tags.
- A huge file in memory. The decoder refuses a body over 1 MB before it parses; a `publiccode.yml` is a few KB.
