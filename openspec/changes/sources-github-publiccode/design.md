# Design: sources-github-publiccode

Kind: code. Size M. Read at integriq development `c67abca0c` on 2026-10-01.

## Context

- `SourceCallNode::outcomeOf()` builds the item result and calls `decodeBody()`, which tries `json_decode` and otherwise returns the raw string.
- `CallService` stores a body that is not valid UTF-8 base64-encoded and marks `encoding: base64`. That is transport encoding, distinct from GitHub's base64 `content` field.
- `SynchronizationService` page parsing (around line 7180) branches on `sourceConfig.format: jsonl` and `Source.configuration.format: markdown|html` before it tries JSON, then XML.
- The rate limit: `CallService::sourceRateLimit()` records `X-RateLimit-*` on the source; `checkRateLimit()` refuses a sync before its first request; the page fetch throws `TooManyRequestsHttpException` only for a 429 without a response.

## D1. Two templates, one fragment

`lib/Settings/register.d/github-source.json` seeds:

| Slug | Location | Credential | Enabled |
|---|---|---|---|
| `github-api` | `https://api.github.com` | `configuration.authentication.credentialRef.credentialName: github-publiccode` | no |
| `github-raw` | `https://raw.githubusercontent.com` | none | yes |

`github-api` sends `Accept: application/vnd.github+json` and `X-GitHub-Api-Version: 2022-11-28`. The token never sits on the source (ADR-064): the broker proxy injects it. `github-raw` reads public files only and spends no API quota, so the harvest fetches each file there.

Both land in the catalogue under `Code hosting` through `SLUG_CATEGORY_OVERRIDES`.

## D2. One decoder, used by every response path

`ResponseDecoder::decode(string $body, string $mode, ?string $contentType): mixed`.

| Mode | Input | Output |
|---|---|---|
| `auto` (default) | anything | JSON when it parses; YAML when the content type is a YAML type; otherwise the string |
| `json` | JSON text | array; invalid JSON throws |
| `yaml` | YAML text | array or scalar; invalid YAML throws |
| `base64+yaml` | base64 text, or a JSON object with a string `content` | the YAML document |
| `base64+json` | same | the JSON document |
| `text` | anything | the string, unparsed |

YAML content types: `application/yaml`, `application/x-yaml`, `text/yaml`, `text/x-yaml`. `text/plain` is not sniffed: plain text is valid YAML, so guessing would turn every text reply into a string scalar silently. A flow that reads a raw file says `decode: yaml`.

Parsing uses `Symfony\Component\Yaml\Yaml::parse($body, Yaml::PARSE_DATETIME)` and no other flag: no `PARSE_OBJECT`, `PARSE_OBJECT_FOR_MAP`, `PARSE_CONSTANT` or `PARSE_CUSTOM_TAGS`. With those off, Symfony refuses `!php/object` and `!php/const`, and the decoder refuses any other tag.

### D2a. Dates

Symfony's YAML parser turns an unquoted `2024-01-31` into the integer `1706659200` unless `PARSE_DATETIME` is set, in which case it returns a `DateTimeImmutable`. Neither is what a mapping expects. The decoder sets `PARSE_DATETIME` and converts every `DateTimeInterface` in the result to ISO 8601: a date-only literal at midnight UTC becomes `Y-m-d`, anything else `DATE_ATOM`. `PARSE_DATETIME` builds a value object only, never an arbitrary class, so it does not open the object hole the other flags do.

### D2b. Failures

A decode failure throws `ResponseDecodeException` with the mode, the reason and, for YAML, the line Symfony reports. It never returns an empty array. A body over 1 MB is refused before parsing.

## D3. `decode` on `openconnector.source-call`

`decode` joins `configKeys()` and the config form. `validateConfig()` refuses an unknown mode. In `outcomeOf()` the decoder runs inside the per-item `try`, so a `ResponseDecodeException` becomes a `FlowNodeException` of kind `decode` and follows the step's `onError`: `stop` raises, `continue` writes `__error` on the item and leaves the output key unset. That is the same failure shape a bad status has today. Unset `decode` behaves exactly as now.

A body that `CallService` stored as transport base64 (`encoding: base64`) is decoded to bytes first when a mode other than `auto` or `text` is asked for.

## D4. YAML in the synchronization page path

`Source.configuration.format: "yaml"`, or a YAML content type on the page response, parses the page through the same decoder, then hands the array to `getAllObjectsFromArray()` like the markdown branch. A decode failure marks the page failed, like an unparseable body already does, so a stale sweep never treats it as an empty source.

## D5. A spent quota suspends

In the page fetch, a 403 or 429 whose response headers say `X-RateLimit-Remaining: 0`, or that carry `Retry-After`, throws `TooManyRequestsHttpException` with the response's own rate-limit headers. `SynchronizationRunNode` and `SourcePaginateNode` already turn that into a suspension clamped to 60 s .. 1 h. A 403 without those headers stays an ordinary failure: a private repository is not a rate limit.

## Declarative versus imperative

The templates are seed data. The decoder is code because no declarative layer parses YAML.

## Seed data

`github-api` and `github-raw`. Test fixtures: a real `publiccode.yml` at v0.2 and one at v0.4, a malformed one, and a recorded contents API answer.

## Risks

- [GitHub changes the API version] the header is a template value on the source; an administrator edits it.
- [Code search through the broker] needs OpenRegister to allow `GET /search/code` on its `github` provider.
