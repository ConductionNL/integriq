# Discovery: tables-bridge

## Question

What does the Nextcloud Tables app actually expose as a public API for
reading/writing tables, columns, and rows — is it OCS v2 only, does a stable
PHP-level API (`OCA\Tables\Api\...`) exist for in-process consumption, and
is the surface complete enough (full row CRUD, column metadata, type
information) to build a synchronization source/target on top of it?

## Approach Taken

- Searched this repo and the sibling `apps-extra`/`workspace/server` trees
  for any existing Tables integration, PHP class references
  (`OCA\Tables\*`), or prior research notes — none found; this is greenfield.
- Confirmed the existing feature-detection idiom used elsewhere in
  Integriq for optional Nextcloud-app dependencies:
  `HealthController` (`lib/Controller/HealthController.php`) and
  `SourceMappingService`/`OrganisationBridgeService`/`BrokeredCallService`
  all guard on `IAppManager::isInstalled()` /
  `IAppManager::isEnabledForUser()` **only** — never a direct reference to
  the other app's PHP classes — specifically so the check works even when
  the dependency app is fully absent (no autoload target to resolve).
- Fetched and parsed the Tables app's own published OpenAPI document
  (`https://raw.githubusercontent.com/nextcloud/tables/main/openapi.json`,
  fetched 2026-07-14) to get the authoritative, versioned endpoint list —
  rather than trusting memory, per this change's verification requirement.
- Read `nextcloud/tables` issue #2237 ("v2 API: Adding new row to table via
  request is hard to figure out") for the actual (undocumented-in-prose) row
  write shape and for corroboration of community pain with the v2 surface.
