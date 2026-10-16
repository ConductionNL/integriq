# developer-overheid-sources Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- [sources-developer-overheid](../../)

## Purpose

An administrator connects the developer.overheid.nl OSS-register to integriq by storing one API key in the credential broker, and a flow can read the register page by page and fetch each listed repository's `publiccode.yml` from github.com or gitlab.com. This lets OpenCatalogi harvest the register (`publiccode-developer-overheid-harvest`). The key never sits on the source (ADR-064).

## ADDED Requirements

### Requirement: The OSS-register is a dormant source that holds only a credential reference (REQ-DOS-001)

Integriq MUST seed a source `developer-overheid-oss` with location `https://api.developer.overheid.nl/oss-register/v1` and the header `Accept: application/json`. It MUST ship disabled. Its key MUST be a credential reference to a credential named `developer-overheid-oss` of OpenRegister provider `generic-apikey`, placed at `configuration.authentication.apikey`; the fragment MUST NOT carry a key or any other secret. The source MUST appear in the connector catalogue under `Government registers`, and its description MUST tell the administrator where to request a key (https://apis.developer.overheid.nl/apis/key-aanvragen) and how to name the credential.

#### Scenario: An administrator finds the register in the catalogue
- GIVEN a fresh install of integriq
- WHEN the connector catalogue is listed
- THEN `developer-overheid-oss` appears under `Government registers`, disabled, naming the credential `developer-overheid-oss`
- AND its description links to the key request page
- @e2e exclude seed data; covered by PHPUnit on the fragment and the catalogue

#### Scenario: The fragment holds no secret
- GIVEN `lib/Settings/register.d/developer-overheid-source.json`
- WHEN it is read
- THEN `configuration.authentication.apikey` is exactly `{"credentialRef": {"credentialName": "developer-overheid-oss"}}`
- AND no other value under `configuration` is a literal key
- @e2e exclude static data; tests/Unit/Settings/DeveloperOverheidSourceFragmentTest.php

### Requirement: The key is sent as X-Api-Key and never stored or logged (REQ-DOS-002)

When a call goes out on `developer-overheid-oss`, integriq MUST resolve the referenced credential through the broker's inject-only path and MUST send its secret in the request header `X-Api-Key`. The secret MUST NOT be written to the source, a synchronization, a flow run log or a CallLog; the CallLog MUST show the header redacted. When the credential is missing, belongs to a provider that is not inject-only, or the broker refuses it, the call MUST NOT be sent and MUST be logged as a configuration error naming the credential, not the secret.

#### Scenario: A list call carries the key
- GIVEN a `generic-apikey` credential `developer-overheid-oss` with secret `YOUR_API_KEY_HERE` and `openconnector` in its allowed apps, and the source enabled
- WHEN a synchronization on the source fetches `/repositories?publiccode=true&perPage=100&page=1`
- THEN the request carries `X-Api-Key: YOUR_API_KEY_HERE`
- AND the CallLog shows the `X-Api-Key` header redacted
- @e2e exclude outbound call; verified live against the mock, which answers 401 without the header

#### Scenario: No credential, no call
- GIVEN the source enabled and no credential named `developer-overheid-oss`
- WHEN a call is made on the source
- THEN no request reaches the register
- AND the CallLog is a 409 configuration error naming `developer-overheid-oss`
- @e2e exclude engine behaviour; covered by the existing BrokeredCallService tests plus one fragment-level test

### Requirement: gitlab.com raw files are a credential-free source (REQ-DOS-003)

Integriq MUST seed a source `gitlab-raw` with location `https://gitlab.com`, the header `Accept: text/plain`, no credential, enabled. It MUST appear in the connector catalogue under `Code hosting`. A flow MUST be able to read a public file with endpoint `/{namespace}/{project}/-/raw/{ref}/{path}` and `decode: yaml`.

#### Scenario: A flow reads a publiccode.yml from gitlab.com
- GIVEN a source-call step on `gitlab-raw` with endpoint `/example-group/project/-/raw/main/publiccode.yml` and `decode: yaml`
- WHEN the file is a valid publiccode.yml
- THEN the item's `body` holds the file's `name` and `url`
- @e2e exclude backend flow node; verified live against the mock's raw route

#### Scenario: The endpoint stays inside the source
- GIVEN a source-call step on `gitlab-raw` whose endpoint renders to `https://example.invalid/publiccode.yml`
- WHEN the step runs
- THEN the call is refused before any request, because the endpoint is an absolute URL
- @e2e exclude existing guard; FlowConfigGuard tests

### Requirement: A listing that fits on one page ends after one request (REQ-DOS-004)

The synchronization page loop MUST read the number of pages from a `Link` header whose `rel` is `last` with double quotes, single quotes or none, and, when no `Link` count is present, from an integer `Total-Pages` response header. A count once found MUST NOT be overwritten by a later page without one. With a count of 1 the loop MUST NOT request page 2. The empty-page and failed-page stop rules MUST stay as they are.

#### Scenario: A single-quoted Link header
- GIVEN a page answered with `Link: <https://api.example.invalid/repositories?page=2>; rel='next', <https://api.example.invalid/repositories?page=3>; rel='last'`
- WHEN the page loop reads it
- THEN the last page is 3

#### Scenario: Total-Pages on a one-page register
- GIVEN the register answering page 1 with 92 records, `Total-Pages: 1` and no `Link` header
- WHEN a synchronization fetches the listing
- THEN exactly one request is sent
- @e2e exclude engine behaviour; PHPUnit on the helper and a live run against the mock with `/__requests`

#### Scenario: GitHub's double-quoted header still works
- GIVEN a page answered with GitHub's `Link: <https://api.github.com/search/code?q=x&page=4>; rel="last"`
- WHEN the page loop reads it
- THEN the last page is 4

### Requirement: A mock of the OSS-register replays its shapes (REQ-DOS-005)

`tests/mocks/developer-overheid/server.py` MUST answer every route with 401 and the register's message when `X-Api-Key` is missing or wrong; MUST serve `GET /oss-register/v1/repositories` as a JSON array of RepositorySummary records with `Link` (single-quoted `rel`), `Total-Count`, `Total-Pages`, `Current-Page` and `Per-Page` headers, honouring `page` and `perPage` (at most 100); MUST serve `GET /oss-register/v1/repositories/{id}` as a RepositoryDetail with `publicCode`, and 404 for an unknown id; and MUST serve raw files for github.com- and gitlab.com-shaped paths, including one broken YAML file and one missing file. It MUST use the Python standard library only and MUST be listed in `tests/mocks/README.md`.

#### Scenario: Without a key the mock refuses
- GIVEN the mock running with `KEY=YOUR_API_KEY_HERE`
- WHEN `GET /oss-register/v1/repositories` is sent without `X-Api-Key`
- THEN it answers 401 with `Missing credentials (Authorization or x-api-key)`
- @e2e exclude test asset

#### Scenario: Two pages
- GIVEN the mock with `TOTAL=150`
- WHEN page 1 is requested with `perPage=100`
- THEN it answers 100 records with `Total-Pages: 2` and a `Link` header naming page 2 as `next` and `last`
- @e2e exclude test asset

## Non-Functional Requirements

- **Performance:** A listing of up to 100 repositories costs one request.
- **Accessibility:** n/a — no user interface; the sources appear in the existing catalogue.
- **Internationalization:** The source names and descriptions are seed data shown as entered; the catalogue labels follow the existing label set (ADR-007).

## Acceptance Criteria

- Both sources are in the catalogue under their labels; `developer-overheid-oss` disabled, `gitlab-raw` enabled.
- A call on `developer-overheid-oss` carries `X-Api-Key` from the broker and logs it redacted.
- A one-page listing is one request; GitHub paging is unchanged.
- The mock answers the shapes above.

## Notes

- Consumer: OpenCatalogi `publiccode-developer-overheid-harvest`.
- If the register refuses the key on `GET /repositories`, the source moves to `generic-oauth2` with the same slug (design D1, proposal Risk 1).
