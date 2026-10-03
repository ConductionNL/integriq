# Design: signed-outbound-webhooks

Kind: code. One default flipped, one explicit opt-out, one read surface.

## D1. The secret is generated at create, not offered later

**Corrected at build (29 Sep 2026).** There is no `SubscriptionService`, and
the Webhooks page does not call `EventsController::subscribe`: it saves
through OpenRegister's generic object API. A default in a service or in the
controller would never run for the subscriptions people actually make. The
decision logic already existed as `SubscriptionSigningPolicy` (#2074, 18 Sep,
with 14 tests and no caller). It now runs in
`SubscriptionSigningDefaultListener`, registered on OpenRegister's
`ObjectCreatingEvent` and `ObjectUpdatingEvent`. Both are stoppable, and
MagicMapper merges `setModifiedData()` into the object before it is written,
so the default holds whichever page or app saves an `event_subscription`.

On create, a `style: push` subscription without `protocolSettings.unsigned`
gains `WebhookSignatureService::generateSecret()`.

### D1a. Reveal once: the simplest safe design (a decision Ruben can reverse)

`protocolSettings` is `writeOnly` (register.d/99-event-subscription-secrets-writeonly.json),
so a generic create never sees the secret, and nothing should change that.

- `POST /api/events/subscriptions` (the app's own create route) returns the
  generated secret once, as `signingSecret` beside the redacted subscription.
  It reads the stored row unrendered, the way the delivery engine does. A
  caller that supplied its own secret gets nothing back.
- A subscription made on the Webhooks page is signed, but its secret is
  shown to nobody. The Webhook signing modal says so, and **Generate signing
  secret** makes a new one and shows it once. That is one click for the
  operator and adds no new place a secret can be read from.

Rejected: a one-shot cache entry a reveal endpoint hands out after a generic
create. It adds a second read path for secret material, keyed on a session
and a timer, to save one click. To reverse this decision, add that endpoint
and have the modal call it on first open.

## D2. Unsigned is a decision with a name on it

`protocolSettings.unsigned: {"reason": "<text>", "setBy": "<uid>", "setAt": "<iso>"}`
(the key names `SubscriptionSigningPolicy` shipped with; the design said `by`/`at`).
Generating a secret on an unsigned subscription removes `unsigned`: choosing
to sign ends the earlier decision. On the wire, `unsigned` wins over a secret
left from before, the same rule the policy's `isSigned()` reads.
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
delivery attempt in the log records whether it was signed.

Because `protocolSettings` is hidden on every read, the listener writes a
readable mirror on each save: `signingPosture` (`signed` or `unsigned`) and
`unsignedReason`. The Webhooks index shows `signingPosture` as a column and
the signing modal reads both. `event_message.attempts[].signed` is written by
`EventService::deliverMessage` for immediate attempts, retries and operator
replays alike, since all three pass through it. A subscription saved before
this change has no mirror until it is saved again; D5 still holds, because
the mirror is not a secret. A receiver who
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
