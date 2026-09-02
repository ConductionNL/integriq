# Design: HITL approvals on the shared task service

## D-1: a mirror, not a migration

The approval_request record keeps owning suspend/resume orchestration; the
shared task mirrors its human-facing half (who must act, by when, with what
consequence). Four callers compose resume orchestration around
`ApprovalService` today; moving the record itself is follow-up work with its
own change. The mirror gives the fleet inbox, notification and expiry
machinery a real row NOW without touching any resume path.

## D-2: the mirror is created on the trusted path

`TaskService::import()` (in-process, trusted) rather than `create()`: the
mirror names a requester that is not the acting identity, and it is created
by a service, not over HTTP. The acting identity passed is the requester
when known, else the app id.

## D-3: OpenRegister owns the mirror's expiry

The mirrored task carries `expiresAt` and the record's `onTimeout` (when it
is one of `skip`/`error`/`dead_letter`), so the shared timer sweep closes it
with the declared behaviour. integriq's `ApprovalTimeoutSweepJob` keeps
resolving the approval_request itself. The two sweeps run at the same 300s
cadence and both are idempotent, so the pair converges without coordination:
the record ends `expired`/`dead_letter`, the task ends through its declared
behaviour. Retiring the app-local sweep for mirrored rows is follow-up 1.

## D-4: decisions close the mirror through the outcome path

`completeApproval()` closes the mirror with `transition:approved`;
`reject()` with `transition:rejected`, or `dead_letter` when the record's
`onReject` routed the record there. The outcome path
(`applyTimerOutcome()`) is chosen over `complete()` because the mirror has
no assignee: the decision was authorized by integriq's own two-layer model
(action matrix + approver group) before the close, and re-running the task
service's assignee check against a pooled mirror would refuse a decision
that already happened. The source names the deciding user
(`integriq:<uid>`).

## D-5: the mirror never gates the approval flow

Creation, linking and closing of the mirror are each wrapped: a failure is
logged as a warning and the approval flow proceeds. A missing `taskUuid`
(pre-seam rows, or a failed mirror) simply means no mirror to close. The
inverse guarantee is OpenRegister's: `applyTimerOutcome()` on an
already-terminal task returns it unchanged, so a decision racing the shared
sweep cannot double-close.

## D-6: tests stub the real signatures

integriq's suite runs without the OpenRegister app. The stubs added for
`OCA\OpenRegister\Service\Task\TaskService` and `OCA\OpenRegister\Db\Task`
copy the REAL signatures (`import(array $data, ?string $actor): Task`,
`applyTimerOutcome(string $uuid, string $outcome, string $source, string
$reason): Task`), because a fake that agrees with the caller cannot fail.
