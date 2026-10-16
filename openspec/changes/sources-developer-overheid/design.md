# Design: sources-developer-overheid

## Context

- Source templates are seeded from `lib/Settings/register.d/*-source.json` and listed by `CatalogRegistryService`, which files a slug under a label through `SLUG_CATEGORY_OVERRIDES` (`github-api` and `github-raw` sit under `Code hosting`).
- Two credential paths exist (`docs/features/sources.md`):
  - **Proxy:** `configuration.authentication.credentialRef` as the whole block. The OpenRegister broker sends the call itself, host-locked to a provider's `baseUrl`. `SourceAuthApplier::apply()` returns early for such a source.
  - **App-side injection:** a `{"credentialRef": {…}}` placeholder at a secret's own position under `configuration.authentication`. `CallService::hydrateInjectedCredentials()` resolves it through `CredentialBrokerService::resolveInjectable()` and Phase 9 renders the configuration's Twig with the hydrated source, so a header template such as `{{ source.configuration.authentication.apikey }}` carries the key. The provider must be an inject-only `generic-*` provider.
- OpenRegister's `generic-apikey` provider is `inject_only: true`, has no `baseUrl` and no allow rules; `request()` (the proxy) refuses it.
- `SourceAuthApplier::applyApiKey()` reads the source's top-level `apikey` field and sends it in the header named by the top-level `authorizationHeader` field (default `Authorization`). It never sees a brokered key.
- `CallService` redacts `Authorization`, `Cookie` and every header whose name matches the secret-key pattern (covers `X-Api-Key`) before a CallLog is written.
- `SynchronizationService::parseLastPageFromLinkHeader()` matches `<…[?&]page=N…>; rel="last"` with double quotes only. Body totals are read through `totalPosition`; response headers other than `Link` are not.
- `FlowConfigGuard::endpointEscapeCode()` keeps every flow endpoint inside its source: no scheme, no `//host`, no `../`.

## Goals / Non-Goals

**Goals:** the two sources, the key kept in the broker and sent as `X-Api-Key`, a one-request listing for a one-page register, a mock to test against.

**Non-Goals:** OAuth2 for the register, other forges, the harvest flow.

## Decisions

### D1. The key: app-side injection with a header template, not `authorizationHeader`

The source:

```json
{
  "@self": {"register": "integriq", "schema": "source", "slug": "developer-overheid-oss"},
  "name": "developer.overheid.nl OSS-register",
  "description": "The national register of government open-source repositories. Request a read-only API key at https://apis.developer.overheid.nl/apis/key-aanvragen, store it as a credential named developer-overheid-oss with provider generic-apikey and openconnector in its allowed apps, then enable this source.",
  "type": "api",
  "location": "https://api.developer.overheid.nl/oss-register/v1",
  "auth": "none",
  "configuration": {
    "headers": {
      "Accept": "application/json",
      "X-Api-Key": "{{ source.configuration.authentication.apikey }}"
    },
    "authentication": {
      "type": "apikey",
      "apikey": {"credentialRef": {"credentialName": "developer-overheid-oss"}}
    }
  },
  "isEnabled": false,
  "test": false,
  "version": "1.0.0"
}
```

- **Why not a top-level `credentialRef` with `authorizationHeader: x-api-key`.** A top-level `credentialRef` selects the proxy path, which the broker refuses for an inject-only provider such as `generic-apikey`. And `authorizationHeader` is read by `SourceAuthApplier` only for a key stored on the source itself, which is exactly what must not happen; for any `credentialRef` source the applier steps aside. So the brief's shape would either be refused at call time or send no key.
- **Why this shape works without new code.** It is the documented app-side injection ("API key injected into a header"), the same mechanism the course-marketplace sources use for an OAuth client secret. The key is resolved per call under the broker's owner and `allowedApps` guards, placed in the header, and redacted from the CallLog because `X-Api-Key` matches the secret-key pattern.
- **Alternative considered: teach `SourceAuthApplier` to honour `authorizationHeader` for an injected key.** It would add a second way to do what the header template already does, in code, for one source.
- **The test environment.** An administrator who wants the register's test environment changes `location` to `https://api.don.apps.digilab.network/oss-register/v1`; the key comes from that environment.

### D2. `gitlab-raw`

```json
{
  "@self": {"register": "integriq", "schema": "source", "slug": "gitlab-raw"},
  "name": "GitLab raw files",
  "description": "Reads public files from gitlab.com projects, such as publiccode.yml, at /{namespace}/{project}/-/raw/{ref}/{path}. Needs no account.",
  "type": "api",
  "location": "https://gitlab.com",
  "auth": "none",
  "configuration": {"headers": {"Accept": "text/plain"}},
  "isEnabled": true,
  "test": false,
  "version": "1.0.0"
}
```

