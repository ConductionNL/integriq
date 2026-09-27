# Design: connectors-inavigator-case-types

Kind: code. Size M. Read at `development` 92f282bc.

## Where it fits

| Piece | File | Today |
|---|---|---|
| Catalogi set | `lib/Settings/configurations/zgw-catalogi.json` | names `zgw-catalogi-pull` and `zgw-zaaktype-to-object`, defined nowhere |
| Set guard | `lib/Service/Zgw/ZgwSetCatalogue.php:47` `SETS`, `lib/Service/Zgw/ZgwSetInstallGuard.php` | no caller outside the guard itself |
| Sync engine | `lib/Service/SynchronizationService.php:2316` (`isTest`), :2436 to :2472 (the approval gate) | pauses with counts only |
| Approval | `lib/Service/ApprovalService.php:227` `suspendForSynchronization()`, `snapshot: []` at :242 | no change set |
| Approval schema | `lib/Settings/register.d/hitl-approval-rule-action.json`, `approval_request` 1.0.0 with `snapshot`, `synchronizationId`, `resumeResult` | reused |
| Screen | `src/views/Approvals/ApprovalDetail.vue:50`, `lib/Controller/ApprovalsController.php:677` (`snapshotPreview`) | method and path only |

## D1. Pin the i-Navigator interface first

The tender asks that i-Navigator content "must be importable into the ZTC".
How i-Navigator publishes its content is not in anything read for this
change, and a connector built on a guessed format would pass its tests and
fail at the first municipality.

So Task 1 pins the interface against a real export or the vendor's interface
documentation and records it here before any code. Two outcomes are
designed for:

- i-Navigator publishes over the ZGW Catalogi API. Then the import is the
  `zgw-catalogi` set with i-Navigator's base URL, and this change adds only
  the attribute mapping of D2 and the preview of D3.
- i-Navigator publishes an export file. Then the source is the delivered
  file source of `migration-source-adapters` (its e2e is
  `tests/e2e/migration-file-source.spec.ts`), and the mapping reads the
  export's structure.

Either way the result is the same objects on the same target schema.

## D2. Custom attributes are properties, not a fixed list

"Unlimited case attributes" means the mapping cannot name a fixed set of
target fields. In ZGW terms a case type's custom attributes are its
`eigenschappen`. The mapping writes each one as an entry in the target case
type's `eigenschappen` list with its name, definition and format, so a new
attribute in i-Navigator arrives on the next run without a mapping edit.

Products (`producten en diensten`) map onto the case type's
`productenOfDiensten` references when the target schema has them, and are
listed as unmapped in the change set when it does not.

## D3. A gated run stores its change set

At the gate (`SynchronizationService.php:2436`), the run has fetched and
mapped every object and has not written. That is the moment the change set
is known and nothing has changed yet. Before calling
`suspendForSynchronization()`, the engine compares each mapped object with
the target object its contract points at and builds:

- `created`: mapped objects with no existing target, with their fields.
- `changed`: existing targets whose mapped fields differ, with before and
  after per field.
- `removed`: targets the deletion step of REQ-010 would remove, only when
  fetch completeness allows a deletion at all.
- `unchanged`: a count.

It stores that as the request's `snapshot`, which is `[]` today (:242), plus
a `fingerprint`, a hash over the change set. Large runs store the first 500
changed objects in full and the rest as counts, with the cut stated on the
screen.

Rejected: a separate preview endpoint that runs a test run and shows a diff.
A test run and the later real run fetch at different moments, and an
administrator would accept something other than what they saw.

## D4. Accept writes what was previewed, or asks again

`ApprovalService::resume()` re-invokes the synchronization with the approved
request. The run fetches again, rebuilds the change set and compares its
fingerprint with the stored one. When they match, the write loop runs. When
they differ, the source changed after the preview: the run writes nothing,
marks the request `superseded` in `resumeResult`, and opens a new request
with the new change set.

## D5. The approval screen shows the change set

`ApprovalDetail.vue` gains a change set section when `synchronizationId` is
set: tabs for created, changed and removed, a field diff per changed object,
and the counts. `ApprovalsController` (:677) passes the stored change set
alongside `snapshotPreview`.

## Declarative versus imperative

The i-Navigator import is configuration: a source, a synchronization with
`requiresApproval: true` and a mapping, packaged like the ZGW sets. The
change set is engine behaviour at an existing gate. No schema gains
lifecycle behaviour.

## Seed data

`approval_request` (1.0.0) gains `fingerprint`, a string, and its `snapshot`
now holds a change set for synchronization requests; it moves to 1.1.0. The
mock register gains one pending synchronization approval with a change set
of two created, one changed and one removed case type, so the approval
screen shows a real preview on a demo install. The i-Navigator source
template ships dormant, `isEnabled: false`, in mock mode.

## Risks

- Task 1 may find neither outcome of D1, for example an interface only
  reachable under a vendor contract. Then the import waits and the preview
  of D3 to D5 still ships, because it serves every gated synchronization.
- A change set for a large catalogue is big. The 500 object cut keeps the
  request readable, and the counts stay exact.
- The fingerprint makes a busy source hard to accept. That is correct for a
  case type catalogue, which changes rarely.
