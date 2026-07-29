# Contract: connector-catalog-ui

## Consumers

- `openconnector` (this repo's own SPA, `src/`) — the only consumer. No other `apps-extra` project calls these endpoints today (verified in discovery.md: no other app currently consumes OpenConnector's configuration export/import surface, and the catalog endpoints are new). This contract is recorded for internal front/back alignment, not cross-project coordination — see company-wide ADR-002 for the conventions it follows.

## Endpoints

### `GET /api/catalog/items/{id}/status`
**Auth**: Nextcloud session (`#[NoAdminRequired]`); any authenticated user may read status (read is not gated by the `catalog.instantiate` action — only the write action is).

**Request:** none (path param `id` = `catalog_item` object id)

**Response (200):**
```json
{ "id": "pdok-wms", "status": "available", "mechanism": "flag-gated", "flagKey": "pdok.feature_flag" }
```

**Errors:**
| Code | Condition |
|------|-----------|
| 401  | Not authenticated |
| 404  | No `catalog_item` with that id |

### `POST /api/catalog/items/{id}/instantiate`
**Auth**: Nextcloud session, `#[NoAdminRequired]` + the existing `ActionAuthService::requireAction($user, 'catalog.instantiate')` (ADR-023 — `lib/Service/ActionAuthService.php`, action seeded `["admin"]` in `lib/actions.seed.json`); underlying Source/app-config write still enforced by OpenRegister data-layer authorization (admin-only on `source` schema).

**Request:** `{}` (empty body — target looked up server-side from the catalog item)

**Response (201):**
```json
{ "created": true, "type": "source", "id": "<uuid>", "action": "enabled" }
```

**Errors:**
| Code | Condition |
|------|-----------|
| 401  | Not authenticated |
| 403  | `catalog.instantiate` action denied, OR underlying data-layer authorization denies the write (e.g. non-admin) |
| 404  | No `catalog_item` with that id |
| 409  | Item already instantiated / flag already enabled (idempotency guard) |

### `POST /api/configurations/{id}/export`
**Auth**: Nextcloud session, `#[NoAdminRequired]` + the existing `ActionAuthService::requireAction($user, 'configuration.export')` (action seeded `["admin"]` in `lib/actions.seed.json`).

**Request:** none (path param `id` = configuration group id)

**Response (200):** `Content-Disposition: attachment`, body = redacted OAS JSON document (unchanged shape from existing `ConfigurationService::exportConfiguration()`, see `configuration-export-import` spec REQ-001/REQ-005).

**Errors:**
| Code | Condition |
|------|-----------|
| 401  | Not authenticated |
| 403  | `configuration.export` action denied |
| 404  | No configuration group with that id |

### `POST /api/configurations/import/preview`
**Auth**: Nextcloud session, `#[NoAdminRequired]` + the existing `ActionAuthService::requireAction($user, 'configuration.import')` (preview shares the import action gate — it is read-only but still gated, since it reveals what the target environment currently contains).

**Request:**
```json
{ "document": { "components": { "sources": {}, "endpoints": {} } } }
```

**Response (200):**
```json
{
  "creates": [{ "type": "source", "slug": "new-source" }],
  "updates": [{ "type": "endpoint", "slug": "existing-endpoint", "id": "<uuid>" }],
  "collisions": [{ "type": "source", "slug": "ambiguous-slug", "reason": "slug matches an object of a different schema" }],
  "unresolvedReferences": [{ "type": "rule", "slug": "r1", "field": "sourceId", "value": "unknown-source-slug" }],
  "credentialsNeedingReentry": [{ "type": "source", "slug": "new-source", "fields": ["apikey", "secret"] }]
}
```

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | Document missing top-level `components` key (matches existing `ConfigurationService` `InvalidArgumentException`) |
| 401  | Not authenticated |
| 403  | `configuration.import` action denied |

### `POST /api/configurations/import`
**Auth**: Nextcloud session, `#[NoAdminRequired]` + the existing `ActionAuthService::requireAction($user, 'configuration.import')`; underlying per-entity writes still enforced by each schema's own OpenRegister data-layer authorization (e.g. Source writes admin-only).

**Request:**
```json
{ "document": { "components": {} }, "confirmed": true }
```

**Response (200):** same shape as `/preview`'s response, reflecting what was actually written.

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | `confirmed` missing/false, OR document missing top-level `components` key |
| 401  | Not authenticated |
| 403  | `configuration.import` action denied, OR a per-entity data-layer authorization denies part of the write |

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 400  | Bad Request | Malformed/incomplete import document, or missing `confirmed` flag |
| 401  | Unauthorized | No authenticated Nextcloud session |
| 403  | Forbidden | ADR-023 action-matrix denial or OpenRegister data-layer denial |
| 404  | Not Found | Referenced catalog item or configuration group does not exist |
| 409  | Conflict | Catalog item already instantiated/enabled (instantiate endpoint only) |

## Versioning

These endpoints ship as part of OpenConnector's existing unversioned `/api/*` surface (matching every other OpenConnector controller — no `/v1/` prefix in use anywhere in `appinfo/routes.php` today). No version negotiation is introduced.

## Breaking Change Policy

Since the only consumer is this app's own bundled SPA (built and deployed together), backend and frontend ship atomically in the same release — there is no cross-project compatibility window to manage. If a future external consumer appears, this contract becomes the basis for a versioned/deprecation-cycle policy at that time; not needed now.

## SLA

No formal SLA — internal, same-deployment consumer only. Informal expectation: catalog list/status reads complete within the same latency envelope as other `index`-type pages (OpenRegister object-list query, typically sub-second per existing fleet observability); import preview/import may take longer proportional to document size, matching the existing `exportConfiguration()`/`importConfiguration()` O(all entities of each type) cost noted in `configuration-export-import` spec REQ-001 Notes — not changed by this contract.
