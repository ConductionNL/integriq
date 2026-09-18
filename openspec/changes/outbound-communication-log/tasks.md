# Tasks: outbound-communication-log

Kind: code. Size M. Round 4 discovery cluster 23, candidates C-communication-33,
57, 5, 58, 56, 39, 10 and 40, rows 6.11, 6.20 and 6.23, three matrix holes.
Waits on nothing. Cluster 60, outbound sender identity, is deferred under D12
and adds a field to this record when it lands.

## Implementation tasks

### Task 1: The message record and its recipients
- **spec_ref**: `openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-every-outbound-message-is-recorded-per-recipient-and-per-step-req-ocl-001`
- **files**: `lib/Outbound/MessageRecorder.php`, the record schema in `lib/Settings/`, the log screen and its filters
- [x] Implement (subject reference, channel, one row per recipient, ordered steps, failure naming its step and the transport's reason, a record even when the first step fails)
- [x] Test (a partial failure across three recipients, and a pre-transport failure)

### Task 2: The body, its permission and its redaction
- **spec_ref**: `openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-the-sent-message-and-its-real-recipients-are-readable-behind-their-own-permission-req-ocl-002`
- **files**: `lib/Outbound/MessageBodyReader.php`, the permission declaration, the redaction path reused from `execution-trace` REQ-003
- [x] Implement (a permission distinct from seeing that a send happened, redaction before the write, the read itself recorded)
- [x] Test (a handler without the permission, and a credential that must not reach storage)

### Task 3: Retry over the existing replay act
- **spec_ref**: `openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-a-failed-send-is-retried-from-the-screen-and-the-retry-is-recorded-req-ocl-003`
- **files**: the log screen action, the call into `dead-letter-replay` REQ-DLR-003 and REQ-DLR-005
- [x] Implement (single and bulk, a new attempt appended to the same record, per-item outcomes, no second retry mechanism)
- [x] Test (a bulk retry where two items fail again)

### Task 4: Forwarding as its own record
- **spec_ref**: `openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-a-message-is-forwarded-onward-and-the-forwarding-is-a-record-req-ocl-004`
- **files**: `lib/Outbound/ForwardService.php`, the link on both records
- [x] Implement (body and attachments carried, a linked record, the original untouched, the link readable from both ends)
- [x] Test

### Task 5: The three delivery and read states
- **spec_ref**: `openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-delivery-and-read-status-are-recorded-where-the-transport-reports-them-and-absent-where-it-does-not-req-ocl-005`
- **files**: the recipient state shape, the per-channel capability declaration, the inbound receipt handlers
- [x] Implement (`reported`, `not reported`, `unsupported by this channel`; no inference from silence; no read inferred from a delivery)
- [x] Test (a reporting channel, a silent one, and one with no read reporting)

### Task 6: The last-contact query
- **spec_ref**: `openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-the-log-answers-when-a-recipient-was-last-told-anything-req-ocl-006`
- **files**: `lib/Outbound/LastContactQuery.php`
- [x] Implement (a query over the log, never a field written onto another app's record; a distinct never-contacted answer)
- [x] Test

### Task 7: External addresses as recipients
- **spec_ref**: `openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-an-address-outside-the-instance-is-a-first-class-recipient-req-ocl-007`
- **files**: the recipient resolution path
- [x] Implement (an address with no account, same statuses and steps, no account created, no silent drop)
- [x] Test (a refused external address leaves the other recipients unaffected)

### Task 8: Coordination, docs and the hand-offs
- **files**: `docs/`, Dutch and English strings, this change's row in `competitor-parity-2026-09`
- [ ] Hand dossiq the case-surface half: the send history on the case, and the `lastTold` field projected from the last-contact query, which the lane marks `dossiq-only 6.20`
- [ ] Hand dossiq C-communication-40, draft replies, with the reason: a draft is composed on the case surface and is not a send
- [ ] Tell the cluster 14 lane that an external address is a recipient here and a participant there
- [x] Record that cluster 60 adds sender identity as a field on this record once it is re-read under D12
- [x] Test (`tests/e2e/outbound-message-log.spec.ts`, `openspec validate outbound-communication-log --type change --strict`)
