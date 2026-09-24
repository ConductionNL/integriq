# outbound-sender-identity Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- outbound-sender-identity-and-deliverability

## Purpose

Integriq decides what a message looks like to the person who receives it:
which address it comes from, whether the receiver's checks pass, whether it
is signed, how much history it quotes, whether it can still be taken back,
and whether this recipient asked not to be written to at all. Extends the
`outbound-message-log` capability of `outbound-communication-log`.
Requested by the dossiq competitor programme, discovery cluster 61.

## ADDED Requirements

### Requirement: REQ-OSI-001 More than one outbound sender identity

The system SHALL hold named sender identities, each with a display name, an
address, an optional reply-to, an optional signature and a reference to the
Nextcloud Mail account it sends through. An outgoing message SHALL name an
identity, and the identity SHALL decide the From, Reply-To and signature
the recipient sees. Where a message names no identity, the instance default
SHALL be used. The identity SHALL be recorded on the message's row in the
outbound log.

Candidate C-communication-44, `should`, two driven passers (dimpact-zac,
znuny).

#### Scenario: Two teams send under their own address

- **GIVEN** identities "Vergunningen" and "Belastingen" on one account
- **WHEN** a case handled by Belastingen sends a letter
- **THEN** the recipient sees the Belastingen display name, address and signature
- **AND** the outbound log row names the identity

#### Scenario: A message without an identity falls back

- **GIVEN** an instance default identity
- **WHEN** a message is sent naming no identity
- **THEN** it leaves under the default, and the log records the fallback

### Requirement: REQ-OSI-002 Domain alignment is checked and reported

For each identity the system SHALL check the SPF, DKIM and DMARC records of
its sending domain and SHALL report each as aligned, misaligned or absent,
with the time of the check. Where a record is absent or misaligned, the
system SHALL print the exact DNS record to publish. An identity with a
failing check SHALL still send, and SHALL show the risk on its screen and
on the log rows it produces.

Candidate C-communication-50, `should`, two documented passers
(easy-redmine, jira-service-management). Documented, never counted in a
driven tally (D21).

#### Scenario: An administrator is told what to publish

- **GIVEN** an identity on `gemeente.nl` with no DKIM record
- **WHEN** the administrator opens the identity
- **THEN** DKIM reads absent, with the record to publish shown in full

#### Scenario: A failing identity still sends, with the risk shown

- **GIVEN** the same identity
- **WHEN** a message is sent from it
- **THEN** the message leaves, and its log row carries the failing alignment state

### Requirement: REQ-OSI-003 Mail is signed, and encrypted where a key is known

The system SHALL administer S/MIME and PGP keys per identity, and recipient
public keys per address. An identity MAY be configured to sign every
outgoing message. Where a recipient public key is known, the message SHALL
be encrypted as well. The log SHALL record, per message, whether it was
signed, encrypted, both or neither. Signed inbound mail SHALL be verified
and its verification result SHALL be recorded.

Candidate C-communication-51, `should`, five driven passers (freescout,
otobo, request-tracker, zammad, znuny).

#### Scenario: A message to a recipient with no key is signed and not encrypted

- **GIVEN** an identity configured to sign, and a recipient with no key
- **WHEN** a message is sent
- **THEN** it is signed, not encrypted, and the log says exactly that

#### Scenario: A signed reply is verified

- **GIVEN** an inbound message signed with a known key
- **WHEN** it is processed
- **THEN** the verification result is recorded on the message

### Requirement: REQ-OSI-004 A recipient's opt-out is held once

The system SHALL hold one opt-out per recipient address per instance. Every
sender in the product SHALL check it before a send. The system SHALL hold a
declared list of protected categories that an opt-out SHALL NOT stop. A
send in a protected category to an opted-out address SHALL proceed and
SHALL be recorded as having overridden an opt-out.

Candidate C-communication-24, `should`, one driven passer (odoo).

#### Scenario: A marketing style update is not sent

- **GIVEN** an address on the opt-out list
- **WHEN** a case status update is sent to it
- **THEN** the send is suppressed and the log records the suppression and its reason

