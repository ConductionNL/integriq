# Tasks: lock-down-mail-schema-reads

## Implementation Tasks

### Task 1: Close the nine schemas with a register.d lockdown fragment
- **spec_ref**: `openspec/changes/lock-down-mail-schema-reads/specs/mail-intake/spec.md#requirement-a-message-is-not-readable-by-everyone-who-can-log-in-req-mail-010`
- **files**: `lib/Settings/register.d/99-mail-schemas-lockdown.json`
- **acceptance_criteria**:
  - `digitalPostMessage`, `intake_message`, `intake_routing_rule`, `mail_message`, `mapping_version`, `outbound_message`, `recipient_key`, `recipient_opt_out` and `verdict` each gain an `authorization` block
  - The block is NON-EMPTY and its rule lists ARE empty — `"authorization": {}` is default-OPEN and closes nothing, so it must not be used
  - `create`, `read`, `update`, `delete` and `destroy` are all declared, so no action falls through to the default
  - The base register JSON is untouched; the declaration arrives by fragment merge
  - The `_comment` records the finding, the empty-block-versus-empty-list distinction with its `PermissionHandler` line numbers, that this is an interim rather than an access model, and that polled mail is owned by the system user so the owner bypass does not help a real person
- [x] Implement
- [x] Test

### Task 2: Detect the omission, so it cannot recur silently
- **spec_ref**: `openspec/changes/lock-down-mail-schema-reads/specs/mail-intake/spec.md#requirement-a-message-is-not-readable-by-everyone-who-can-log-in-req-mail-010`
- **files**: `tests/Unit/Settings/MailSchemaLockdownTest.php`
- **acceptance_criteria**:
  - Asserts all TEN reviewed schemas (the nine plus `sender_identity`) are closed, reading the base register merged with `register.d` exactly as `InitializeRegister` merges them
  - Asserts the block is not `{}` — mutation-verified: setting `mail_message` to an empty block makes the suite fail with a message naming the mistake
  - Asserts every action is declared and every rule list is empty
  - Asserts the declaration lives in a fragment, not the base register, so a register regeneration cannot quietly drop it
  - Asserts each lockdown fragment carries a `_comment` naming the finding — an undocumented authorization choice is indistinguishable from an oversight, which is how this shipped
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [ ] Manual testing against acceptance criteria — for the contributor, on a live instance
- [x] Code review against spec requirements

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`)
- [ ] Newman/Postman tests for new/changed API endpoints — N/A, no endpoint changes; the effect is on the generic OpenRegister object API this app does not own
- [ ] Vitest tests for new/changed frontend logic — N/A, no frontend change
- [ ] Playwright e2e for new/changed user journeys — N/A, asserting an absence of rows for a non-admin needs a seeded multi-user instance; the declaration is asserted structurally instead

## Notes

The test asserting the block is **not** `{}` is the one that matters. A test
asserting only that an `authorization` block exists would pass against the exact
bug this change fixes, because an empty block and an absent block take the same
default-OPEN branch in `PermissionHandler`. It was mutation-verified rather than
assumed correct.

What this deliberately does not do: decide who should read a `mail_message`. After
this change the Mail intake page shows polled messages to administrators only,
because `SaveObject::applyOwnerAttribution()` stamps the system user id when there
is no session and `MailboxSourceHandler` runs sessionless. That is a usability
regression accepted for this beta against a disclosure that was worse.
