# Contract: learniq-exchange-jobs-native

This is the interface between integriq and any app whose data exchange jobs integriq carries.
learniq is the first consumer. Everything below is additive to integriq's existing API.

## Consumers

- `learniq`: raises `ExchangeJobRequestedEvent` and `ExchangeMappingRequestedEvent`, answers
  `ExchangeGateRequestedEvent`, listens for `ExchangeJobConcludedEvent`, serves
  `GET /apps/learniq/api/exchange-gates/{jobId}`, and reads `/api/exchange/*` from its
  status panel.
- Any later app with statutory exchanges (dossiq, humaniq) can adopt the same contract by
  setting `ownerApp` to its own id.

## Vocabulary

### Targets

| `exchangeTarget` | directions | adapter | handler in this change |
|---|---|---|---|
| `bron-rod` | export | rod | yes |
| `oso` | export, import | oso | export only |
| `leerplicht` | export | verzuimloket | yes |
| `surfconext` | sync | none | no |
| `hr` | import, export | none | no |
| `swv` | export | swv | yes |
| `ooapi-catalog` | export | none (opencatalogi) | no |
| `timetable-import` | import | roster (planninq, D10) | no |
| `migration-import` | import | migration presets | no |
| `lvs-results` | import | lvs | no |
| `uwlr` | export, import | uwlr-eduv | export only |
| `edu-v` | export | uwlr-eduv | yes |
| `basispoort` | sync | uwlr-eduv | yes |
| `entree-content` | sync | uwlr-eduv | yes |

`exchangeDirection` is `export`, `import` or `sync`. A `sync` job to `basispoort` or
`entree-content` is handled as an outbound hand-off.

### Job status (`exchangeStatus`)

