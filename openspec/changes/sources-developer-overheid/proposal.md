---
kind: mixed
depends_on: []
---

# Proposal: sources-developer-overheid

## Summary

Integriq gets two source templates and one paging fix so that OpenCatalogi can harvest the developer.overheid.nl OSS-register: `developer-overheid-oss` (the register's API, dormant until an administrator stores their API key in the credential broker) and `gitlab-raw` (public files on gitlab.com, no credential). A listing that fits on one page now ends after one request, because the page count is also read from a single-quoted `Link` header and from a `Total-Pages` header. A mock of the register lets the harvest be tested on a dev instance without a key.

## Motivation

OpenCatalogi's change `publiccode-developer-overheid-harvest` (Jira WOO-592) reads every repository in the OSS-register that has a `publiccode.yml` and fetches the full file from github.com or gitlab.com. Integriq does every outbound call for OpenCatalogi, so it owes three things:

- A source for the register. Every request needs `X-Api-Key`; each installation requests its own read-only key. The key must sit in the credential broker, not on the source.
- A source for raw files on gitlab.com, next to the existing `github-raw`.
- A page count the register's headers actually give. Integriq reads the count from `Link: …; rel="last"` with double quotes. The register's published example uses single quotes (`rel='last'`) and also sends `Total-Pages`; today neither is read, so a one-page listing is followed by a request for a page that does not exist, whose answer the register does not document.

## Affected Projects

- [ ] Project: `integriq` — register fragments for `developer-overheid-oss` and `gitlab-raw`, two catalogue labels, the page count from `Link` and `Total-Pages`, a mock server, the sources manual.
- [ ] Project: `opencatalogi` — consumer only (`publiccode-developer-overheid-harvest` names these sources by slug).
- [ ] Project: `openregister` — no change; `generic-apikey` is an existing inject-only provider.

## Scope

### In Scope

- Source `developer-overheid-oss`: location `https://api.developer.overheid.nl/oss-register/v1`, disabled, `Accept: application/json`, the key resolved from a `generic-apikey` credential named `developer-overheid-oss` and sent as `X-Api-Key`.
- Source `gitlab-raw`: location `https://gitlab.com`, enabled, no credential.
- Both listed in the connector catalogue (`Government registers`, `Code hosting`).
- The synchronization page loop reads the last page from `Link` with `rel="last"`, `rel='last'` or `rel=last`, and otherwise from a `Total-Pages` response header.
- `tests/mocks/developer-overheid/server.py`: list, detail, raw files, 401 without key.
- `docs/features/sources.md`: the recipe for the register's key.

### Out of Scope

- OAuth2 client credentials for the register. The read-only key is what the register hands out to the public; OAuth2 follows only if the list endpoint refuses the key (see Risks).
- The flow, the mappings and the admin section (OpenCatalogi).
- Self-hosted GitLab instances and other forges. A repository there takes the register's detail endpoint in OpenCatalogi's flow.

## Approach

Seed data plus thin glue. The two sources are register fragment objects, like `github-api` and `github-raw`. The key uses integriq's existing app-side injection: a `credentialRef` placeholder at `configuration.authentication.apikey` and a header template that renders it, which the call engine already resolves and redacts. The paging change widens one regular expression and adds a header fallback in `SynchronizationService`. Details in design.md.

## Capabilities

- New: `developer-overheid-sources` (`specs/developer-overheid-sources/spec.md`).

## New Dependencies

- External services: the developer.overheid.nl OSS-register API (key required) and gitlab.com raw files (public). No new packages.

## Impact

- New: `lib/Settings/register.d/developer-overheid-source.json`, `lib/Settings/register.d/gitlab-source.json`, `tests/mocks/developer-overheid/server.py`, `tests/Unit/Settings/DeveloperOverheidSourceFragmentTest.php`, a paging unit test.
- Changed: `lib/Service/CatalogRegistryService.php` (two labels), `lib/Service/SynchronizationService.php` (page count), `tests/mocks/README.md`, `docs/features/sources.md`.

## Cross-Project Dependencies

- OpenCatalogi `publiccode-developer-overheid-harvest` depends on this change.
- OpenRegister's credential broker must offer `generic-apikey` and `resolveInjectable()` (both exist).

## Risks

### Risk 1: The list endpoint may refuse an API key
**Severity:** High — **Mitigation:** the OpenAPI document declares only OAuth2 client credentials on `GET /repositories`, while `GET /repositories/{id}` explicitly allows the key and the 401 answer names `x-api-key`. The live task checks the list endpoint with a real key first. If it refuses, the source switches to `generic-oauth2` (client id, token URL `https://auth.developer.overheid.nl/realms/don/protocol/openid-connect/token`, scope `repositories:read`, secret as a placeholder) with an `Authorization: Bearer {{ oauthToken(source) }}` header; the source slug and every consumer stay the same.

### Risk 2: A wider `Link` pattern reads a wrong count on another source
**Severity:** Low — **Mitigation:** the pattern still needs `rel` equal to `last` and a `page=N` in the URL; only the quoting is relaxed. `Total-Pages` is read only when no `Link` count was found, and the stop rule (empty page, failure) is unchanged, so a wrong count costs requests, not data.

## Rollback Strategy

Revert the change. Seeded sources stay in the register until deleted; a disabled `developer-overheid-oss` sends nothing. The paging change reverts to double-quoted `Link` only, which costs one extra request per run on the register.

## Open Questions

- Does `GET /repositories` accept `X-Api-Key` (Risk 1)?
- What does the register answer for a page past the last: an empty array or an error? The paging change avoids asking.
- Do self-hosted or other forges need a raw source of their own: self-hosted GitLab (`gitlab.<organisation>.nl`), Gitea/Forgejo (including codeberg.org), Bitbucket, Azure DevOps, or code.overheid.nl? Today they take the detail fallback in OpenCatalogi; decide after the first live run shows the host distribution.
