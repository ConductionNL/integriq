# Design: outbound-sender-identity-and-deliverability

## Context

`outbound-communication-log` records every message per recipient and per
step. This change decides what that message looks like to the receiver and
who is allowed to receive it. Decision D12, as Ruben answered it, puts the
mail account in Nextcloud Mail, so an identity here is a face on an account
somebody else owns.

## Decisions

### An identity is not an account

The account holds the credentials and the OAuth grant, and Nextcloud Mail
owns it. An identity holds what a recipient sees: display name, address,
reply-to, signature, quoting level, hold window, keys. Many identities may
share one account, and moving an identity to another account changes no
policy.

The re-read the umbrella asked for lands here. Wave 1 assumed integriq
would hold the account, and it does not.

### Domain alignment is reported, never claimed

Nothing in this change can make a receiver trust a domain. What it can do
is check the DNS records for the identity's domain, report SPF, DKIM and
DMARC as present, absent or misaligned, and print the exact record to
publish. A red identity still sends, with the risk shown, because blocking
a send would break an instance the day a DNS change propagates slowly.

The candidate is documented rather than driven, and the design stays
deliberately close to what can be verified: a DNS lookup and a comparison.

### The opt-out is one list, and it names what it cannot stop

A single opt-out per recipient address per instance, checked by every
sender before a send. Categories that may never be stopped are declared as
a list: a besluit, an ontvangstbevestiging, anything with a statutory
delivery duty. A send in a protected category ignores the opt-out and
records that it did.

Per-app opt-out lists were rejected. A recipient who asked not to be
written to should not have to ask each app separately, and the AVG duty is
the sender's, not the app's.

### The unsubscribe link binds to a case, not to an account

The link carries a signed token for the recipient and the case. Following
it adds an opt-out for that case's updates. It creates no account and needs
no login, because the recipient often has neither. A statutory message
carries no link at all rather than a link that refuses, so nobody is told
they can stop something they cannot.

### Undo is a hold, not a recall

The message sits in the queue for the identity's hold window before the
send is attempted. During the window it can be withdrawn and the log
records the withdrawal. Once the message has left, nothing is recalled,
because nothing can be. A zero window disables the feature for that
identity.

### Signature stripping changes the timeline, never the log

The stripped text is what the timeline shows. The message as it arrived
stays whole in `outbound-message-log` and in the inbound record. Stripping
is a rendering decision, and a rendering decision must never lose evidence
that a delivery dispute may need.

### Keys are administered per identity

S/MIME and PGP keys belong to the identity that signs with them. Recipient
public keys are held per address. When no recipient key is known, the
message is signed and not encrypted, and the log says which of the two
happened, so nobody assumes encryption they did not get.

## Risks

- **An opt-out silently stops something statutory.** The protected category
  list is the mitigation, and a send that overrode an opt-out is recorded
  as such so it can be shown afterwards.
- **A hold window delays a time-critical message.** The window is per
  identity and defaults to zero, so it is opt-in.
- **Stripping removes content somebody needed.** Only the quoted signature
  and disclaimer blocks are stripped, the log keeps the original, and the
  timeline offers the original on demand.

## Open questions

- Whether the protected category list is per instance or fleet-wide. The
  spec requires the list and leaves the scope to configuration.
- Where recipient public keys come from in bulk. The spec requires
  per-address administration and does not specify a directory lookup.
