# Sign people in with DigiD, eHerkenning or eIDAS

Integriq is the one place that talks to a government identity provider. Every
other app gets a small signed statement about who just logged in, and never the
assertion itself.

That split is the point. An app that cannot read the assertion cannot leak it,
cannot store a BSN it should not hold, and does not need its own certificate.

## What an app receives

A subject envelope. Six claims about the person, and nothing else:

| Claim | What it holds |
|---|---|
| `sub` | the pseudonym for DigiD, the KvK or RSIN for eHerkenning, the PersonIdentifier for eIDAS |
| `subType` | which of those it is |
| `provider` | `digid`, `eherkenning` or `eidas` |
| `audience` | the app the envelope was minted for |
| `organisation` | the tenant the login happened in |
| `trust` | `low`, `substantial` or `high` |

It also carries `use: idp-envelope`. That claim is what stops a leaked envelope
being used as a session: a session resolver refuses any token that carries it.

An envelope lives 60 seconds and verifies once.

**The signature is ours, not yours.** The envelope is signed HS256 under a key
only integriq holds, and integriq verifies it at redemption. Your app cannot
check that signature itself: what it trusts is the exchange call, which is
authenticated with your shared secret over TLS. If you need to verify the
envelope independently, say so, because that means publishing an asymmetric
key and a JWKS, and that is a change rather than a setting.

## How the hand-off works

1. The person authenticates at the provider.
2. Integriq verifies the assertion, mints the envelope, and puts it behind a
   one-time code.
3. The browser is redirected back to the app carrying the code, not the
   envelope. Anything in a URL bar gets read, logged and shared.
4. The app's server posts the code to `/api/idp/envelope/exchange` with its own
   shared secret, and receives the envelope once.
5. The app mints its own session from it.

A second exchange of the same code fails. So does a second verification of the
same envelope.

## What you configure

Five settings, under `integriq`:

```bash
occ config:app:set integriq idp_broker_signing_key --value "<32 bytes or more>"
occ config:app:set integriq idp_broker_consumers --value '{"portaliq":"<secret>"}'
occ config:app:set integriq idp_broker_salts --value '{"gemeente-x":"<32 bytes or more>"}'
occ config:app:set integriq idp_broker_trust_aliases --value '{"digid":{"<urn>":"Hoog"}}'
occ config:app:set integriq idp_broker_enabled --value "1"
```

Set the flag last. With it at `0` every endpoint answers 401 and no adapter
authenticates anybody.

**You need a shared cache.** Redis or Memcached, configured in Nextcloud as the
distributed cache. Without one integriq refuses to issue a code at all, and
says so. That is deliberate: Nextcloud's fallback cache lives for a single
request, and a single-use guard on top of it accepts every replay. A guard that
cannot work should stop, not pretend.

## The pseudonym

A BSN never leaves the callback. Two routes get you there.

If your broker contract includes polymorphic pseudonymisation, integriq uses
the pseudonym the broker decrypted and the BSN never enters the stack at all.
Prefer this.

Without it, integriq computes `HMAC-SHA256` over the organisation and the BSN
under that organisation's salt, in memory, during the callback only. The same
person in the same organisation gets the same `sub` every time. The same person
in another organisation gets a different one, and that stays true even if
somebody reuses one salt, because the organisation is part of what gets signed.

A salt shorter than 32 bytes is refused. A BSN is nine digits, so a short salt
makes the whole pseudonym space brute-forceable.

## Trust levels

| Provider | Level | Trust |
|---|---|---|
| DigiD | Basis, Midden | `low` |
| DigiD | Substantieel | `substantial` |
| DigiD | Hoog | `high` |
| eHerkenning | EH2, EH2+ | `low` |
| eHerkenning | EH3 | `substantial` |
| eHerkenning | EH4 | `high` |
| eIDAS | low, substantial, high | the same |

A level not in that table ends the login. It does not become `low`.

**Your provider probably sends a URN, not a word.** The eHerkenning
(`urn:etoegang:core:assurance-class:loa3`) and eIDAS
(`http://eidas.europa.eu/LoA/high`) URNs are built in. DigiD's are not, on
purpose: they are deliberately left to your own metadata rather than guessed,
because a wrong guess does not fail loudly. It either refuses every login,
which you would notice, or it maps Hoog onto low, which you would not.

Read the spellings off your tenant's metadata and put them in
`idp_broker_trust_aliases`.

## Trust never changes mid-session

An envelope freezes its trust level. A person on a `substantial` session who
needs a `high` action goes through a new authentication. Nothing rewrites the
claim in place.

## What is not built yet

This is the half of the broker that needs no vendor. The other half does.

- **The SAML Service Provider and the OIDC Relying Party.** Signature
  verification, metadata, certificates and the broker contract itself. Until
  those land, every provider binds to an adapter that logs the attempt and
  refuses.
- **The Beheer > Authenticatie screen.** The settings above are the same ones
  it will write.
- **Single logout.** Local logout in the consuming app works and always will.
  Propagating it to the provider depends on what the broker contract supports.

## Walk it once

At the time of writing this page has been walked against the spec and the
services' own tests, not against a live broker or a live tenant. The DigiD
level spellings and the polymorphie hand-off are the two points where a live
walk is most likely to correct it.