#### Scenario: A besluit is still delivered

- **GIVEN** the same address and a message in the protected category `besluit`
- **WHEN** it is sent
- **THEN** it is delivered, and the log records the override

### Requirement: REQ-OSI-005 The recipient can stop case updates from the message

Case notification mail SHALL carry an unsubscribe link bound to the
recipient and the case by a signed token. Following it SHALL add an opt-out
for that case's updates without requiring a login or an account, and SHALL
confirm what was stopped. A message in a protected category SHALL carry no
unsubscribe link.

Candidate C-communication-66, `should`, one driven passer (gitlab).

#### Scenario: A requester stops the updates on one case

- **GIVEN** a case notification with an unsubscribe link
- **WHEN** the recipient follows it
- **THEN** updates for that case stop, the page says so, and no account is created

#### Scenario: A statutory notice carries no link

- **GIVEN** a message in the protected category `besluit`
- **WHEN** it is rendered
- **THEN** it contains no unsubscribe link

### Requirement: REQ-OSI-006 How much history is quoted is chosen

Each identity SHALL carry a quoting level for outgoing mail: `none`,
`last-message` or `full-history`. The level SHALL be applied when the
message is composed, and the level used SHALL be recorded on the log row.
The default for a new identity SHALL be `last-message`.

Candidate C-communication-43, `should`, one driven passer (freescout).

#### Scenario: A reply to a bezwaarmaker quotes nothing

- **GIVEN** an identity with quoting level `none`
- **WHEN** a reply is sent on a case with twelve earlier messages
- **THEN** the outgoing message contains no quoted history
- **AND** the log row records the level `none`

### Requirement: REQ-OSI-007 A sent message can be taken back before it leaves

Each identity SHALL carry a hold window in seconds, defaulting to zero. A
message from an identity with a non-zero window SHALL be queued for that
window before the send is attempted, and SHALL be withdrawable during it by
the sender. A withdrawal SHALL be recorded on the outbound log. After the
window, the message SHALL NOT be withdrawable.

Candidate C-communication-26, `should`, one driven passer (freescout) and
one documented (youtrack).

#### Scenario: A wrong letter is pulled back in time

- **GIVEN** an identity with a 30 second hold window
- **WHEN** the sender withdraws the message after 10 seconds
- **THEN** nothing is sent and the log records the withdrawal with its actor

#### Scenario: After the window nothing can be recalled

- **GIVEN** the same message 40 seconds later
- **WHEN** the sender asks to withdraw it
- **THEN** the request is refused and says the message has left

### Requirement: REQ-OSI-008 Mail to a no-reply address is handled

An identity MAY be marked as taking no replies. Inbound mail to such an
identity SHALL be diverted to a configured address or refused, never
silently dropped. A refusal SHALL tell the sender where to write instead,
and every diversion or refusal SHALL be recorded.

Candidate C-communication-45, `could`, one driven passer (freescout).

#### Scenario: A citizen replies to a no-reply address

- **GIVEN** a no-reply identity configured to divert to the KCC mailbox
- **WHEN** a citizen replies to it
- **THEN** the message arrives in the KCC mailbox and the diversion is recorded

#### Scenario: A refusal says where to write

- **GIVEN** a no-reply identity configured to refuse
- **WHEN** a citizen replies
- **THEN** the sender receives a refusal naming the address to use

### Requirement: REQ-OSI-009 Signature and disclaimer are stripped from the timeline

Where a quoted signature or disclaimer block is detected in an inbound
message, the timeline entry SHALL show the message without it. The original
message SHALL be kept whole in the outbound and inbound logs, and the
timeline SHALL offer the original on demand. Stripping SHALL never remove
content outside a detected signature or disclaimer block.

Candidate C-communication-65, `could`, one documented passer
(jira-service-management). Documented, never counted in a driven tally
(D21).

#### Scenario: A four line corporate disclaimer is not in the timeline

- **GIVEN** an inbound reply ending in a standard disclaimer block
- **WHEN** the timeline entry is rendered
- **THEN** the disclaimer is absent from the entry
- **AND** the stored message still contains it, and the entry offers the original