Enabled like `github-raw`: it reads only public files and holds nothing secret. A private or missing project answers with a redirect to the sign-in page or a 404; the flow's YAML decode then finds no `name` and drops the item. The fragment is its own file (`gitlab-source.json`) because GitLab is not part of the developer.overheid.nl register.

### D3. Catalogue labels

`SLUG_CATEGORY_OVERRIDES` gains `'developer-overheid-oss' => 'Government registers'` and `'gitlab-raw' => 'Code hosting'`.

### D4. Page count from a single-quoted `Link` and from `Total-Pages`

- `parseLastPageFromLinkHeader()` accepts `rel="last"`, `rel='last'` and `rel=last` (pattern `rel=(["']?)last\1`), keeping the `[?&]page=N` requirement.
- When no `Link` count was found on a page, the loop reads an integer `Total-Pages` response header (case-insensitive) into the same field, under the same rule: recorded only when found, never overwritten with null.
- With `Total-Pages: 1` a listing ends after page 1. The register's behaviour for a page past the last is undocumented, so not asking is the safe side.

Both are a few lines inside the existing helper and its caller.

### D5. The mock

`tests/mocks/developer-overheid/server.py`, standard library only, like `tests/mocks/github/server.py`:

| Route | Answer |
|---|---|
| any route without `X-Api-Key` equal to `KEY` | 401 `{"status": 401, "title": "Missing credentials (Authorization or x-api-key)"}` |
| `GET /oss-register/v1/repositories?publiccode=true&perPage=N&page=P` | a JSON array of RepositorySummary records, with `Link` (single-quoted `rel`, `next` and `last` when there are more pages), `Total-Count`, `Total-Pages`, `Current-Page`, `Per-Page` |
| `GET /oss-register/v1/repositories/{id}` | RepositoryDetail: the summary plus `publicCode` (the crawler's subset) and `longDescription`; 404 for an unknown id |
| `GET /raw/github/{owner}/{repo}/{ref}/{path}` and `GET /raw/gitlab/{namespace}/{project}/-/raw/{ref}/{path}` | a `publiccode.yml`, a deliberately broken YAML file for one record, a 404 for another |
| `GET /__requests` | the requests served so far |

Environment: `PORT` (8080), `TOTAL` (92), `KEY` (`YOUR_API_KEY_HERE`), `BASE` (the URL the mock's `Link` headers name). The records' `publicCodeUrl` values are github.com and gitlab.com URLs, as in the real register, so OpenCatalogi's rewrite classifies them as it would live; the paths it derives are what the mock serves under `/raw/github` and `/raw/gitlab`. The corpus mixes github.com and gitlab.com repositories, one on another host (for OpenCatalogi's detail fallback), one monorepo file below the root and one fork whose file names the original. To test against it an administrator points `developer-overheid-oss` at `{BASE}/oss-register/v1`, `github-raw` at `{BASE}/raw/github` and `gitlab-raw` at `{BASE}/raw/gitlab`. `tests/mocks/README.md` lists it.

## Seed Data

The change introduces no schema. Its seed data are the two source objects above, in the integriq register, matched by slug (ADR-001 idempotency). They are system templates an administrator configures, not demo content, so they carry no `example-` markers; they hold no secret and no realistic-looking key. Test fixtures: one RepositorySummary page and one RepositoryDetail in the mock, with ids `00000000-0000-0000-0000-0000000000NN`.

## Declarative-vs-imperative decision

Per ADR-031: the sources, their headers and the credential reference are declared in register fragments; the key's injection reuses the engine's existing placeholder mechanism, so no code is written for it. The page count is imperative because the paging loop is code and no schema option names a response header for it; adding a `totalHeader` source option was considered and rejected as a new configuration surface for a header that is a de-facto standard.

## Mixed-spec rationale

`kind: mixed` under ADR-032's thin-glue exception: the declarative part is the two fragments; the code is about a dozen lines across two files (`CatalogRegistryService.php`: two map entries; `SynchronizationService.php`: the relaxed pattern and the `Total-Pages` fallback), tightly coupled to the sources it serves. The mock server and the unit tests are test assets. Splitting would produce a config change whose source cannot page correctly and a code change with no consumer.

## Risks / Trade-offs

- [The list endpoint refuses the key] → switch the source to `generic-oauth2` (proposal Risk 1); the slug and consumers do not change.
- [gitlab.com rate-limits anonymous raw requests] → a run fetches a handful of gitlab.com files; a refused file is skipped by the flow and fetched again next run.
- [A source elsewhere sends a `Total-Pages` that means something else] → read only when no `Link` count exists; the stop rule still ends on an empty or failed page.

## Migration Plan

No migration. On update the fragments seed the two sources. An administrator stores the key and enables `developer-overheid-oss` when OpenCatalogi's harvest is wanted.

## Open Questions

- Whether `GET /repositories` accepts `X-Api-Key`.
- Whether the register sends `Link` and `Total-Pages` on a one-page answer; the mock assumes both, and D4 handles either.