- Cross-checked `lib/Service/CallService.php::call()` to confirm it is a
  general-purpose, Source-driven HTTP dispatcher (not coupled to
  `SynchronizationService`'s target-write flow) suitable for reuse as the
  transport under a Tables-specific adapter.
- Cross-checked the already-landed `source-broker-credentials` change
  (`openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md`
  + the matching `CallService::call()` code, which already contains a
  "Phase 7b: Brokered-credential guards + resolution (REQ-SBC-001/002/003)"
  block) to see whether brokered credentials are real, shipped functionality
  or still speculative.

## Findings

**Two API generations coexist, and they are not equally complete.**

- `index.php/apps/tables/api/1/*` — the original REST API (NOT under
  `ocs/v2.php`, no OCS envelope). Full CRUD: `GET/POST /tables`,
  `PUT/GET/DELETE /tables/{id}`, `GET/POST /tables/{id}/columns`,
  `PUT/GET/DELETE /columns/{columnId}`, `GET/POST /tables/{id}/rows`,
  `GET/PUT/DELETE /rows/{rowId}`, plus `views` and `shares`. This is the
  complete, versioned, stable surface.
- `ocs/v2.php/apps/tables/api/2/*` — the newer OCS-wrapped API. Complete for
  `tables` (`GET/POST/PUT/DELETE`) and for **creating** typed columns
  (`POST columns/text|number|datetime|selection|usergroup`), plus new
  features absent from v1 (`contexts`, `favorites`, capability `init`,
  share-token `public/{token}/rows` with full CRUD). **Critically, the only
  authenticated (non-public-token) row route is
  `POST {nodeCollection}/{nodeId}/rows` — there is no authenticated
  `GET`/`PUT`/`DELETE` on a single row in v2.** Per-row read/update/delete
  only exists under the share-token path
  (`public/{token}/rows/{rowId}`), which requires a table share link, not a
  normal authenticated session — unsuitable for a synchronization running as
  a configured user identity.
- This asymmetry is corroborated by nextcloud/tables#2237: the reporter's
  complaint about the row-write shape is specifically about the v2 create
  route, and the "fix" community members converged on
  (`{"data": {"<columnId>": "<value>", ...}}`, object keyed by column id —
  not the array-of-`{columnId,value}` shape the v1/v2 **read** paths return)
  is exactly the shape both `index.php/api/1/tables/{id}/rows` (POST) and
  `ocs/v2.php/api/2/{nodeCollection}/{nodeId}/rows` (POST) both use for
  writes.
- Column schema (`Column` OpenAPI component): `type` (string: `text`,
  `number`, `datetime`, `selection`, `usergroup`) + `subtype` (string,
  type-dependent — e.g. text: `line`/`rich`/`link`; number: default/`progress`;
  datetime: `date`/`time`/`datetime`; selection: `selection`/`selection-check`/
  `selection-multi`), plus type-specific constraint fields
  (`numberDecimals`, `numberMin`/`Max`, `textMaxLength`, `selectionOptions`,
  etc.) needed to coerce/validate a mapped value before writing.
- Row schema (`Row` component): `id` (int64), `tableId`, `data` (object,
  `columnId → value` on read) and `dataByAlias` (object, column *title* →
  `{columnId, value}` — a friendlier read shape useful for the editor's
  live preview, but writes still require the `columnId`-keyed shape).
- Auth: the OpenAPI `securitySchemes` declare `basic_auth` (HTTP Basic) and
  `bearer_auth` (HTTP Bearer) — the same two mechanisms Integriq's
  `Source` entity already supports for any `api`-type source. No
  Tables-specific auth scheme exists; a Tables `Source` is configured
  exactly like any other HTTP source (app-password Basic Auth, or a
  brokered credential).
- **No stable PHP-level integration surface was found or is documented.**
  Tables does not publish an `OCA\Tables\Api\*` facade intended for other
  apps to call in-process; its own internal `Service`/`Controller` classes
  are implementation detail, not a contract, and are far more likely to
  change shape across Tables releases than the documented, OpenAPI-schema'd
  REST surface. This confirms the brief's instinct to feature-detect and go
  through the public API rather than reach into Tables' internals.
- `CallService::call(ObjectEntity $source, string $endpoint, string $method,
  array $config, ...)` is already a general-purpose, Source-driven HTTP
  dispatcher used well beyond `SynchronizationService`'s target-write path —
  it is a safe, direct reuse target for a Tables adapter (inherits CallLog
  persistence, rate-limit tracking, header/Twig normalisation, and —
  already shipped — brokered-credential dispatch).
- `source-broker-credentials` is **not** speculative: its
  `CallService::call()` Phase 7b (`credentialRef` resolution,
  `CredentialBrokerService::request()` dispatch) is already present in HEAD.
  A Tables `Source` can use a brokered credential today without this change
  inventing any new secret-handling code.

## Recommendation

Build `TablesClientInterface` (+ concrete implementation) against the v1
(`index.php/apps/tables/api/1/*`) surface for **all** row/table/column CRUD
and metadata reads, because it is the only surface with complete,
authenticated single-row `GET`/`PUT`/`DELETE`. Do not build on v2 for row
operations — it is documented-incomplete for the authenticated case this
change needs (verified against the live OpenAPI schema, not assumed).
`TablesClientInterface` is deliberately API-version-agnostic in its method
signatures (`listTables()`, `listColumns()`, `listRows()`, `createRow()`,
`updateRow()`, `deleteRow()`, `getCapabilities()`) so a future
`TablesOcsV2Client` could be swapped in without touching
`SynchronizationService` once v2 row CRUD matures. Feature-detect the whole
target/source type via `IAppManager::isEnabledForUser('tables')` — never via
an OCS capabilities round-trip and never via a direct `OCA\Tables\*` class
reference. Transport is `CallService::call()` against a normal `Source`
object; no new HTTP client, no new secret storage.

## Risks Uncovered

- The v1 API path is not literally deprecated by Nextcloud (it remains
  documented and live in the fetched schema alongside v2), but Tables'
  README wiki frames v2 as the forward direction. If a future Tables release
  drops v1 row endpoints before v2 gains authenticated single-row CRUD,
  `TablesClientInterface`'s v1 implementation would need an urgent swap.
  Mitigated by the interface boundary; flagged as a design risk, not
  blocking.
- `dataByAlias` (title-keyed) is attractive for the editor's column-mapping
  UI (no numeric column ids in the mapping config) but is documented only on
  the **read** shape; writes must still resolve to numeric `columnId`s. The
  adapter resolves alias → columnId server-side at write time using the
  cached column list, so the mapping UI/config can store human-readable
  column titles.

## Next Steps

Proceed to design.md (interface shape, identity/permission model, contract
mapping, coercion rules) and the spec deltas. No further discovery spikes
needed — the API surface question is answered with evidence.
