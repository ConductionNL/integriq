# outbound-sender-identity Specification

## Purpose

How an outbound sender identity signs and encrypts mail, and where its private key
material lives. An identity's S/MIME signing key is held in the credential broker
and the `sender_identity` object carries only a reference to it.

Synced from the archived change `enrol-sender-identity-in-credential-broker`. The
other sender-identity requirements (REQ-OSI-001, 002 and 004 onward) are defined in
the open change `outbound-sender-identity-and-deliverability` and are added here when
that change is archived; REQ-OSI-003 below is already in its brokered form.

## Requirements

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

An identity's private key material MUST be held in the credential broker and
referenced, never stored inline on the object — see REQ-OSI-013. A recipient's
public key and an identity's own certificate are public material and remain stored
and readable on the object.

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

#### Scenario: A private key is never returned and a certificate is

- **GIVEN** an identity carrying both a certificate and a signing key
- **WHEN** the identity is read over the API
- **THEN** the certificate is returned and the private key is not

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

### Requirement: REQ-OSI-010 A message that fails a send check is held for review

When a message fails a send check, such as an alignment, signing or opt-out check that does not allow a send, the system SHALL put it on hold instead of sending it or dropping it. A held message SHALL be listed with the check that stopped it. A person with the send right SHALL release it, which sends it, or withdraw it, which records it as not sent. Both acts SHALL be logged with their actor.

#### Scenario: A message with a failing check waits for a person

- **GIVEN** an identity whose send check refuses a message
- **WHEN** a sibling app hands that message over for sending
- **THEN** the message is held and listed with the reason, and nothing is sent

#### Scenario: A held message is released

- **GIVEN** a held message
- **WHEN** a person with the send right releases it
- **THEN** it is sent, and the log records the release with that person

### Requirement: REQ-OSI-011 Signing keeps working once the key is brokered

The system MUST resolve an identity's signing key through the broker at signing
time, in system context, and MUST NOT depend on the key being returned by an
RBAC-filtered read.

Where a signing attempt cannot obtain key material, the system MUST NOT send the
message as though it were signed, and the recorded reason MUST distinguish "this
identity has no key configured" from "this identity's key could not be resolved".
A message MUST NOT leave unsigned under a reason that reads as the former when the
cause was the latter.

#### Scenario: signing still obtains the key after the field is write-only
- GIVEN an identity configured to sign, whose key is held in the broker
- WHEN a message is protected
- THEN the key is obtained and the message is signed
- @e2e exclude covered by PHPUnit on the identity and security services

#### Scenario: an unresolvable key is reported as such, not as an absent one
- GIVEN an identity configured to sign, whose reference the broker cannot resolve
- WHEN a message is protected
- THEN the message is not reported as signed
- AND the recorded reason says the key could not be resolved, not that none is configured
- @e2e exclude covered by PHPUnit on the security service

### Requirement: REQ-OSI-012 An inline secret is refused where a reference belongs

The system MUST refuse a write that places private key material directly in the
reference field.

PEM material is self-identifying by its `-----BEGIN` header, so this check is
exact. The system MUST NOT attempt to classify a value as secret by entropy or by
guessing, because a false refusal on a legitimate reference is worse than the
narrow check missing an unusual format.

The refusal is enforced by a schema `pattern` on `smimePrivateKeyRef`
(`^(?!.*-----BEGIN)[^\s]*$`) rather than in application code, per ADR-031's
preference for declaring a constraint where the schema can enforce it for every
writer at once. That choice bounds what the refusal can say: a JSON-Schema
validation error names the property that FAILED, and cannot name a different
property as the one that should have been used. The requirement therefore does
not ask for that, and an earlier draft of this document did — see the note below.

There is no debug exemption. An earlier draft of this requirement granted one
"unless the instance is explicitly in a debug configuration"; nothing was ever
built for it, and it is not wanted. A debug flag that re-opens a path for pasting
a private key into a readable field is a production foot-gun whose only
beneficiary is a developer who can already use the broker locally.

#### Scenario: pasting a key where a reference belongs is refused
- WHEN a write places PEM private key material in the reference field
- THEN the write is refused by schema validation
- AND the refusal names `smimePrivateKeyRef` as the property that failed
- @e2e exclude covered by PHPUnit on the schema pattern

#### Scenario: a legitimate reference is never refused
- WHEN a write places a credential reference in the reference field
- THEN the write is accepted
- @e2e exclude covered by PHPUnit on the schema pattern

#### Scenario: an empty reference is accepted
- WHEN a write leaves the reference field empty
- THEN the write is accepted, because an identity that has not been enrolled yet
  is a normal state and not a violation
- @e2e exclude covered by PHPUnit on the schema pattern

### Requirement: REQ-OSI-013 A signing key is held in the broker, not in the register

The system MUST hold an identity's S/MIME private key in the credential broker and
MUST store only a reference to it on the `sender_identity` object. The reference
MUST be readable over the API, because an operator has to be able to see which
credential an identity points at and which to correct when signing fails. The key
material itself MUST NOT be returned by any read, including a read performed by an
administrator.

A key is moved by minting it into the broker, verifying that it resolves back
byte-for-byte identical, writing the reference, and only then clearing the inline
value — in that order. An inline value MUST NOT be cleared before the minted
credential has been verified.

#### Scenario: the key is not readable and the reference is
- GIVEN an identity whose signing key has been migrated
- WHEN the object is read over the generic object API, as an administrator
- THEN the response carries the reference
- AND the response carries no S/MIME private key material
- @e2e exclude covered by PHPUnit on the render boundary and the identity service

#### Scenario: a mint that cannot be verified changes nothing
- GIVEN an identity holding an inline signing key
- WHEN the key is minted into the broker but does not resolve back identically
- THEN the inline value is left exactly as it was
- AND no reference is written
- AND the failure names the identity
- @e2e exclude covered by PHPUnit on the migration executor

#### Scenario: an operator can tell which credential is wrong
- GIVEN an identity whose reference points at a credential the broker cannot resolve
- WHEN an operator reads the identity
- THEN the reference is visible, so the entry to repair can be identified
- @e2e exclude covered by PHPUnit on the identity service

## Non-Functional Requirements

- **Performance:** The broker is consulted once per signing attempt, not once per
  recipient. A send to many recipients under one identity resolves one credential.
- **Accessibility:** Any refusal surfaced in the sender-identity UI MUST be
  readable as a sentence naming the field, not a code.
- **Internationalization:** Operator-facing refusal and diagnostic text MUST be
  available in Dutch and English (hydra ADR-007). The `{credentialRef}` value
  itself is an identifier and is not translated.
