# Tasks: outbound-call-delivery-and-replay

Kind: code. Size M. Round 4 discovery cluster 27, candidates C-integrations-7,
31, 45 (three matrix holes), 12, 6, 16, 20 and 32, row 6.11. Number 8 of the
twenty-five loudest, five driven passers. Depends on `outbound-communication-log`
for the shared replay and retention rules.

## Implementation tasks

### Task 1: The call record
- **spec_ref**: `openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-every-outbound-call-is-a-record-with-its-request-and-its-response-req-ocd-001`
- **files**: `lib/Outbound/CallRecorder.php`, the record schema, the call log screen and its filters, the permission declaration
- [x] Implement (target, trace id, request as sent, response as received, status, duration; redaction before the write; filters by target, status and time; a named permission)
- [x] Test (a failed StUF call, a redacted authorization header, and a refused unpermissioned read)

### Task 2: Replay over the existing act
- **spec_ref**: `openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002`
- **files**: the call log actions, the calls into `dead-letter-replay` REQ-DLR-003 and REQ-DLR-005 and `execution-trace` REQ-006
- [x] Implement (single and bulk with per-item outcomes, a new attempt appended, the original dispatch path, the dry run of REQ-005 surfaced)
- [x] Test (a replayed delivery signed under the subscription's current secret, and a dry run that writes nothing)

### Task 3: Firing a call by hand
- **spec_ref**: `openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-a-call-can-be-fired-by-hand-req-ocd-003`
- **files**: the call log action, the subscription selection
- [x] Implement (same shape to the receiver, the principal recorded, the replay permission required)
- [x] Test

### Task 4: The retry policy
- **spec_ref**: `openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-the-retry-schedule-is-configuration-per-connection-req-ocd-004`
- **files**: the connection configuration schema, the retry scheduler, the dead-letter hand-off
- [x] Implement (retries, interval and backoff per connection with an instance default; the policy recorded on the call; exhaustion lands in the dead-letter list)
- [x] Test

### Task 5: Mapping versions on a call and a replay
- **spec_ref**: `openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-a-replay-names-the-mapping-version-it-ran-under-req-ocd-005`
- **files**: the mapping version shape behind `mapping-editor-ui`, the call record, the replay dialog
- [x] Implement (a call names its version, a replay offers both and records the choice, and a mapping is snapshotted before integriq uses it). One half is NOT built here and is honest about it: integriq cannot make the editor version rather than mutate, because a mapping is saved through OpenRegister's own objects endpoint and not through an integriq controller. What integriq owns is the snapshot it takes before it uses one, which is what makes a replay under the recorded version real rather than nominal. Enforcing versioning at the editor belongs to `mapping-editor-ui` and to OpenRegister.
- [x] Test

### Task 6: External verdicts
- **spec_ref**: `openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-an-external-verdict-is-recorded-against-the-record-it-judges-req-ocd-006`
- **files**: `lib/Controller/VerdictController.php`, `appinfo/routes.php`, the verdict store
- [x] Implement (`pass`, `fail`, `pending` with a source and a reason; readable by the owning app; the judged object untouched)
- [ ] Test (Newman against the endpoint; the endpoint and its signature refusal are covered by PHPUnit here, the Newman collection is deferred to the quality sweep)

### Task 7: The blocking pre-check
- **spec_ref**: `openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-a-blocking-pre-check-asks-an-outside-system-and-reports-the-answer-req-ocd-007`
- **files**: `lib/Outbound/PreCheckService.php`, the per-act configuration
- [x] Implement (`allow`, `refuse`, `no answer`; a configured timeout that never reports `allow`; the attempt recorded)
- [x] Test (a refusal with a reason, and a timeout)

### Task 8: Coordination, docs and the hand-offs
- **files**: `docs/`, Dutch and English strings, this change's row in `competitor-parity-2026-09`
- [ ] Tell dossiq that `lib/BackgroundJob/StufRetryJob.php` becomes a caller of the policy rather than a policy of its own
- [ ] Tell dossiq how to filter the call log to one case, and that a verdict and a pre-check answer are facts, not acts
- [x] Record C-integrations-6 as answered by `synchronization-engine` and C-integrations-32 as belonging to the Nextcloud platform programme under D9
- [x] Test (`tests/e2e/outbound-call-log.spec.ts`, `tests/e2e/outbound-call-retry-policy.spec.ts`, `openspec validate outbound-call-delivery-and-replay --type change --strict`)
