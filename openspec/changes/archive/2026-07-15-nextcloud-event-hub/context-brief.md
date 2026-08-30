# Context Brief: nextcloud-event-hub
Source: Specter deep-research 2026-07-14 (insight #1250). VERIFY every code claim against HEAD before writing artifacts.

## Problem / Opportunity (differentiator)
Nextcloud core webhook_listeners (NC 30+) exposes events (file/folder CRUD+tags, calendar object CRUD, Forms submissions, Tables row add/update/delete, custom app events) but: registration is admin-only via OCS/CLI (no UI), delivery is background-job based (up to 5 min latency), and there are NO documented retries, HMAC signing, dead-lettering, or delivery guarantees. Nextcloud's own orchestration answer (Windmill) requires operating a second product. No App Store app fills this. OpenConnector already has: CloudEvents emit/consume, Event/EventSubscription/Consumer entities, dead-letter capture + one-click replay (EventDeliveries UI, EventRetryJob), WebhookSignatureService, and OR object lifecycle listeners.

## In scope
1. Nextcloud event triggers: subscribe OpenConnector to NC core events IN-PROCESS (PHP event listeners — no HTTP hop, no webhook_listeners dependency): file created/updated/deleted/tagged, calendar object CRUD, Tables row changes, Forms submission (verify which events are exposed as OCP events on NC 28-34; gate per-event-type availability). Selected events become Event entities that can trigger: a synchronization, a job, or an outbound webhook — configured via a new "Nextcloud event" trigger type on EventSubscription.
2. Event filter: JsonLogic condition on event payload (reuse existing JsonLogic machinery).
3. Guaranteed outbound webhook delivery: per-consumer retry policy w/ exponential backoff, HMAC signing (wire existing WebhookSignatureService into subscription delivery), delivery log, dead-letter + replay (reuse EventDeliveries), delivery-status UI per subscription.
4. Self-service: non-admin users (via action authorization matrix ADR-023) can create subscriptions for event types admins have allowed.
5. Tests: unit for filter/backoff; integration: fire NC event → assert delivery record + signature header; failure path → DLQ + replay.
## Out of scope
- Kafka/MQTT (deferred), Flow/WorkflowEngine actions (possible follow-up change), Tables as sync source/target (tables-bridge change).

## Constraints
- Reuse events-cloudevents, dead-letter-replay, webhook-signing, consumer-management specs — write deltas, don't fork machinery.
- NC version gating: Tables/Forms events only when apps installed; feature-detect, no hard dependency.
- Register schema updates via openconnector-register-schema descriptor.
