# Tasks: one-off-and-suppressed-recipients

## Implementation tasks

### Task 1: Resolve a message's recipients into three recorded parts
- **spec_ref**: `openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-recipient-list-of-a-message-is-a-recorded-decision-req-mrs-001`
- **files**: `lib/Service/` recipient resolution, the message record of `outbound-communication-log`
- [ ] Implement (standing, added and suppressed stored separately; the transport receives the resolved list)
- [ ] Test

### Task 2: A suppressed recipient reads as suppressed, not as silent
- **spec_ref**: `openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-recipient-list-of-a-message-is-a-recorded-decision-req-mrs-001`
- **files**: the message record read surface, Dutch and English strings
- [ ] Implement
- [ ] Test

### Task 3: An addition is scoped to the message
- **spec_ref**: `openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-an-addition-applies-to-one-message-only-req-mrs-002`
- **files**: `lib/Service/` recipient resolution
- [ ] Implement
- [ ] Test (a second message for the same subject resolves without it, and nothing outside the message was written)

### Task 4: The reason on a suppression, refused when it is missing
- **spec_ref**: `openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-a-suppression-carries-a-reason-and-is-refused-without-one-req-mrs-003`
- **files**: `lib/Service/` recipient resolution, the send surface, Dutch and English strings
- [ ] Implement
- [ ] Test

### Task 5: Required recipients, enforced but not invented
- **spec_ref**: `openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-a-required-recipient-cannot-be-suppressed-req-mrs-004`
- **files**: `lib/Service/` recipient resolution, `lib/Event/DeliveryRequestedEvent.php`
- [ ] Implement (the caller's marker and its text, the refusal that repeats it)
- [ ] Test

### Task 6: The preview, running the send's own resolution
- **spec_ref**: `openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-resolved-list-is-shown-before-the-send-req-mrs-005`
- **files**: `lib/Service/`, `appinfo/routes.php`, the send surface
- [ ] Implement (no record, no transport, one code path shared with the send)
- [ ] Test

### Task 7: The decision rides the delivery request
- **spec_ref**: `openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-recipient-decision-travels-on-the-delivery-request-req-mrs-006`
- **files**: `lib/Event/DeliveryRequestedEvent.php`, `lib/EventListener/DeliveryRequestedListener.php`
- [ ] Implement (recipients, additions, suppressions and required markers on the event; a suppression of an unknown recipient refused)
- [ ] Test

## Verification

- [ ] `openspec validate one-off-and-suppressed-recipients --strict` passes
- [ ] PHPUnit run in the container, exit code read rather than the summary line
- [ ] A send with one addition and one suppression, read back from the record with the reason intact

## Handover

- [ ] Hand dossiq its half: the send screen where a handler adds or suppresses a recipient and types the reason, the standing recipients with the statutory ones marked required on the delivery request, and the resolved list rendered on the case timeline entry
- [ ] Record the row 6.23 closure in `openspec/changes/competitor-parity-2026-09/proposal.md` when this change is archived
