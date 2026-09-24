# intake-channels Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- intake-channels-beyond-mail

## Purpose

Integriq turns a channel into a declared adapter with a routing rule, so a
message that is not an e-mail can open a case without a release. Round 4
discovery cluster 45, candidates C-intake-21, C-intake-3, C-intake-35,
C-intake-4, C-intake-12 and C-tasks-and-phases-31.

## ADDED Requirements

### Requirement: A channel is a declared adapter behind one contract (REQ-IC-001)

Integriq MUST offer an `IntakeChannelAdapter` contract with `receive()`,
`describe()` and `reply()`, discovered through a DI tag and keyed by channel
id, with the same collision policy as the integration registry. An adapter
MUST normalise its payload into one inbound shape carrying the channel id,
the correspondent, the text, the attachments and the raw payload. A consumer
MUST NOT contain channel-specific code.

#### Scenario: a new channel arrives as an adapter, not a release of the consumer
- GIVEN a registered channel adapter for a messaging service
- WHEN a message arrives
- THEN it is normalised into the inbound shape and handed on, and no consuming app changed
- @e2e exclude adapter dispatch; covered by PHPUnit on the registry

#### Scenario: describe says what a channel can do
- GIVEN a channel that cannot send a reply
- WHEN `describe()` is read
- THEN it says so, and the reply path reports `unsupported by this channel` rather than failing at send time
- @e2e exclude contract shape; covered by PHPUnit

#### Scenario: an unknown channel id fails loudly
- GIVEN an inbound payload naming a channel no adapter answers to
- WHEN it is processed
- THEN it fails naming the channel id and nothing is created
- @e2e exclude covered by PHPUnit on the registry

### Requirement: A routing rule maps a channel and a payload onto a case type (REQ-IC-002)

Routing MUST be configuration: a rule names a channel, an optional condition
over the normalised payload, and the target the message opens. A message
matching no rule MUST be held in a reviewable inbox with its reason and MUST
NOT be dropped and MUST NOT open a default case. Changing a rule MUST NOT
require a release.

#### Scenario: two channels land on two different case types
- GIVEN a rule sending public space reports to `melding openbare ruimte` and another sending messaging traffic to `algemene vraag`
- WHEN one message arrives on each channel
- THEN each opens the target its rule names
- e2e: `tests/e2e/intake-channels.spec.ts`

#### Scenario: an unroutable message is held, not lost
- GIVEN a message matching no rule
- WHEN it is processed
- THEN it appears in the reviewable inbox with the reason, and no case is created
- e2e: `tests/e2e/intake-channels.spec.ts`

#### Scenario: one bad message does not stop the channel
- GIVEN a batch of messages, one of which cannot be parsed
- WHEN the channel processes them
- THEN the rest are routed and the bad one is captured with its reason
- @e2e exclude per-item isolation is the engine's; covered by PHPUnit

### Requirement: A submission arrives over a signed webhook and maps to a case type (REQ-IC-003)

Integriq MUST accept a form submission posted as an object to a signed public
endpoint, verify the signature before reading the body, and route it through
REQ-IC-002. The mapping from submission fields onto the target's fields MUST
be configuration. An unsigned or wrongly signed submission MUST be rejected
before any read and MUST be recorded as rejected.

#### Scenario: an Open Formulieren submission becomes a case without code
- GIVEN a configured submission mapping for a form
- WHEN a correctly signed submission arrives
- THEN a case of the mapped type exists with the mapped field values
- @e2e exclude a signed external post; covered by Newman against the endpoint

#### Scenario: an unsigned submission is refused before it is read
- GIVEN a submission with no valid signature
- WHEN it arrives
- THEN it is rejected before the body is read, and the rejection is recorded
- @e2e exclude covered by Newman and PHPUnit on the endpoint

#### Scenario: a mapping onto a field that does not exist fails at configuration time
- GIVEN a mapping naming a target field the case type does not have
- WHEN the administrator saves it
- THEN the save is refused naming the field
- e2e: `tests/e2e/intake-channels.spec.ts`

### Requirement: A public space report carries its location and its media (REQ-IC-004)

The normalised inbound shape MUST carry an optional location and an ordered
list of media, and a channel that supplies them MUST populate both. A report
routed to a case type MUST write the location onto the target's declared
location field when one is mapped, and MUST hold the media as attachments
rather than as text.

#### Scenario: a streetlight report arrives with its coordinates and its photo
- GIVEN a public space report channel and a rule onto `melding openbare ruimte`
- WHEN a report with coordinates and two photos arrives
- THEN the case carries the location in its mapped field and both photos as attachments
- @e2e exclude an external report channel; covered by PHPUnit against a mock-mode fixture

#### Scenario: a channel without location says nothing rather than zero
- GIVEN a channel that supplies no location
- WHEN a message is routed
- THEN the location field is left unwritten, and no coordinate is invented
- @e2e exclude an absence claim; covered by PHPUnit

### Requirement: A reply goes back over the channel it arrived on (REQ-IC-005)

An inbound message MUST record its channel id and its correspondent, and a
reply to it MUST be sent through that channel's `reply()`. A channel that
cannot reply MUST report `unsupported by this channel` and MUST NOT silently
fall back to another channel. Every reply MUST be recorded by the outbound
message log.

#### Scenario: a WhatsApp message is answered on WhatsApp
- GIVEN a case opened from a messaging channel
- WHEN a handler replies from the case
- THEN the reply leaves over the same channel to the same correspondent and is recorded
- @e2e exclude an external messaging channel; covered by PHPUnit against a mock-mode fixture

#### Scenario: no silent fallback to mail
- GIVEN a channel that cannot reply
- WHEN a handler attempts a reply
- THEN the attempt reports `unsupported by this channel` and nothing is sent over another channel
- e2e: `tests/e2e/intake-channels.spec.ts`
