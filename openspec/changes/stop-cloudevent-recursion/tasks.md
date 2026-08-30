# Tasks: stop-cloudevent-recursion

Ordered so the bleeding stops first. Task 1 alone ends the storm; everything
after it is hardening, performance, and cleanup.

## 1. Break the loop (ship first, independently)
- [ ] 1.1 `CloudEventListener::handle()` — return early when the object's
      register is `openconnector` AND its schema is one of
      `event` / `event_message` / `event_subscription`. Resolve register+schema
      from the `ObjectEntity` (`getRegister()` / `getSchema()`), never from the
      payload, so a crafted `type` cannot bypass it.
- [ ] 1.2 Log ONE debug line per suppressed object (not per recursion level) so
      the guard is observable without recreating the flood.
- [ ] 1.3 Regression test: dispatching `ObjectCreatedEvent` for an object in
      `openconnector/event` MUST NOT call `EventService::handleObjectCreated`.
      This is the test that would have caught the bug.

## 2. Provenance guard (defence in depth)
- [ ] 2.1 Stamp CloudEvents created by `EventService` with an explicit
      generated-by marker.
- [ ] 2.2 `CloudEventListener` drops any object carrying that marker regardless
      of register/schema — survives a register rename or a same-slug schema
      copied into another register (the #2150 collision shape).
- [ ] 2.3 Test: a marked object in a DIFFERENT register is still suppressed.

## 3. Move processing off the request path
- [ ] 3.1 New `lib/BackgroundJob/ProcessEventJob.php` (QueuedJob) taking the
      event object id.
- [ ] 3.2 `handleObjectCreated` / `handleObjectUpdated` / `handleObjectDeleted`
      persist the CloudEvent and enqueue the job; they no longer call
      `processEvent()` inline.
- [ ] 3.3 Remove synchronous `deliverMessage()` from the request path — push
      delivery happens in the job, so one unreachable subscriber can never
      stall another app's write.
- [ ] 3.4 Register the job in `appinfo/info.xml` under `lib/BackgroundJob/`
      (ADR-069: one job dir, TimedJob/QueuedJob only).
- [ ] 3.5 Test: an object create enqueues exactly ONE job and creates zero
      `event_message` rows synchronously.

## 4. Stop re-querying subscriptions per event
- [ ] 4.1 `processEvent()` currently runs a `findAll` over `event_subscription`
      for EVERY event. Resolve the active set once per job run and reuse it.
- [ ] 4.2 Test asserting a single subscription query for a batch of N events.

## 5. Data remediation
- [ ] 5.1 Purge command (dry-run by default, consistent with
      `openregister:schemas:dedup`) deleting events whose `source` is
      `/objects/com.nextcloud.openregister.object.{created,updated,deleted}` —
      i.e. events generated from other events. Keep genuine ones.
- [ ] 5.2 Purge orphaned `event_message` rows whose parent event is gone.
- [ ] 5.3 Report before/after counts. Baseline on the dev instance:
      45,715 events of which 45,398 (99.3%) are self-generated.

## 6. Verification
- [ ] 6.1 `POST /apps/openregister/api/objects/<reg>/<schema>` returns in
      **< 2 s** (currently >120 s / never) and emits < 50 log lines
      (currently 6,600–68,300).
- [ ] 6.2 Exactly ONE `event` row per real object create.
- [ ] 6.3 larpingapp `crud-persistence` suite passes end-to-end, and the 24
      parked detail tests can be seeded and unparked.
- [ ] 6.4 `composer check:strict` green.
