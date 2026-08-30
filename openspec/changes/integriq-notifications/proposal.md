---
kind: config
depends_on: []
---

# integriq — schema-declared notifications

## Why

Integriq is the fleet's integration gateway / sync hub. Its strongest
notification fit is **operational failure alerting**: integration engineers and
ops need to know when a call, job, or synchronization fails, when an event
message exhausts its delivery retries, and when a scheduled job runs overdue.
Today none of Integriq's schemas declare any `x-openregister-notifications`,
so the OpenRegister notification engine has nothing to dispatch on. This change
declares schema-level notification rules so the engine emits push (and optional
email/activity) notifications on these operational events.

All rules use trigger types that work **today** (`created` + filter, `threshold`,
`scheduled`). None of them depend on the unshipped `updated`-field-change engine
condition, so this change carries **no** `depends_on`.

## What Changes

Add a top-level `x-openregister-notifications` key to the relevant schemas in
`lib/Settings/integriq_register.json`, using the verified engine dialect.

### `call_log` — failed API call (created + filter)

`call_log` rows are written per outgoing call; `statusCode` (integer) and
`userId` exist on the schema.

```jsonc
"x-openregister-notifications": {
  "call-failed": {
    "trigger": {"type": "created", "filter": {"statusCode": {"op": "gte", "value": 400}}},
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [
      {"kind": "field", "field": "userId"},
      {"kind": "groups", "groups": ["openconnector-ops"]}
    ],
    "subject": {
      "nl": "Mislukte API-aanroep ({{statusCode}}) op bron {{sourceId}}",
      "en": "Failed API call ({{statusCode}}) on source {{sourceId}}"
    }
  }
}
```

### `job_log` — job run error (created + filter)

`job_log` rows carry `level` (string) and `userId`. Filter on error-level rows.

```jsonc
"x-openregister-notifications": {
  "job-error": {
    "trigger": {"type": "created", "filter": {"level": {"op": "equals", "value": "ERROR"}}},
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [
      {"kind": "field", "field": "userId"},
      {"kind": "groups", "groups": ["openconnector-ops"]}
    ],
    "subject": {
      "nl": "Job-fout: {{message}}",
      "en": "Job error: {{message}}"
    }
  }
}
```

### `synchronization_log` — synchronization failure (created)

`synchronization_log` rows are written per sync run; `userId` exists. A row with
a failing `result` is the failure signal. (`result` is free-form, so the rule
fires on every sync-log row by default and ops filters in their override prefs;
if a stable failure marker is added to `result` later, tighten the filter.)

```jsonc
"x-openregister-notifications": {
  "sync-failed": {
    "trigger": {"type": "created"},
    "enabled": false,
    "channels": ["nc-notification"],
    "recipients": [
      {"kind": "field", "field": "userId"},
      {"kind": "groups", "groups": ["openconnector-ops"]}
    ],
    "subject": {
      "nl": "Synchronisatie-uitkomst: {{message}}",
      "en": "Synchronization result: {{message}}"
    }
  }
}
```

Ships **disabled by default** (`enabled: false`) because without a structured
failure marker on `result` it would fire on every sync run; ops opts in via
override prefs.

### `event_message` — delivery retries exhausted (threshold)

`event_message` carries `retryCount` (integer) and `status`. Use a `threshold`
on `retryCount` so ops is alerted when a message has been retried repeatedly.

```jsonc
"x-openregister-notifications": {
  "delivery-retries-exhausted": {
    "trigger": {"type": "threshold", "aggregation": "max", "field": "retryCount", "op": "gte", "value": 5},
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [
      {"kind": "groups", "groups": ["openconnector-ops"]}
    ],
    "subject": {
      "nl": "Event-bezorging faalt herhaaldelijk (retry {{retryCount}})",
      "en": "Event delivery failing repeatedly (retry {{retryCount}})"
    }
  }
}
```

### `job` — overdue scheduled job (scheduled)

`job` carries `nextRun`, `isEnabled`, and `userId`. A `scheduled` rule (interval
>= 60s) periodically checks for enabled jobs whose `nextRun` is in the past.

```jsonc
"x-openregister-notifications": {
  "job-overdue": {
    "trigger": {"type": "scheduled", "intervalSec": 3600, "filter": {"isEnabled": {"op": "equals", "value": true}, "nextRun": {"op": "lt", "value": "now"}}},
    "enabled": false,
    "channels": ["nc-notification"],
    "recipients": [
      {"kind": "field", "field": "userId"},
      {"kind": "groups", "groups": ["openconnector-ops"]}
    ],
    "subject": {
      "nl": "Geplande job loopt achter: {{name}}",
      "en": "Scheduled job overdue: {{name}}"
    }
  }
}
```

Ships **disabled by default** because the `scheduled` filter's `"now"`-relative
date comparison support in the engine should be confirmed before it is enabled;
see Caveats.

## Capabilities

### New Capabilities
- `openconnector-notifications`: declarative schema-level notification rules on
  the operational log + job + event-message schemas, consumed by the OpenRegister
  notification engine, surfacing call/job/sync failures, exhausted event-delivery
  retries, and overdue scheduled jobs to the triggering user and the integration
  ops group.

## Impact

- **File:** `lib/Settings/integriq_register.json` — adds `x-openregister-notifications`
  blocks to `call_log`, `job_log`, `synchronization_log`, `event_message`, `job`.
- The OpenRegister notification engine (schema-rule source + override-only
  user-config prefs, shipped in OR change `notification-schema-rules-and-userconfig-prefs`)
  consumes these blocks at runtime. No PHP/Vue changes in Integriq.
- Users **opt out** per `(schema, rule)` via override-only user-config prefs;
  schema `enabled` is only the default.
- Two rules ship **disabled by default** (`sync-failed`, `job-overdue`) — see Caveats.

## Caveats

- **No assignee/owner field on the operational schemas.** Integriq has no
  per-record "assignee". Recipients fall back to `field:userId` (the user who
  triggered the call/job/sync, where present) plus a `groups` recipient pointed
  at an `openconnector-ops` group. **That group must exist** (or be remapped to a
  real NC group) for the `groups` recipients to resolve — otherwise only the
  `userId` recipient receives the notification. `source`, `synchronization`, and
  `event_subscription` have **no** `userId`, which is why the rules live on the
  log rows / job / event_message instead.
- **No `status` enums and no named lifecycle/`transition` actions** on any
  Integriq schema. The plan's "call/job/sync failures (created+filter or
  threshold)" headline is therefore implemented via `created`+filter and
  `threshold`, not `transition`. A precise "status changed to failed" rule would
  need either named transition actions on the schema or the unshipped
  `updated`-field-change engine condition (`notification-updated-field-change-condition`).
- **`synchronization_log.result` and `call_log` have no stable failure marker**
  beyond `statusCode`. `call-failed` filters on `statusCode >= 400` (reliable);
  `sync-failed` cannot filter precisely yet, so it ships disabled.
- **`scheduled` `"now"`-relative date filtering** (`nextRun < now`) must be
  confirmed against the engine's filter evaluator before `job-overdue` is enabled.
- **External-recipient email** (partner orgs, non-uid contacts) is out of scope —
  the engine's `field` resolver resolves NC uids only.

See `hydra/openspec/fleet-notification-plan.md` (openconnector row + cross-cutting
engine-gap section) for the full analysis.
