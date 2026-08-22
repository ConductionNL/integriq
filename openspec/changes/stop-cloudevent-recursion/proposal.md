# Proposal: Stop the CloudEvent recursion storm

## Why

Every OpenRegister object create currently triggers a **self-sustaining event
storm**. A create takes minutes and often never returns; the same path also
makes `POST /apps/openregister/api/objects/...` unusable fleet-wide, which is
what blocks larpingapp's `crud-persistence` suite and its 24 parked
detail tests.

### The loop

`CloudEventListener::handle()` fires on **every** `ObjectCreatedEvent` with
no guard on which register or schema the object belongs to
(`lib/EventListener/CloudEventListener.php:56-67`):

1. A user creates object **X** → `ObjectCreatedEvent`.
2. `EventService::handleObjectCreated(X)` calls
   `objectService->saveObject(register: 'openconnector', schema: 'event')`
   (`lib/Service/EventService.php:784`) — i.e. it **creates another
   OpenRegister object**, the CloudEvent **E**.
3. Creating **E** fires `ObjectCreatedEvent` again → step 2 → **E2, E3, …**

`processEvent()` then compounds it: per event it runs a `findAll` over all
active subscriptions and creates one `event_message` object per match
(`:106-110`) — and every one of those creates recurses too. For `style=push`
subscriptions it additionally performs a **synchronous HTTP delivery inside
the originating web request** (`:113-115`).

### Measured evidence (2026-07-28, dev instance)

| Signal | Value |
|---|---|
| Rows in openconnector `event` (`oc_openregister_table_65_25`) | **45,715** |
| Of those, `source = /objects/com.nextcloud.openregister.object.created` | **45,398 (99.3%)** |
| Events genuinely originating from real objects | ~317 |
| Sustained creation rate | **3,000–5,000 events/hour** |
| Log lines emitted by ONE object create | 6,600 → 68,300 (varies with depth) |
| `POST /api/objects/<reg>/<schema>` wall time | **>120 s, frequently never returns** |

The `source` field is proof: it is built as `'/objects/'.$objectData['type']`
(`:786`), so an event describing a real object yields `/objects/player`,
while an event describing **another event** yields
`/objects/com.nextcloud.openregister.object.created`. 45,398 rows carry the
latter — they were generated *from other events*.

Related defects already fixed while diagnosing this (they amplified the cost
but were not the loop): a docudesk array-to-string flood on the same write
path (6,240 warnings/create), integriq writing a UUID into the legacy
integer `eventId` column (~697 validation failures/create), `debug=true`
forcing hot-path debug logging to disk, and a stale exclusive
`openregister/audit-seal` lock costing 150 ms per audit row.

## What Changes

Four layers, cheapest and most decisive first.

1. **Never react to our own bookkeeping (the loop breaker).**
   `CloudEventListener` ignores objects whose register is `openconnector` and
   whose schema is `event` / `event_message` / `event_subscription`. This
   alone terminates the recursion. It is a guard, not a heuristic: those
   schemas exist *because of* the listener, so feeding them back in is never
   meaningful.

2. **Provenance flag as defence in depth.** CloudEvents created by the
   listener carry an explicit marker (e.g. `x-generated-by: openconnector`);
   the listener drops any object carrying it, regardless of register. This
   survives a register rename or a copy of the schema into another register —
   exactly the cross-app slug-collision shape that already bit us (#2150).

3. **Get event processing out of the request.** `handleObjectCreated` /
   `handleObjectUpdated` / `handleObjectDeleted` should enqueue a `QueuedJob`
   rather than fan out inline. An object create must not pay for subscription
   matching, message creation, or HTTP delivery. This also removes the
   synchronous push delivery, which today lets one unreachable subscriber
   stall an unrelated app's write.

4. **Stop re-querying subscriptions per event.** `processEvent` performs a
   `findAll` over `event_subscription` for every single event. Cache the
   active set for the life of the job/request.

Plus **data remediation**: purge the ~45k self-generated events (keep the
~317 genuine ones), and add a guard test so the loop cannot silently return.

## Impact

- Affected code: `lib/EventListener/CloudEventListener.php`,
  `lib/Service/EventService.php`, a new `lib/BackgroundJob/ProcessEventJob.php`,
  and a purge command or repair step.
- Affected specs: the CloudEvents/event-subscription capability spec.
- Risk: subscribers stop receiving events *synchronously*. That is the point —
  delivery becomes asynchronous and observable, instead of being charged to
  whichever app happened to write an object.
- Not in scope: the OpenRegister `SaveObject` `$ref` parser only accepting
  `#/components/schemas/<slug>` while schemas on this instance carry numeric
  `$ref` (e.g. `character.ocName` → `"$ref": 19`), which silently drops those
  relations on save. Tracked separately.
