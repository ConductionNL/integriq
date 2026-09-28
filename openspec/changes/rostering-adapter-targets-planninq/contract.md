# Contract: rostering-adapter-targets-planninq

Contract version: **1**.

## Consumers
- `learniq`: its `timetable-import` job asks integriq to deliver a rostering source into planninq (change `sessions-from-planninq`).
- The native exchange job runner (integriq change `learniq-exchange-jobs-native`, not merged) may call `RosterDeliveryService::deliver()` directly as the `timetable-import` handler.

This change is itself a consumer of planninq's contract v1 (`TimetableUpsertRequestedEvent`, planninq change `school-timetable-target`).

## Server-side interface (ADR-041 event)

### `OCA\Integriq\Event\RosterImportRequestedEvent`

Consumers look the class up by name, `class_exists()`-guard it, construct it with named arguments and `dispatchTyped()` it. An absent class or an unhandled event means integriq cannot deliver; the consumer reports a failed import and writes nothing of its own.

```php
new RosterImportRequestedEvent(
    sourceApp: 'learniq',
    systemId: 'roster-zermelo',
    options: [
        'groupMap' => ['3a' => '<learniq cohort uuid>'],
        'teacherMap' => ['JAN' => 'jan.devries'],
    ],
    correlationId: '<the learniq job id>',
);
```

- `systemId`: one of `roster-zermelo`, `roster-untis-oneroster`, `roster-xedule`, `roster-timeedit`.
- `options.groupMap`, `options.teacherMap`: optional; merged over the maps stored in integriq app config for that source (the delivery's entries win).

Getters: `getSourceApp()`, `getSystemId()`, `getOptions()`, `getCorrelationId()`, `isHandled()`, `getResult(): ?array`. Integriq's listener calls `setResult(array)`, which marks the event handled. It always answers, also on failure.

Result on success:
```json
{
  "contractVersion": 1,
  "status": "delivered",
  "systemId": "roster-zermelo",
  "target": "planninq",
  "flavour": "mock",
  "active": false,
  "fetched": 2,
  "planninq": { "contractVersion": 1, "sourceSystem": "roster-zermelo", "processed": 2, "created": 2, "updated": 0, "unchanged": 0, "rejected": [], "sessionIds": ["<uuid>", "<uuid>"] }
}
```

- `flavour`: `mock` or `https`, which client answered. `active`: whether the source's feature flag is on. A dormant source still delivers the mock batch, so a consumer MUST show `flavour` when it reports the import.
- `planninq`: planninq's upsert result, unchanged.

Result on failure:
```json
{"contractVersion": 1, "status": "failed", "systemId": "roster-zermelo", "target": "planninq", "errorCode": "planninq-absent", "error": "Planninq is not installed, so the timetable has nowhere to go."}
```

| `errorCode` | Condition |
|---|---|
| `unknown-source` | `systemId` is not one of the four rostering sources. |
| `fetch-failed` | The rostering client threw. |
| `planninq-absent` | `OCA\Planninq\Event\TimetableUpsertRequestedEvent` does not exist, or nothing handled it. |
| `planninq-refused` | Planninq answered with an `error` (no source system, OpenRegister unavailable). |

## Mapping presets

`lib/roster-mapping-presets.seed.json` holds one preset per source id. Each maps a planninq session field (contract v1) to a vendor field and a transform:

| Transform | Input | Output |
|---|---|---|
| `text` (default) | scalar, or a list (first element) | trimmed string |
| `datetime` | Unix seconds, or a parseable date and time | ISO 8601 with offset |
| `status` | a flag or code | `cancelled` when the value is in `cancelledValues`, else `scheduled` |

Fields a preset does not name are not sent. `cohortId` and `teacherUserId` are never read from the vendor: the target configuration fills them from `groupReference` and `teacherReference`.

## Target configuration

Integriq app config, per source id:
- `roster.<systemId>.group_map`: JSON object, school group code to cohort id.
- `roster.<systemId>.teacher_map`: JSON object, school teacher code to Nextcloud user id.

An unreadable value counts as an empty map and is logged.

## Versioning
Contract version 1. Additive keys keep version 1; consumers ignore unknown keys.

## Breaking Change Policy
A renamed or removed constructor argument, getter, result key or error code bumps the version and ships a new event class beside the old one for one release.

## SLA
In process. One delivery reads one batch from the client and dispatches one planninq event.
