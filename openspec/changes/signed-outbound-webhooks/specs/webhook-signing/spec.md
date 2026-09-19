# webhook-signing Delta: signed-outbound-webhooks

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- signed-outbound-webhooks

## Purpose

A receiver can verify every outbound webhook, because a subscription
arrives with a signing secret rather than acquiring one if somebody
remembers. Competitor gap register row Q6.20.

## ADDED Requirements

### Requirement: A push subscription is signed unless somebody says otherwise (REQ-SOW-001)

Creating an `event_subscription` with `style: push` MUST generate a
signing secret and store it as `protocolSettings.signingSecret`, unless
the request sets `protocolSettings.unsigned`. The create response MUST
return the full secret exactly once, and every later read MUST redact it
under REQ-WHS-002's convention. `protocolSettings.unsigned` MUST carry a
`reason`, the user who set it and the time; a save that sets `unsigned`
without a reason MUST be refused. Subscriptions that already exist MUST
NOT gain a secret.

#### Scenario: a new subscription is signed without anybody asking
- GIVEN an operator creating a push subscription with a sink and no signing configuration
- WHEN the subscription is saved
- THEN the response carries a full `whsec_...` value once and the stored subscription has a `signingSecret`
- AND the next delivery to that sink carries `X-OpenConnector-Signature`
- e2e: `tests/e2e/signed-outbound-webhooks.spec.ts`

#### Scenario: unsigned is refused without a reason
- GIVEN an operator creating a push subscription with `protocolSettings.unsigned: {}`
- WHEN the subscription is saved
- THEN the save is refused and the message names the missing reason
- @e2e exclude validation path; covered by PHPUnit

#### Scenario: unsigned with a reason is accepted and recorded
- GIVEN an operator creating a push subscription with `unsigned: {"reason": "receiver cannot verify HMAC yet"}`
- WHEN the subscription is saved
- THEN the subscription stores the reason, the user and the time, and deliveries carry no signature header
- e2e: `tests/e2e/signed-outbound-webhooks.spec.ts`

#### Scenario: an existing subscription is left alone
- GIVEN a subscription created before this change with no `signingSecret`
- WHEN the app is upgraded
- THEN the subscription still has no secret and its deliveries are unchanged
- @e2e exclude upgrade behaviour; covered by PHPUnit

### Requirement: The subscription page states what a receiver must compute (REQ-SOW-002)

The subscription surface MUST state the verification recipe: the header
name, the `t=<unix-ts>,v1=<hex>` value shape, that `v1` is
`HMAC-SHA256(secret, "<t>." + rawBody)` over the body exactly as received,
that a receiver should apply its own timestamp tolerance, and that two
`v1` pairs appear during a rotation grace window. The recipe MUST be shown
whether or not the secret is currently revealed, and MUST ship with Dutch
and English strings.

#### Scenario: an integrator reads the recipe without the secret
- GIVEN a signed subscription whose secret was revealed and dismissed
- WHEN an operator opens the subscription page
- THEN the verification recipe is shown with the header name, the signed string and the algorithm
- AND the secret itself is redacted
- e2e: `tests/e2e/signed-outbound-webhooks.spec.ts`

### Requirement: An unsigned subscription and an unsigned attempt are marked (REQ-SOW-003)

The subscription list and detail MUST mark a subscription that delivers
unsigned. Every delivery attempt recorded in the log MUST record whether
it was signed, for immediate attempts, retries and operator replays alike.

#### Scenario: the list distinguishes signed from unsigned
- GIVEN one signed and one unsigned push subscription
- WHEN an operator opens the subscription list
- THEN the unsigned one is marked and the signed one is not
- e2e: `tests/e2e/signed-outbound-webhooks.spec.ts`

#### Scenario: the delivery log answers the receiver's question
- GIVEN an unsigned subscription with three delivery attempts
- WHEN an operator reads its delivery log
- THEN each attempt records that it was sent unsigned
- @e2e exclude delivery logging; covered by PHPUnit
