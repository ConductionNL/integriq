---
kind: code
---

# Proposal: signed-outbound-webhooks

## Summary

Make a signature the default on an outbound webhook instead of an option
an operator has to find. A new push subscription is created with a signing
secret, the subscription page states the recipe a receiver needs to verify
it, and an unsigned subscription is visible as unsigned wherever it is
listed.

## Motivation

Competitor gap register, row Q6.20 "Does an outbound webhook carry a
signature the receiver can verify" (`procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence, 2026-09-13). Rated partial, owner
integriq, slug `signed-outbound-webhooks`, size S. Opened by the last
sweep of the OpenSpec phase.

The best competitor, verbatim from the register's `best` column: "Vikunja
2.6.0: a webhook takes a write-only secret and requests are signed with
HMAC (pkg/models/webhooks.go:64-67), read in the code
(`_round4/compare/proposed-rows-batch7.md`)". The load-bearing word is
*takes*: in Vikunja the secret is part of what a webhook is, not a setting
beside it.

The register's note: "`lib/Service/Transitions/WebhookHandler.php` has no
`hmac`, `signature` or `secret`, so outbound calls are unsigned; inbound
signatures are verified in `DSOIntakeController.php:54-88` and
`DwangsomPaymentCallbackController.php:149-180`, HMAC-SHA256 over the raw
body. Vikunja signs with an HMAC secret per webhook
(`pkg/models/webhooks.go:64`); Kanboard appends one instance-wide token to
the URL as a query parameter."

## Where the register is wrong, and what is actually left

The register's `covered` column reads "none (events-cloudevents specifies
retry and dead letter, not signing)". That is true of
`events-cloudevents` and wrong about integriq. `openspec/specs/webhook-signing/`
already specifies outbound HMAC signing in full: REQ-WHS-001 signs every
push delivery for a subscription carrying `protocolSettings.signingSecret`
with `X-OpenConnector-Signature: t=<unix-ts>,v1=<hex>` over
`"<t>." + rawBody`, REQ-WHS-002 covers generation, one-time reveal,
redaction and rotation with a 24 hour dual-sign window, REQ-WHS-003 is the
inbound rule, and `lib/Service/WebhookSignatureService.php` implements
`sign()`, `verify()` and `isRotationGraceActive()`. The archived change
`2026-06-14-openconnector-webhook-signing` carries 15 tasks, all ticked.
Whoever re-rates the row should point it at `webhook-signing`, not at
`events-cloudevents`.

What is genuinely missing is one sentence of REQ-WHS-001: "Subscriptions
without a `signingSecret` MUST deliver unsigned, unchanged from current
behaviour." Signing is opt-in. An operator who never opens the signing
section ships an unsigned webhook and nothing says so, which is exactly
the state the row measures. Vikunja's answer is that the secret arrives
with the webhook, and that is the gap this change closes.

## Scope

- A push subscription is created with a generated signing secret. The
  create response reveals it once, the way REQ-WHS-002 already reveals a
  rotated one, and every later read redacts it.
- Unsigned delivery becomes an explicit choice: `protocolSettings.unsigned`
  with a reason the operator types, refused without one.
- The subscription page states the verification recipe: the header name,
  the exact string that is signed, the algorithm, the tolerance and the
  rotation window, so a receiver can implement verification without
  reading our source.
- The subscription list, the detail page and the delivery log mark an
  unsigned subscription and an unsigned attempt as unsigned.
- Existing subscriptions are untouched. Nothing gains a secret on upgrade,
  because a secret the receiver does not have turns every delivery into a
  401 on their side.

## How dossiq consumes it

The register's `dossiq_half`: "nothing beyond finishing
dossiq-delivers-nothing, which retires WebhookHandler; dossiq already
verifies inbound HMAC on two controllers". dossiq's own
`WebhookHandler` sends unsigned and goes away with that change; once its
outbound traffic is on integriq's bus it is signed by default and dossiq
writes no signing code. No dossiq change is opened for this row.

## ADRs

- ADR-091: the protocol and its credential are integriq's, not a leaf
  app's.
- ADR-064: the secret is custody material, generated server-side, revealed
  once, redacted everywhere else.
- ADR-005: an unsigned delivery is a choice somebody made and can be
  audited, not a default nobody saw.
- ADR-102: a subscription with neither a secret nor a recorded reason is a
  configuration error, refused at save.

## Existing specs it extends

`webhook-signing` (the delta lives there) and, by reference,
`events-cloudevents` and `dead-letter-replay`, whose retry and replay
paths already sign under REQ-WHS-001.

## Out of scope

- Changing the signature format. `X-OpenConnector-Signature` is shipped
  and receivers verify against it; a second format would break them.
- Signing inbound. REQ-WHS-003 already covers verification of what
  arrives.
- Retro-fitting a secret onto an existing subscription. That is an
  operator action with a receiver on the other end, and rotation already
  exists for it.
