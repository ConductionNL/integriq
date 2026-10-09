# Design: synchronisation-source-destruction-purge

Kind: code. Size S. Read at integriq `development` 92f282bc and OpenRegister
`development`.

## Where it fits

| Piece | File | Today |
|---|---|---|
| Policy | `lib/Service/Ownership/DisappearancePolicy.php`, `ACCEPTED` = delete, markEnded, keepAndFlag | no purge |
| Full-run GC | `lib/Service/SynchronizationService.php:2795-2797`, :3832 `deleteInvalidObjects()`, :4099 policy branch, :4114 delete | soft delete |
| Delete | `SynchronizationService.php:4953-4959`, `updateTargetOpenRegister()` case `delete` | `deleteObject(uuid)` without `permanent` |
| Notifications | `lib/Controller/NotificatiesSubscriberController.php:259`, `lib/Service/NotificatiesSubscriberService.php:495` | re-emitted as a CloudEvent only |
| Contracts | `synchronization_contract` 1.1.0 with `originId`, `targetId`, `targetLastAction` | the link from source record to object |
| OpenRegister | `ObjectService::deleteObject(..., bool $permanent = false)` (`lib/Service/ObjectService.php:2858`), `DeleteObject.php:262-302` permanent, :363-364 soft keeps files | reused |

## D1. Purge is a fourth disappearance policy

`DisappearancePolicy` gains `PURGE = 'purge'` in `ACCEPTED`. At :4099 the
engine already branches on the policy; `purge` goes to the delete path with
a permanent flag. `updateTargetOpenRegister()` passes `permanent: true` to
`deleteObject()` when the action is `purge`, and sets `targetLastAction` to
`purge`.

A purge keeps every guard a delete has: never in incremental mode (:2795),
never on an incomplete fetch, and never past the deletion ratio (REQ-010).
A purge cannot be undone, so it inherits the strictest set, not a new one.

Rejected: making `delete` permanent. OpenRegister's docblock on
`deleteObject()` says the soft delete "is what makes a mistake recoverable"
for records, and every existing synchronization relies on that.

## D2. A destruction notice purges one object at once

Disappearance on a full run is the fallback. A source that destroys a
document can say so, and the tender asks that the purge not wait for the next
run. Two ways in:

- ZGW: when `handleInboundNotification()` receives `actie: destroy`, it also
  calls a new `SourceDestructionService::handleDestroyed()` with the
  notification's `resourceUrl`. The service finds the synchronizations whose
  source is the notification's source, and in them the contract whose
  `originId` is that URL or its last path segment.
- Any other source: a new route, `POST /api/synchronizations/{id}/destroyed`,
  `#[PublicPage]`, verified with the synchronization's webhook signature
  before the body is read, like the other signed inbound routes, carrying the
  source record's id.

Either way the service purges only when the synchronization's
`sourceConfig.onSourceDestroyed` is `purge`; otherwise it applies the
synchronization's disappearance policy to that one object. It never touches
an object without a contract, and never runs the full-run garbage collection.

Rejected: triggering a full synchronization run on the notice. A full run
reaches every object, and one destroyed document is not a reason to
reconsider the others.

## D3. The purge is recorded where it survives

A permanent delete removes the object, so a record on the object would go
with it. Each purge writes a `synchronization_contract_log` entry with the
synchronization, the contract's `originId`, the purged `targetId`, the
trigger (`fullRun` or `destructionNotice`) and the notice's reference, and
counts it in the run's `synchronization_log` result as `purged`. The contract
itself is kept with `targetId: null` and `targetLastAction: purge`, so a
re-appearing source record is not re-published without anyone noticing: it
is recreated and the run log says it came back after a purge.

## D4. A refused purge stays a refusal

OpenRegister's permanent delete can refuse, for example with
`ReferentialIntegrityException` when another object restricts it. The engine
records the refusal on the contract log and in the run's failures, and leaves
the object as it was. It never falls back to a soft delete, because that
would look like success and keep the files the tender asks to remove.

## Declarative versus imperative

The policy and `onSourceDestroyed` are declared on the synchronization's
`sourceConfig`. The purge is an act on a stored object the engine already
performs; it gains one flag.

## Seed data

No schema version change: `sourceConfig` is an object and the policy is a
value in it. The mock register's demo publication synchronization gains
`disappearancePolicy: purge` and `onSourceDestroyed: purge`, so the demo
shows the purge count on a run.

## Risks

- A purge is final. The policy is opt-in per synchronization, and the edit
  form states that purged files cannot be restored.
- A misconfigured source that reports every record destroyed. The notice path
  purges one object per notice, and the full-run path keeps the ratio guard.
- Retention law may require keeping a record that a document was destroyed.
  The contract log entry is that record, and its retention follows the
  synchronization log's own settings.
