---
kind: code
---

# A receiver for CloudEvents from outside systems

## Why

Ruben decided on 12 Nov (decision 392) that integriq gets a CloudEvents receiver, and that it is
not built yet. Today an outside system can only POST a body to a generic Endpoint and let a Rule
act on it, or send a ZGW notification to the Notificaties callback. Nothing checks the CloudEvents
envelope or matches the event to integriq's own subscriptions. `CloudEventHttpBinding` only
renders outbound events. `cloud-event-management` REQ-CE-001 describes inbound routing and its
spec says `status: implemented`, but no code backs it.

### Matrix rows (integriq `openspec/parity/capabilities.json`)

| row | what changes |
|---|---|
| `evt-receive` | outside systems send CloudEvents to a receiver that checks them and acts on them |

## What changes

- A receiver URL per source, protected by a webhook signature or a credential, that takes
  CloudEvents 1.0 in structured and binary content mode.
- The receiver checks `specversion`, `id`, `source` and `type`, refuses a duplicate `id` from the
  same source, stores the event and hands it to `EventService::processEvent()`, so the existing
  subscriptions act on it.

## Impact

- `appinfo/routes.php`, a new receiver controller under `lib/Controller/`, a decode method in
  `lib/Broker/CloudEventHttpBinding.php`, `lib/Service/EventService.php`. Boards IqEndpoint and
  IqGebeurtenissen. Not built yet: the matrix row moves to `specified`.
