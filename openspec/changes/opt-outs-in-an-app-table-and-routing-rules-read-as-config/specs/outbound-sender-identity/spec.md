## ADDED Requirements

### Requirement: REQ-OSI-010 Opt-outs live in a table integriq owns

The system MUST store opt-outs in its own database table, written through a mapper, one row per address, scope and case. The unsubscribe link MUST write that row only after its signed token verifies, without a login, and MUST NOT write anything else. It MUST NOT write OpenRegister. Every reader of opt-outs, the send decision and the opt-out list, MUST read the table. A repair step MUST copy the existing `recipient_opt_out` objects into the table once; running it again MUST copy nothing. The `recipient_opt_out` schema stays as read-only history. The opt-out list MUST be readable by administrators only. Approved by Ruben on 2026-10-05.

#### Scenario: A recipient without an account stops a case

- **GIVEN** a valid unsubscribe link and no login
- **WHEN** the recipient follows it
- **THEN** the page answers 200 and says the updates stopped
- **AND** the table holds one row for that address and case, and OpenRegister holds nothing new
- **AND** following it again adds no second row
- @e2e exclude public link with a signed token: covered by PHPUnit and the live proof

#### Scenario: The send decision honours the table

- **GIVEN** an opt-out for an address on one case
- **WHEN** a status update for that case is decided
- **THEN** it is not sent, while an update on another case and a besluit on the same case are
- @e2e exclude no sender calls the decision yet: covered by PHPUnit and a live call of the real service

#### Scenario: An upgrade keeps the earlier opt-outs

- **GIVEN** opt-outs stored in `recipient_opt_out` before the upgrade
- **WHEN** the upgrade runs the repair step, and later runs it again
- **THEN** each is in the table once, with its date, and the second run copies nothing
- @e2e exclude repair step: covered by PHPUnit and the live proof

### Requirement: REQ-OSI-011 A new unsubscribe link expires and an old one still works

A new link MUST carry its expiry inside the signed claims, with a signature that covers the format prefix, so that a signature of one format never verifies as the other. The expiry MUST default to one year and MAY be set per instance. An expired link MUST answer 410 and change nothing. A link that does not verify MUST answer 400 and change nothing. A link in the format without expiry whose signature verifies MUST still be honoured and recorded as such.

#### Scenario: A tampered link is refused

- **GIVEN** a link whose claims were changed after signing
- **WHEN** it is followed
- **THEN** the answer is 400 and no row is added
- @e2e exclude signed token: covered by PHPUnit and the live proof

#### Scenario: An expired link is refused

- **GIVEN** a correctly signed new link past its expiry
- **WHEN** it is followed
- **THEN** the answer is 410, the page says the link expired, and no row is added
- @e2e exclude signed token: covered by PHPUnit and the live proof

#### Scenario: A link from before expiry existed still works

- **GIVEN** a correctly signed link in the old format
- **WHEN** it is followed without a login
- **THEN** the answer is 200 and the row records `unsubscribe-link-v1`
- @e2e exclude signed token: covered by PHPUnit and the live proof
