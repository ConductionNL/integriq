# notifynl-sms-channel Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- notifynl-sms-channel
- messaging-sms-providers-and-notifynl-mail

## Purpose

Integriq sends a text message through CM.com, MessageBird, Twilio or
NotifyNL behind one provider seam, lets the caller choose the source, and
sends NotifyNL e-mail through the same NotifyNL binding. Rows
`integriq:msg-sms` and `integriq:nl-notifynl`.

## ADDED Requirements

### Requirement: CM.com, MessageBird and Twilio send through the SMS provider seam (REQ-SMSP-001)

Integriq MUST offer SMS bindings with provider ids `cmcom`, `messagebird`
and `twilio`, each implementing `SmsProviderInterface`. Each MUST dispatch
through the OpenRegister credential broker from the source's
`authentication.credentialRef` and MUST fail closed when none is configured.
A send through any binding MUST write an `sms_message` naming the provider
and the source.

#### Scenario: a sibling app sends a reminder through MessageBird
- GIVEN an enabled SMS source with provider `messagebird` and a brokered access key
- WHEN pipelinq posts a message to `/api/notifynl/messages` naming that source
- THEN MessageBird receives the message, and the SMS page lists it as sent through `messagebird` with its source
- e2e: `tests/e2e/sms-providers.spec.ts`

#### Scenario: a Twilio source without a credential sends nothing
- GIVEN an SMS source with provider `twilio` and no `credentialRef`
- WHEN a message is sent through it
- THEN no request leaves the instance, and the stored message is `failed` with a reason that names the missing credential
- @e2e exclude a fail-closed path with no screen of its own; covered by PHPUnit on TwilioSmsProvider

### Requirement: An unknown SMS provider value is refused (REQ-SMSP-002)

When an SMS source names a provider no binding answers to, the send MUST
fail and the stored `sms_message` MUST say which value was unknown. A source
with no provider value MUST keep using the log binding.

#### Scenario: a mistyped provider shows as a failure, not a success
- GIVEN an SMS source whose provider is `twillio`
- WHEN a message is sent
- THEN the message is stored as `failed` naming `twillio`, and no message reports `sent`
- e2e: `tests/e2e/sms-providers.spec.ts`

### Requirement: A caller picks the SMS source, and the form picks the provider (REQ-SMSP-003)

The send MUST accept an optional `sourceSlug` and MUST refuse a slug that is
not an enabled source of type `sms`. Without it, the first enabled SMS
source MUST send, as today. The source form MUST offer a provider picker for
a source of type `sms`, built from `GET /api/sms/providers`.

#### Scenario: an administrator sets up a CM.com source
- GIVEN an administrator creating a source of type `sms`
- WHEN they open the provider picker
- THEN it offers log, NotifyNL, CM.com, MessageBird and Twilio, and picking CM.com shows the fields CM.com needs
- e2e: `tests/e2e/sms-providers.spec.ts`

#### Scenario: a slug that is not an SMS source is refused
- GIVEN a source `brp-haalcentraal` of type `api`
- WHEN a caller sends with `sourceSlug` `brp-haalcentraal`
- THEN the send is refused with a reason naming the slug, and nothing is sent
- @e2e exclude a server-to-server call; covered by PHPUnit on SmsDispatchService

### Requirement: Vendor delivery status reaches the stored message (REQ-SMSP-004)

A delivery callback from CM.com, MessageBird or Twilio MUST arrive on a
route that carries a per-source token, compared in constant time, and MUST
also pass the vendor's signature check where the vendor signs. A checked
callback MUST update the `sms_message` it names and MUST leave an unknown id
unchanged. Polling MUST read the vendor's status where the vendor offers a
status read.

#### Scenario: a Twilio delivery receipt marks the message delivered
- GIVEN a message sent through Twilio and a callback signed with the source's signing key
- WHEN Twilio posts the delivered status to the source's callback URL
- THEN the message shows `delivered` on the SMS page
- e2e: `tests/e2e/sms-providers.spec.ts`

#### Scenario: a forged callback changes nothing
- GIVEN a callback with a wrong token or a bad signature
- WHEN it arrives
- THEN it is refused before its body is read, and no message changes
- @e2e exclude a refused public request; covered by PHPUnit on SmsStatusController

### Requirement: NotifyNL sends e-mail and the send is logged per recipient (REQ-SMSP-005)

Integriq MUST send an e-mail through NotifyNL's `/v2/notifications/email`
with a template and personalisation, from `POST /api/notifynl/emails`
behind the `notifynl.email` action. Each send MUST be recorded in the
outbound message log on channel `notifynl` with NotifyNL's id as reference.
NotifyNL's delivery callback and status read MUST update that record.

#### Scenario: a case worker's app sends a NotifyNL mail
- GIVEN an enabled NotifyNL source with a mail template mapped as `ontvangstbevestiging`
- WHEN dossiq posts an e-mail for that template and an address
- THEN NotifyNL receives it, and the outbound message log shows it handed over with NotifyNL's reference
- e2e: `tests/e2e/sms-providers.spec.ts`

#### Scenario: NotifyNL reports the mail delivered
- GIVEN a NotifyNL mail handed over
- WHEN NotifyNL's signed callback reports it delivered
- THEN the outbound message record shows the recipient delivered
- @e2e exclude a provider callback; covered by PHPUnit on NotifyNlController::inbound

#### Scenario: an invalid address is refused before sending
- GIVEN an address with no domain
- WHEN a caller posts an e-mail for it
- THEN the request is refused and nothing is sent or recorded
- @e2e exclude a server-to-server validation; covered by PHPUnit
