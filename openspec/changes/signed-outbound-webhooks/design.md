# Design: signed-outbound-webhooks

Kind: code. One default flipped, one explicit opt-out, one read surface.

## D1. The secret is generated at create, not offered later

`SubscriptionService::create()` calls
`WebhookSignatureService::generateSecret()` for a `style: push`
subscription unless the request carries `protocolSettings.unsigned`. The
create response returns the full `whsec_...` value once, under the same
rule REQ-WHS-002 already applies to generate and rotate: full in that
response, redacted in every read after it.

## D2. Unsigned is a decision with a name on it

`protocolSettings.unsigned: {"reason": "<text>", "by": "<uid>", "at": "<iso>"}`.
A create or update that sets `unsigned` without a reason is refused at
save (ADR-102: a configuration that cannot be right is refused, not
defaulted). The reason is what an auditor reads a year later, and it is
the only thing that distinguishes a deliberate plaintext integration from
somebody who clicked past the signing section.

## D3. The recipe belongs on the page, not in our repo

The subscription page renders the verification recipe as text a receiver
can implement from: header `X-OpenConnector-Signature`, value
`t=<unix-ts>,v1=<hex>`, `v1 = HMAC-SHA256(secret, "<t>." + rawBody)`,
`rawBody` exactly as received, a tolerance the receiver chooses, and the
note that two `v1` pairs appear during a rotation grace window. Every one
of those facts is already normative in REQ-WHS-001 and REQ-WHS-002; this
puts them where the person integrating can see them.

## D4. Unsigned is visible where subscriptions are read

The subscription list and detail mark an unsigned subscription. Each
delivery attempt in the log records whether it was signed. A receiver who
asks "are you signing these" gets an answer from the log rather than from
a code reading.

## D5. No migration

Existing subscriptions keep their current state. Generating a secret for a
live subscription would make every delivery fail verification on the
receiver's side until they were told the value, and they would see it as
our outage. Rotation already exists for the operator who wants to move.

## Risks

- **A receiver that ignores the header.** Signing does not make them
  verify. The recipe on the page is the mitigation we can actually ship;
  the rest is their integration.
- **The secret revealed once and lost.** Rotation is the recovery path and
  it already exists. The create response says so in the same breath as the
  value.
- **An operator who sets `unsigned` to get past a refusal.** The reason
  field makes that a written statement rather than a silent default, which
  is the whole difference this change buys.