`queued` (created, waiting for the next scheduler pass), `running`, `succeeded`, `partial`
(some records rejected), `failed`, `refused` (the owning app's gate said no). The last four are
terminal. A migrated job keeps the terminal status it had; a migrated `pending-parent-review`
job becomes `queued` and its gate decides again.

### Rejection status (`sync_item_dead_letter.status`)

`failed` (open, needs correction), `replayed` (resubmitted; accepted unless it comes back),
`discarded` (waived, with a reason). Translation from learniq's former vocabulary, seeded as the
mapping row `learniq-exchange-rejection-status`:

| learniq `ExchangeRejection.status` | integriq status |
|---|---|
| `open` | `failed` |
| `corrected` | `failed` (with `correctedAt`) |
| `resubmitted` | `replayed` |
| `accepted` | `replayed` |
| `waived` | `discarded` |

### Error codes

Codes integriq's runner raises itself, on the job's `gateDecision` or on a rejection:

| Code | Where | Meaning |
|---|---|---|
| `gate-owner-missing` | gate | The job names no owning app. |
| `gate-app-absent` | gate | The owning app is not installed or not enabled. |
| `gate-unanswered` | gate | The owning app is enabled but nobody answered the gate. |
| `gate-error` | gate | The owning app's gate listener threw. |
| `gate-refused` | gate | Fallback when the owning app refused without a code. |
| `no-handler` | job | No handler for this target and direction. |
| `mapping-missing` | job | The job names a mapping slug that does not exist. |
| `mapping-failed` | rejection | The mapping threw on this record. |
| `translation-failed` | rejection | The adapter refused the record's fields. |
| `send-failed` | rejection | The adapter could not send the record. |
| `source-missing` | job | The handler needs a scope value (such as `receiverId`) the job lacks. |

Target codes (DUO's own afkeurcodes and the like) are seeded per target as mapping rows
`learniq-exchange-error-codes-<target>`. Their `mapping` object is keyed by code, each value
`{label, labelEn, category, severity}`. These rows are catalogues, not transformations.

## Events (ADR-041, in process)

All four live in `OCA\Integriq\Event\`. A consumer MUST `class_exists()`-guard the FQCN and
treat an absent class as integriq absent.

### `ExchangeJobRequestedEvent`: an app asks integriq to carry a job

Raised by the owning app. Integriq's listener creates the job and fills the result slot.

| Constructor argument | Type | Notes |
|---|---|---|
| `ownerApp` | string | The asking app's id, `learniq`. Required. |
| `target` | string | One of the targets above. Required. |
| `direction` | string | `export`, `import` or `sync`. Required. |
| `ownerRef` | string | The owning app's reference for what the job is about, such as `attendance-flag/<uuid>`. Opaque to integriq. |
| `scope` | array | Selectors only, never personal data: `schema`, `filters`, `cohortId`, `period`, `recordIds`, and target parameters `berichtsoort`, `meldingType`, `subtype`, `dataService`, `receiverId`. |
| `mappingSlug` | ?string | Slug of a `mapping` row, such as `learniq-bron-rod-export-learner`. |
| `requestedBy` | string | Nextcloud user id. |
| `name` | string | Human label for the job list. |
| `history` | ?array | Migration only. `{legacyId, status, requestedAt, startedAt, finishedAt, result, errorMessage, rejections[]}`. A job whose history is finished is created disabled and never runs; a job that was still waiting is created `queued` and its gate decides again. |

Result slot: `getJobId(): ?string` after `setJobId()`, or `getRefusal(): ?array`
(`{code, reason}`) after `refuse()`. Refusal codes: `target-unknown`, `direction-unsupported`,
`owner-missing`, `mapping-missing`, `store-failed`. A migrated job whose `legacyId` was already
migrated returns the existing job id instead of a second row.

### `ExchangeMappingRequestedEvent`: an app upserts its own mapping

| Constructor argument | Type | Notes |
|---|---|---|
| `ownerApp` | string | Required. |
| `slug` | string | Must start with `<ownerApp>-`. Upserted by slug. |
| `name` | string | |
| `description` | string | |
| `mapping` | array | Integriq mapping rules: output key to source path or Twig template. |
| `cast`, `unset`, `passThrough` | array, array, bool | As on the `mapping` schema. |

Result slot: `getMappingId()` or `getRefusal()` (`slug-foreign`, `mapping-empty`,
`store-failed`).

### `ExchangeGateRequestedEvent`: integriq asks the owning app

Raised by integriq's runner right before a job runs. Answered by the owning app.

| Getter | Type |
|---|---|
| `getJobId()` | string |
| `getOwnerApp()` | string |
| `getTarget()`, `getDirection()` | string |
| `getOwnerRef()` | string |
| `getScope()` | array |

Answer, exactly one of:

- `allow(array $records = [])`: the job may run. For an export, `$records` is what may leave,
  each `{recordId: string, sourceKind: string, data: array}`. `recordId` is the owning app's id
  of the source object; `data` holds only the fields the owning app lets leave, before mapping.
- `refuse(string $code, string $reason)`: the job may not run. The reason is shown to people,
  so it is a full sentence in the owning app's language.

Integriq reads `isAnswered()`, `isAllowed()`, `getRecords()`, `getRefusal()`. It never
stores the records.

### `ExchangeJobConcludedEvent`: a job reached a terminal state

Raised by integriq after every run that ends `succeeded`, `partial`, `failed` or `refused`.

| Getter | Type |
|---|---|
| `getOwnerApp()`, `getJobId()`, `getTarget()`, `getDirection()`, `getOwnerRef()` | string |
| `getStatus()` | string |
| `getResult()` | array: `{recordsProcessed, recordsAccepted, recordsRejected, runId, artefactRef}` |
| `getGateDecision()` | ?array |
| `getErrorMessage()` | ?string |

A consumer MUST filter on `getOwnerApp()` and keep its side effect idempotent.

## The gate over HTTP (served by the owning app)

### `GET /apps/<app>/api/exchange-gates/{jobId}`

**Auth**: Nextcloud session. Served by the owning app, which applies its own access rule.

This route is the same decision the owning app gives on the event, for people and support
tooling. Integriq's runner does not call it: a cron run has no session to call it with
(ADR-041, "Internal HTTP/OCS to the target's REST endpoint: rejected").

**Response (200):**
```json
{
  "jobId": "00000000-0000-0000-0000-000000000000",
  "decision": "refuse",
  "code": "teldatum-unconfirmed",
  "reason": "The teldatum check for 1 October is not confirmed yet.",
  "checkedAt": "2026-10-01T09:00:00+02:00"
}
```

`decision` is `allow` or `refuse`; `code` and `reason` are empty strings on allow. The records
are never part of the HTTP answer.

**Errors:**
| Code | Condition |
|------|-----------|
| 401 | No session. |
| 403 | The user may not see this job's gate. |
| 404 | No exchange job with that id is owned by this app, or integriq is absent. |

## Integriq endpoints (the read model)

All return JSON; list endpoints return `{results: [...], total: n}`. Auth: Nextcloud session
plus the named ADR-023 action (default `admin`, configurable in Admin settings > Integriq >
Action authorization).

### `GET /api/exchange/jobs`
**Auth**: `exchange.read`.
Query: `ownerApp` (required), `target`, `status`, `ownerRef`, `limit` (default 50, max 200),
`offset`.

**Response (200):**
```json
{
  "results": [
    {
      "id": "00000000-0000-0000-0000-000000000000",
      "name": "ROD inschrijving groep 3",
      "ownerApp": "learniq",
      "ownerRef": "school-advies/00000000-0000-0000-0000-000000000000",
      "target": "bron-rod",
      "targetLabel": "DUO ROD",
      "direction": "export",
      "status": "partial",
      "requestedBy": "admin",
      "requestedAt": "2026-10-01T08:00:00+02:00",
      "startedAt": "2026-10-01T08:05:00+02:00",
      "finishedAt": "2026-10-01T08:05:02+02:00",
      "result": {"recordsProcessed": 3, "recordsAccepted": 2, "recordsRejected": 1, "runId": "", "artefactRef": null},
      "gateDecision": {"decision": "allow", "code": "", "reason": "", "checkedAt": "2026-10-01T08:05:00+02:00"},
      "error": null,
      "migratedFrom": null,
      "resubmissionOf": null,
      "openRejections": 1
    }
  ],
  "total": 1
}
```

**Errors:** 400 `ownerApp` missing; 401; 403 action refused.

### `GET /api/exchange/jobs/{id}`
**Auth**: `exchange.read`. Query: `ownerApp` (required; a job owned by another app is 404).
**Response (200):** one job row as above plus `rejections: [...]` (rows as below) and
`lastLog: {level, message, created}` (the latest `job_log` line, or null).
**Errors:** 400, 401, 403, 404.

### `GET /api/exchange/rejections`
**Auth**: `exchange.read`. Query: `ownerApp` (required), `status`, `target`, `jobId`, `limit`,
`offset`.

**Response (200):**
```json
{
  "results": [
    {
      "id": "00000000-0000-0000-0000-000000000000",
      "jobId": "00000000-0000-0000-0000-000000000000",
      "target": "bron-rod",
      "status": "failed",
      "errorCode": "BRON-102",
      "errorLabel": "Ontbrekende geboortedatum",
      "errorLabelEn": "Missing birth date",
      "severity": "blocking",
      "offendingFields": ["geboorteDatum"],
      "ownerRef": "learner-profile/00000000-0000-0000-0000-000000000000",
      "sourceKind": "learner-profile",
      "correctionDeadlineAt": null,
      "discardReason": null,
      "retryCount": 0,
      "created": "2026-10-01T08:05:02+02:00"
    }
  ],
  "total": 1
}
```

### `GET /api/exchange/targets`
**Auth**: `exchange.read`. **Response (200):** `{results: [{id, label, directions, adapter, handled: {export: bool, import: bool, sync: bool}}]}`.

### `POST /api/exchange/rejections/{id}/resubmit`
**Auth**: `exchange.resubmit`. Creates a single-record job for the rejected record, marks the
rejection `replayed`. Replaying the same row from integriq's own dead letter screen does the
same. **Response (200):** `{rejectionId, jobId}`.
**Errors:** 401, 403, 404 unknown or not an exchange rejection, 409 not in `failed`.

### `POST /api/exchange/rejections/{id}/waive`
**Auth**: `exchange.waive`. Body `{reason: string}` (required, non-empty). Marks it `discarded`
with the reason, the actor and the time. **Response (200):** the rejection row.
**Errors:** 400 empty reason, 401, 403, 404, 409 already `discarded`.

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 400 | Bad request | `ownerApp` missing, empty waive reason. |
| 401 | Not authenticated | No session. |
| 403 | Forbidden | The ADR-023 action is not granted to the user. |
| 404 | Not found | Unknown id, or owned by another app. |
| 409 | Conflict | Rejection not in a state that allows the action. |

## Versioning

Event constructors, getters and answer methods, the job and dead letter property names, and the
`learniq-*` mapping slugs are the contract. Adding an optional constructor argument at the end,
a target or an error code is compatible. Renaming or removing any of them is breaking.

## Breaking Change Policy

A breaking change ships as a new event class next to the old one for one release, with a note in
both apps' changelogs, and the consumer PR lands first.

## SLA

In process: the gate and the job request are synchronous and bounded by the owning app's
listener. A job runs on the next `JobTask` pass (at most five minutes). The read model reads
at most 200 jobs per call.
