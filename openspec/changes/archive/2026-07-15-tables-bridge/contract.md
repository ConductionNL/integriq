# Contract: tables-bridge

This is an internal contract between OpenConnector's backend (new discovery
endpoints) and its own `sync-editor-ui` Vue frontend — not a cross-project
API (no other apps-extra project consumes it). Captured formally anyway
because the editor's table picker and column-mapping helper need a pinned
shape to build against.

## Consumers

- `openconnector` (`src/` Vue, `sync-editor-ui` capability): `SyncConfigWidget.vue`
  (table picker) and the new column-mapping helper component call these
  endpoints when the selected source/target kind is `nextcloud-table`.

## Endpoints

### `GET /apps/openconnector/api/synchronizations/tables-bridge/tables`

**Auth**: Nextcloud session (admin/authenticated user), `@NoAdminRequired`
+ `@NoCSRFRequired` per the existing `SynchronizationsController` posture.

**Query params:** `sourceId` (required — the `Source` id whose credentials
are used to list tables).

**Response (200):**
```json
{
  "results": [
    { "id": 42, "title": "Vendor Invoices", "ownerType": "user" }
  ]
}
```

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | `sourceId` missing or not a valid Source id |
| 404  | Source not found |
| 409  | Tables app not enabled (`IAppManager::isEnabledForUser('tables')` false) |
| 502  | Upstream Tables call failed (network/5xx) — message carries the CallLog reference, never raw upstream body |

### `GET /apps/openconnector/api/synchronizations/tables-bridge/tables/{tableId}/columns`

**Auth**: same as above.

**Query params:** `sourceId` (required).

**Response (200):**
```json
{
  "results": [
    {
      "id": 7,
      "title": "Amount",
      "type": "number",
      "subtype": null,
      "mandatory": true,
      "constraints": { "numberDecimals": 2, "numberMin": 0 }
    },
    {
      "id": 8,
      "title": "Status",
      "type": "selection",
      "subtype": "selection",
      "mandatory": false,
      "constraints": { "selectionOptions": ["open", "paid", "overdue"] }
    }
  ]
}
```

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | `sourceId` missing/invalid, or `tableId` not numeric |
| 404  | Source or table not found (upstream 404 mapped through) |
| 409  | Tables app not enabled |
| 401/403 | Configured identity cannot read this table's columns — mapped through unmodified from the upstream Tables response |
| 502  | Upstream Tables call failed |

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 400  | Bad request | Missing/invalid `sourceId` or `tableId` |
| 401  | Unauthenticated upstream | Configured Source credential rejected by Tables |
| 403  | Forbidden upstream | Configured identity lacks access to the table (Tables ACL, not re-implemented here) |
| 404  | Not found | Source, or table/column, does not exist |
| 409  | Config error | Tables app not installed/enabled on this instance |
| 502  | Upstream failure | Network error or non-2xx/non-4xx from the Tables API, surfaced via CallLog |

## Versioning

Unversioned (same convention as the rest of OpenConnector's REST surface —
no `/v1/` segment observed elsewhere in `appinfo/routes.php`). Additive-only
evolution expected: new optional response fields are non-breaking; any
removal of a response field is a breaking change requiring a new endpoint
path, per the Breaking Change Policy below.

## Breaking Change Policy

Since the only consumer is this same app's own frontend, breaking changes
are coordinated by shipping the backend and frontend change together in one
PR (no separate release-train coordination needed, unlike a genuine
cross-project contract). If a future change makes these endpoints consumed
by another apps-extra project, this contract MUST be promoted to the
standard cross-project review process before that consumer is built.

## SLA

Best-effort, synchronous request/response bound by the upstream Tables call
latency (itself bound by `CallService`'s existing timeout/retry
configuration — no new timeout policy introduced). Not on any availability
SLA beyond OpenConnector's own app availability; a Tables outage surfaces as
a 502 to the editor, not a hang.
