# outbound-sender-identity Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:
- outbound-sender-identity-and-deliverability
- enrol-sender-identity-in-credential-broker

## Purpose

An identity's S/MIME signing key is the organisation's ability to sign mail as
itself. This delta moves that key out of the register and into the credential
broker, leaving behind a reference an operator can read, so that custody of the
key stops depending on nobody reading a schema they are permitted to read.

## ADDED Requirements

### Requirement: REQ-OSI-010 A signing key is held in the broker, not in the register

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

### Requirement: REQ-OSI-012 An inline secret is refused outside debug

The system MUST refuse a write that places private key material directly in the
reference field, unless the instance is explicitly in a debug configuration. The
refusal MUST name the field that should have been used.

PEM material is self-identifying by its `-----BEGIN` header, so this check is
exact. The system MUST NOT attempt to classify a value as secret by entropy or by
guessing, because a false refusal on a legitimate reference is worse than the
narrow check missing an unusual format.

#### Scenario: pasting a key where a reference belongs is refused
- GIVEN an instance not in debug configuration
- WHEN a write places PEM private key material in the reference field
- THEN the write is refused
- AND the refusal names the field that accepts key material
- @e2e exclude covered by PHPUnit on the write guard

#### Scenario: a legitimate reference is never refused
- GIVEN an instance not in debug configuration
- WHEN a write places a credential reference in the reference field
- THEN the write is accepted
- @e2e exclude covered by PHPUnit on the write guard

## MODIFIED Requirements

### Requirement: REQ-OSI-003 Mail is signed, and encrypted where a key is known

The system SHALL administer S/MIME and PGP keys per identity, and recipient
public keys per address. An identity MAY be configured to sign every
outgoing message. Where a recipient public key is known, the message SHALL
be encrypted as well. The log SHALL record, per message, whether it was
signed, encrypted, both or neither. Signed inbound mail SHALL be verified
and its verification result SHALL be recorded.

An identity's private key material MUST be held in the credential broker and
referenced, never stored inline on the object — see REQ-OSI-010. A recipient's
public key and an identity's own certificate are public material and remain stored
and readable on the object.

Candidate C-communication-51, `should`, five driven passers (freescout,
otobo, request-tracker, zammad, znuny).

<!-- Previous behavior: the requirement said only that keys are "administered per
     identity", which the implementation satisfied by storing the S/MIME private
     key as an ordinary inline string property. Nothing in the requirement
     distinguished public material from private, so a readable private key
     satisfied it. -->

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

## Non-Functional Requirements

- **Performance:** The broker is consulted once per signing attempt, not once per
  recipient. A send to many recipients under one identity resolves one credential.
- **Accessibility:** Any refusal surfaced in the sender-identity UI MUST be
  readable as a sentence naming the field, not a code.
- **Internationalization:** Operator-facing refusal and diagnostic text MUST be
  available in Dutch and English (hydra ADR-007). The `{credentialRef}` value
  itself is an identifier and is not translated.

## Acceptance Criteria

- A read of a migrated identity returns the reference and no private key material, administrator included
- A mint that does not verify byte-for-byte leaves the inline value untouched and writes no reference
- Signing obtains key material through the broker after the inline field is marked write-only
- A failure to resolve a reference is recorded distinguishably from an identity that has no key configured
- A write placing PEM material in the reference field is refused outside debug, naming the correct field
- A write placing a genuine reference is accepted

## Notes

- The ordering in REQ-OSI-010 is the load-bearing part. `InlineSecretMigrationExecutor`
  already implements mint → verify → write ref → null for `source`; this requirement
  states it so the sequence cannot be reordered by a later refactor that finds the
  verification step redundant.
- REQ-OSI-011's second scenario exists because of a specific failure shape.
  `OutboundSecurityService::protect()` reports *"This identity is set to sign but
  carries no S/MIME certificate and key"* whenever the key string is empty. If the
  write-only marker lands without a system-context read, that message becomes the
  symptom of a stripped field, and mail goes out unsigned under a reason that reads
  like an unconfigured identity. The two causes must not share a message.
- The certificate (`smimeCertificate`) deliberately stays inline and readable. It is
  public material, and treating it as a secret would break the operator's ability to
  inspect what the identity signs with.
