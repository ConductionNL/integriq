# mail-intake Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:
- mail-intake-creates-cases
- lock-down-mail-schema-reads

## Purpose

A mailbox is a source and a message is an object. This delta closes the gap that
made every such object readable by every authenticated account: the schemas were
declared without an `authorization` block, and in OpenRegister an absent block
grants reads rather than denying them.

## ADDED Requirements

### Requirement: A message is not readable by everyone who can log in (REQ-MAIL-010)

Integriq MUST declare an `authorization` block that denies reads by default on
every schema holding message content, routing configuration or recipient key
material. A schema MUST NOT rely on instance configuration to become private, because
OpenRegister's `enforce_default_closed` setting governs only `create`, `update`,
`delete` and `destroy` — never `read`.

The block MUST be declared as a non-empty object whose rule lists are empty. An
empty block (`{}`) is NOT equivalent and MUST NOT be used: it is indistinguishable
from an absent block and leaves the schema open.

Reads MUST remain available to administrators, and to the owner of an individual
object, both of which are evaluated before the declared rule lists.

#### Scenario: an ordinary account cannot read an intercepted message
- GIVEN an account that is not an administrator and owns no messages
- WHEN it reads `mail_message` over the generic object API
- THEN no message is returned
- @e2e exclude covered by PHPUnit asserting the declared blocks

#### Scenario: an administrator can still read
- GIVEN an administrator
- WHEN it reads `mail_message`
- THEN messages are returned, because the admin check precedes the rule lists
- @e2e exclude covered by PHPUnit on PermissionHandler's documented precedence

#### Scenario: the declaration is a rule list, not an empty block
- GIVEN any schema this requirement covers
- WHEN its declared `authorization` block is inspected
- THEN the block is non-empty
- AND every rule list it declares is present and empty
- @e2e exclude a structural claim about a declaration; covered by PHPUnit

#### Scenario: a schema added later is not silently open
- GIVEN a schema holding message content added after this change
- WHEN it declares no `authorization` block
- THEN the omission is detectable rather than defaulting to readable
- @e2e exclude covered by the fragment coverage test

## Non-Functional Requirements

- **Performance:** No change. Authorization is evaluated from the schema
  declaration already loaded for validation; no additional query is introduced.
- **Accessibility:** Not applicable — no UI is added. See the note below about
  what a non-administrator sees.
- **Internationalization:** No user-facing string is added by this change.

## Acceptance Criteria

- Each of the nine schemas declares a non-empty `authorization` block
- Every declared rule list is present and empty — `"authorization": {}` fails this
- `create`, `read`, `update`, `delete` and `destroy` are all declared, so no action falls through to the default
- The base register JSON is unmodified; the declaration arrives by `register.d` merge
- The existing mail intake tests continue to pass, showing writers are unaffected

## Notes

- **What a non-administrator sees is nothing, not a refusal.** RBAC filtering on the
  list path removes rows rather than erroring, so the Mail intake page renders an
  empty table with no explanation. That is a real usability regression and is
  accepted for this beta only, because the state it replaces is every account
  reading every intercepted message.
- **Object ownership does not rescue the polled path.**
  `SaveObject::applyOwnerAttribution()` falls back to the system user id when there
  is no session, and `MailboxSourceHandler` runs sessionless. Polled messages are
  therefore owned by nobody real, and only an `.eml` imported through
  `MailIntakeController` carries a human owner. Attributing polled mail to a real
  user is one of the candidate access models and is out of scope here.
- `sender_identity` is covered by `enrol-sender-identity-in-credential-broker`
  rather than this change, because it also held a secret.
