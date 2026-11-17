# Sign people in with DigiD, eHerkenning or eIDAS

Integriq is the one place that talks to a government identity provider. Every
other app gets a small signed statement about who just logged in, and never the
assertion itself.

That split is the point. An app that cannot read the assertion cannot leak it,
cannot store a BSN it should not hold, and does not need its own certificate.

## What an app receives

A subject envelope. Six claims about the person, a seventh for a branch login,
and nothing else:

| Claim | What it holds |
|---|---|
| `sub` | the pseudonym for DigiD, the KvK or RSIN for eHerkenning, the PersonIdentifier for eIDAS |
| `subType` | which of those it is |
| `provider` | `digid`, `eherkenning` or `eidas` |
| `audience` | the app the envelope was minted for |
| `organisation` | the tenant the login happened in |
| `trust` | `low`, `substantial` or `high` |
| `branch` | eHerkenning only: the vestigingsnummer the login was restricted to. Absent otherwise |

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

1. The app sends the browser to
   `/apps/integriq/api/idp/<provider>/start?organisation=&consumer=&trust=&returnUrl=&relayState=`.
   `<provider>` is `digid`, `eherkenning` or `eidas`.
2. Integriq checks the app and its return address, keeps a signed state for
   five minutes, and sends the browser to the provider.
3. The person authenticates at the provider, which sends the browser to
   `/apps/integriq/api/idp/<provider>/callback`.
4. Integriq verifies the assertion, mints the envelope, and puts it behind a
   one-time code.
5. The browser goes back to the return address with `code` and `relayState`.
   It carries the code, not the envelope. Anything in a URL bar gets read,
   logged and shared.
6. The app's server posts the code to `/api/idp/envelope/exchange` with its own
   shared secret, and receives the envelope once.
7. The app mints its own session from it.

Integriq sends the browser back only to an address registered for that app,
compared character for character. A start with any other address ends on
integriq's own error page, and the browser goes nowhere.

When a login fails after the start, the browser still goes back to the app,
with `error=login_failed` and the relay state. The app gets one reason for
every failure. The real one is in the Nextcloud log.

A response the provider sends without a login integriq started is refused.

A second exchange of the same code fails. So does a second verification of the
same envelope.

## What you configure

Two things: the hand-off to the apps that use the login, and one registration
per organisation and provider.

### The hand-off

```bash
occ config:app:set integriq idp_broker_signing_key --value "<32 bytes or more>"
occ integriq:idp:consumer portaliq \
  --return-url=https://portal.example.nl/index.php/apps/portaliq/portal/api/session/broker/callback \
  --secret-ref=<credential id in the OpenRegister credential broker>
occ config:app:set integriq idp_broker_enabled --value "1"
```

Integriq ships `portaliq` registered and switched off, without a secret or an
address, so the command above is all it takes. Repeat `--return-url` for more
addresses.

Register the return address exactly as portaliq sends it: your portal's host,
then `/index.php/apps/portaliq/portal/api/session/broker/callback`. Leave out
`/index.php` when your Nextcloud runs with pretty URLs. The comparison is
character for character, so one missing segment refuses every start.

Integriq takes no sign-out address. A return address must be https; plain http
works for `localhost` only. Add `--secret-organisation=<organisation>` when the
credential belongs to an organisation, and `--disable` to switch an app off.

The exchange secret stays in the credential broker. Integriq reads it when a
code is redeemed and never copies it into app config.

Set the flag last. With it at `0` every endpoint answers 401 and no adapter
authenticates anybody.

### One registration per organisation and provider

Each organisation has its own Service Provider (SAML) or Relying Party (OIDC)
for each provider it offers, because that is how DigiD and eHerkenning
contracts work: one contract per organisation. A registration is an
`idpRegistration` object in the integriq register, readable by administrators
only, and edited under *Beheer > Authenticatie*. There can be one per
organisation and provider; a second one is refused.

**A SAML registration** (`kind` `saml-sp`) needs:

- `organisation` and `provider` (`digid`, `eherkenning` or `eidas`);
- `idpMetadataUrl`, the metadata of the identity provider or your broker;
- `spEntityId` only when your scheme assigns one. Otherwise the EntityID is
  the metadata address below;
- the `signing` credential: your SP private key and certificate. Add a
  `decryption` credential when the identity provider encrypts assertions.

**An OIDC registration** (`kind` `oidc-rp`) needs:

- `organisation` and `provider`;
- `issuer`. Integriq reads `{issuer}/.well-known/openid-configuration`;
- `clientId` and `clientAuth`: `private_key_jwt` with a `signing` credential,
  or `client_secret_basic` with a `clientSecret` credential.

Both kinds also carry:

- `subjectMapping`: which attribute or claim holds `bsn`, `pseudonym`, `kvk`,
  `rsin`, `branch` and `eidasPersonIdentifier`. Nothing else is read;
- `assuranceMapping`: the spelling your provider sends for each level, mapped
  to a level in the table below;
- `pseudonymisation`: `polymorphic` or `hmac` (the default), with the
  `pseudonymKeys` or the `salt` credential that goes with it;
- `enabled`, off by default.

**Keys live in Keepiq.** A registration holds credential ids only, never a
key. Integriq reads each one through the OpenRegister credential broker at the
moment it is needed, for that organisation, and keeps it for that request
only. Without Keepiq installed and enabled no key is read and no login starts:
integriq does not fall back to another store. A key that cannot be read stops
the login and is logged by registration and role, never by value.

A registration is used only when it is ready: enabled, every property its kind
needs filled in, and every credential it names readable. Anything less, and the
provider stays on the adapter that logs the attempt and refuses.

### The metadata address

Give your identity provider or broker this address for each registration:

```
https://<your nextcloud>/index.php/apps/integriq/api/idp/metadata/<organisation>/<provider>
```

For a SAML registration it answers SP metadata with the EntityID, the public
certificates, the callback `/apps/integriq/api/idp/<provider>/callback` for
the POST and Redirect bindings, and the attributes the subject mapping reads.
For an OIDC registration it answers the client id, the redirect address and,
with `private_key_jwt`, the address of the public key set at
`/apps/integriq/api/idp/jwks/<organisation>/<provider>`. No private key and no
secret is ever in either.

An unknown organisation, an unknown provider, a missing registration and a
disabled one all answer the same 404, so the address does not tell anyone
which organisations exist. A certificate that cannot be read answers 503.

### Settings that no longer apply

`idp_broker_entity_ids`, `idp_broker_salts` and `idp_broker_trust_aliases`
are no longer read. Each organisation's EntityID, salt and level spellings now
belong to its registration. An upgrade logs one warning for each of them that
still holds a value. Put each salt in Keepiq, reference it from the
registration, then delete the setting.

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

**Substantial is the floor.** A level that maps to `low` is refused even when
the app asked for `low`, and no setting lowers the floor. The person sees a
page that names the level they used, the level needed and how to get it for
that provider, with one button back to the app. The app receives
`error=assurance_below_floor` and the relay state.

**Your provider probably sends a URN, not a word.** The eHerkenning
(`urn:etoegang:core:assurance-class:loa3`) and eIDAS
(`http://eidas.europa.eu/LoA/high`) URNs are built in. DigiD's are not, on
purpose: they are deliberately left to your own metadata rather than guessed,
because a wrong guess does not fail loudly. It either refuses every login,
which you would notice, or it maps Hoog onto low, which you would not.

Read the spellings off your tenant's metadata and put them in the
registration's `assuranceMapping`.

## Trust never changes mid-session

An envelope freezes its trust level. A person on a `substantial` session who
needs a `high` action goes through a new authentication. Nothing rewrites the
claim in place.

## What is not built yet

- **The SAML Service Provider and the OIDC Relying Party adapters.** Signing,
  signature verification and the token exchange itself. Until they land, a
  ready registration still binds to the adapter that logs the attempt and
  refuses, so a start today ends on the error page. The metadata address is
  built, so a broker can be registered ahead of them.
- **The Beheer > Authenticatie screen.** Until it lands, registrations are
  edited as `idpRegistration` objects in OpenRegister.
- **Single logout.** Local logout in the consuming app works and always will.
  Propagating it to the provider depends on what the broker contract supports.
- **Berichtenbox linkage.** Linking a login to the person's MijnOverheid
  Berichtenbox is out of scope for the login. Letters to the Berichtenbox go
  through the Berichtenbox adapter.

## Walk it once

At the time of writing this page has been walked against the spec and the
services' own tests, not against a live broker or a live tenant. The start and
callback round trip ran against a scripted provider in the unit tests only. The DigiD
level spellings and the polymorphie hand-off are the two points where a live
walk is most likely to correct it.
