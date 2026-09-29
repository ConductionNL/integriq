# Contract: integriq-adapter-oso

## Consumers

- `learniq`: dispatches an `oso` DataExchangeJob run (export leg) by
  calling `POST /api/oso/export`; subscribes to
  `OsoDossierReceivedEvent` (import leg, feeding `oso-inbound-contract`'s
  `OsoImportDossier`) and `OsoAcknowledgementReceivedEvent` (export
  acknowledgement).

## Endpoints

### `POST /api/oso/export`
**Auth**: Nextcloud session, `#[NoAdminRequired]`.

**Request:**
```json
{
  "kenmerk": "<caller-generated correlation id>",
  "payload": {
    "learnerEckId": "<pseudonymous pupil id>",
    "targetSchoolBrin": "12AB",
    "categories": [ { "category": "basisgegevens", "included": true, "data": {} } ],
    "attachmentRefs": ["nc:files/oso/onderwijskundig-rapport.pdf"]
  }
}
```

**Response (200):**
```json
{ "ref": "MOCK-OSO-1", "direction": "export", "status": "sent" }
```

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | missing `kenmerk`, or a required field for the export payload |
| 503  | `not_configured` — no active `type=oso` source, or `kennisnet` selected without a certificate reference |

### `POST /api/oso/import`
**Auth**: unauthenticated, HMAC-signed (`#[PublicPage]` + `WebhookSignatureService`) — Kennisnet delivering an inbound overstapdossier.

**Request:** raw OSO XML overstapdossier.

**Response (200):**
```json
{ "received": true }
```

### `POST /api/oso/retour`
**Auth**: unauthenticated, HMAC-signed — the export leg's acknowledgement.

**Request:** raw DUO-style acknowledgement envelope.

**Response (200):**
```json
{ "received": true }
```

**Errors:**
| Code | Condition |
|------|-----------|
| 401  | missing or invalid HMAC signature (both `/import` and `/retour`) |

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 400  | Bad request | required field missing before any envelope is built |
| 401  | Unauthorized | inbound signature verification failed |
| 503  | Not configured | no active `type=oso` source, or `kennisnet` selected without a resolvable certificate reference |

## Versioning

v1, unversioned paths (`/api/oso/*`), same convention as
`integriq-adapter-rod`/`integriq-adapter-verzuimloket`.

## Breaking Change Policy

Any change to `OsoDossierReceivedEvent`'s payload shape is announced before
merge — it is designed against `oso-inbound-contract`'s `OsoImportDossier`
field names directly, so a shift there requires a matching follow-up here
(see proposal.md Risk 1).

## SLA

Best-effort, matching the other two adapters in this lane. The `log`
binding responds synchronously with no network call.
