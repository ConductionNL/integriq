# Design: sync-object-error-isolation

## Architecture Overview

The synchronization run in `SynchronizationService::synchronize()` fetches the
full source object list, then iterates it in a single `foreach` loop
(`lib/Service/SynchronizationService.php`, around line 1434). Each iteration
calls `processSynchronizationObject(...)`, whose call path reaches OpenRegister's
`saveObject` and can throw
`OCA\OpenRegister\Exception\ValidationException` when a source object is missing
a schema-required field.

Currently that exception is uncaught inside the loop, so it unwinds through the
`foreach` and aborts the entire run (surfacing as a 500). This change adds a
fault boundary at the single-iteration level:

```
foreach ($objectList as $object) {
    try {
        $processResult = $this->processSynchronizationObject(...);
        // existing result-handling stays inside the try
    } catch (ValidationException $e) {
        // log(error) + errors[] + objects.failed++ + continue
    } catch (\Throwable $e) {
        // same recording + continue (fallback)
    }
}
```

The full-list-before-loop fetch model is preserved unchanged — no internal
batching or re-fetch is introduced.

## Nextcloud Integration
- Controllers: none.
- Services: `lib/Service/SynchronizationService.php` — the per-object loop and
  the `objects` result-map seed (around lines 1434 and 1634).
- Mappers/Entities: none.
- Events/Hooks: none. Logging uses the injected `Psr\Log\LoggerInterface`
  (`$this->logger`) already present on the service.
- Caught exception type: `OCA\OpenRegister\Exception\ValidationException` (must
  be imported / referenced by FQCN in the service).

## Security Considerations

No security impact. The change catches and logs exceptions and records
diagnostic entries; it does not alter authentication, authorization, input
trust boundaries, or data exposure. Logged/recorded fields are limited to the
object's originId and the exception message — no credentials or full object
payloads are added to logs.

## File Structure
```
lib/
  Service/
    SynchronizationService.php   # try/catch around per-object processing;
                                 # seed objects.failed; append errors[]
tests/
  Unit/
    Service/
      SynchronizationServiceTest.php   # error-isolation unit tests (new or extended)
```

## Decisions

### Decision 1: Catch ValidationException specifically, with a \Throwable fallback

Catching `ValidationException` first yields a precise "object did not conform to
its schema" diagnostic, matching the observed root cause. A general `\Throwable`
fallback guarantees that no per-object error — even an unexpected one — can
escape the loop and abort the run. Alternative considered: catch only
`\Throwable`. Rejected because it loses the ability to phrase a schema-specific
message and to reason about the common case distinctly.

### Decision 2: New `failed` bucket, distinct from `invalid`

`invalid` already means "not an array / unknown resultAction" and is incremented
on non-exception control paths. Reusing it for thrown exceptions would conflate
two different failure modes and mislead operators. A dedicated `failed` counter,
seeded alongside the existing counters at the result-map initialization (line
~1634), keeps the two orthogonal. Alternative considered: reuse `invalid`.
Rejected for the reason above.

### Decision 3: Keep the full-list fetch model; no internal batching

Zaaksysteem provides no consistent ordering, so splitting the load into
fetch-page/process/fetch-next-page risks missing or double-syncing objects.
Error isolation is achieved purely by containing the exception per iteration,
not by changing how the list is fetched.

## Risks / Trade-offs

- [Silent data loss if failures are ignored] → Every failure is logged at error
  level with originId + synchronizationId and surfaced in the run result
  (`errors[]` + `objects.failed`), giving operators a triage signal.
- [Over-broad `\Throwable` catch could mask systemic failures] → The full
  exception is logged for every failed object, so a systemic problem (e.g.
  target unreachable) still appears in the logs per object rather than being
  swallowed; only the run-abort behavior changes, not visibility.

## Migration Plan

No database or schema migration. The change is additive and localized to
`SynchronizationService.php`. Deploy is a code update; rollback is reverting the
try/catch wrapper and the counter/array seeding, which restores the prior
fail-fast behavior.

## Trade-offs

The run now completes "partially" when some objects fail, which is a behavioral
change from all-or-nothing. This is the intended trade-off: partial progress
plus an explicit failure record is strictly more useful than aborting the whole
dataset on one bad object. Consumers reading the result map must tolerate the
new `objects.failed` key and `errors[]` array; no existing key changes meaning.