# Contract: integriq-adapter-uwlr-eduv

## Consumers

- `learniq`: dispatches a `uwlr`/`edu-v`/`basispoort`/`entree-content`
  DataExchangeJob run by calling the matching `POST /api/uwlr-eduv/*`
  endpoint; subscribes to `UwlrEduVAcknowledgementReceivedEvent`.

## Endpoints

### `POST /api/uwlr-eduv/uwlr`
**Auth**: Nextcloud session, `#[NoAdminRequired]`.

**Request:**
```json
{
  "kenmerk": "<caller-generated correlation id>",
  "subtype": "pupil",
  "payload": { "eckId": "<pseudonymous pupil id>", "schoolBrin": "12AB" }
}
```
`subtype` is one of `pupil`, `group`, `teacher`.

**Response (200):**
```json
{ "ref": "MOCK-UWLREDUV-1", "target": "uwlr", "status": "sent" }
```

### `POST /api/uwlr-eduv/edu-v`
**Auth**: Nextcloud session, `#[NoAdminRequired]`.

**Request:**
```json
{
  "kenmerk": "<caller-generated correlation id>",
  "dataService": "onderwijsdeelnemers",
  "payload": { "eckId": "<pseudonymous pupil id>", "schoolBrin": "12AB" }
}
```
`dataService` is one of `onderwijsdeelnemers`, `onderwijsgroepen`,
`onderwijsmedewerkers`.

**Response (200):**
```json
{ "ref": "MOCK-UWLREDUV-2", "target": "edu-v", "status": "sent" }
```

### `POST /api/uwlr-eduv/basispoort`
**Auth**: Nextcloud session, `#[NoAdminRequired]`.

**Request:**
```json
{
  "kenmerk": "<caller-generated correlation id>",
  "payload": { "eckId": "<pseudonymous pupil id>", "schoolBrin": "12AB", "ssoAudience": "<method-or-publisher-id>" }
}
```

**Response (200):**
```json
{ "ref": "MOCK-UWLREDUV-3", "target": "basispoort", "status": "sent" }
```

### `POST /api/uwlr-eduv/entree-content`
**Auth**: Nextcloud session, `#[NoAdminRequired]`.

**Request:**
```json
{
  "kenmerk": "<caller-generated correlation id>",
  "payload": { "eckId": "<pseudonymous pupil id>", "schoolBrin": "12AB", "ssoAudience": "<publisher-id>" }
}
```

**Response (200):**
```json
{ "ref": "MOCK-UWLREDUV-4", "target": "entree-content", "status": "sent" }
```

### `POST /api/uwlr-eduv/retour`
**Auth**: unauthenticated, HMAC-signed (`#[PublicPage]` + `WebhookSignatureService`) — shared acknowledgement leg for all four targets above.

**Request:** raw acknowledgement envelope XML, naming the originating `kenmerk`.

**Response (200):**
```json
{ "received": true }
```

**Errors (all five endpoints):**
| Code | Condition |
|------|-----------|
| 400  | missing `kenmerk`, or a required field for the chosen target/subtype |
| 401  | missing or invalid HMAC signature (`/retour` only) |
| 503  | `not_configured` — no active `type=uwlr-eduv` source, or `uwlr-eduv` provider selected without a certificate reference |

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 400  | Bad request | required field missing before any envelope is built |
| 401  | Unauthorized | inbound signature verification failed on `/retour` |
| 503  | Not configured | no active `type=uwlr-eduv` source, or the live provider selected without a resolvable certificate reference |

## Versioning

v1, unversioned paths (`/api/uwlr-eduv/*`), same convention as the other
three adapters in this lane.

## Breaking Change Policy

Any change to the four request payload shapes above is announced before
merge — they are designed directly against `uwlr-eduv-basispoort-contract`'s
`DataMappingProfile` seed field names, so a shift there requires a
matching follow-up here (see proposal.md Risk 2).

## SLA

Best-effort, matching the other three adapters in this lane. The `log`
binding responds synchronously with no network call.
