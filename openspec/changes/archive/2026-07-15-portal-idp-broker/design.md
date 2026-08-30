# Design: portal-idp-broker

## Context

Portaliq's auth edge is audience-agnostic and fail-closed (ADR-046, contract
v2): `PortalSessionService::resolveFromBearer()` only ever trusts a validated
HS256 JWT minted by `issueSession(subjectRef, audience, organisation, trust,
roles)`, trust is normalised into the eIDAS-aligned vocabulary
`low|substantial|high`, and server-to-server forwarding uses a short-lived
(60s) A6 assertion carrying `use: "assertion"` so an assertion can never be
replayed as a session. Accounts and sessions are OpenRegister objects
(`portalAccount`, `portalSession` in `lib/Settings/portaliq_register.json`);
`portalAccount.identityType` already enumerates
`eherkenning|digid|eidas|dev`, and `identityRef` is documented as
"pseudonymous identity reference … **not a raw BSN**".

What is missing is the other side: **nobody hosts the SAML SP / OIDC RP that
talks to the government IdP chain.** ADR-017 reserves that role for
openconnector (`digid-eherkenning-auth-adapter` catalogue entry; Rule 3 puts
tenant-wide broker config in *Beheer > Authenticatie*; Rule 7 sanctions the
Adapters+Beheer split). Procest built the consumer half of this seam and left
it dormant: `EHerkenningSamlAdapterInterface` + `LogEHerkenningSamlAdapter`
(logs + throws), activation ladder = broker config in openconnector → SP key +
cert in app-config → feature flag `0→1` → DI swap.

Constraints:

- **Wet BSN / AVG**: raw BSN may only be processed by parties with a legal
  basis. Portaliq, OpenRegister object stores, and generic app code have none.
- **eIDAS LoA**: the Netherlands notified DigiD at *substantial*/*high* and
  eHerkenning at *substantial* (EH3) / *high* (EH4); lower levels are not
  notified.
- **ADR-017**: no new top-level menus, no per-adapter settings pages; broker
  config = *Beheer > Authenticatie*, discovery = *Adapters* catalogue.
- **ADR-007/ADR-016**: source credentials today are plaintext-pending-
  encryption; broker private keys must go through the encryption-service
  design, not the legacy plaintext path.

Stakeholders: portaliq (Wave-2 gate), procest (dormant seam), zaakafhandelapp /
docudesk / decidesk / softwarecatalog (Wave-2 consumers), Conduction ops
(certificate custody), the municipalities (white-label tenants).

## Goals / Non-Goals

**Goals**

- Pin the broker boundary, envelope shape, trust mapping, flow, dormant-seam
  activation, and threat posture as normative spec before any code exists.
- Keep everything broker-vendor-agnostic so D1 (vendor selection) does not
  invalidate the spec.

**Non-Goals**

- Implementing anything (PHP/Vue/routes/JSON) — chained changes do that.
- Choosing the broker vendor, certificate custody, or SP-metadata tenancy —
  parked as Open Decisions D1–D3 in the proposal.
- Berichtenbox identity linkage (D5), machtigingen/ketenmachtiging depth,
  IdP-initiated login (rejected by design), Wave-3 audiences.

## Decisions

### D1 — Broker boundary: openconnector owns the IdP conversation

Openconnector hosts the SAML SP (eHerkenning/eIDAS, and DigiD when connected
via SAML) and/or OIDC RP (broker-dependent) and is the **only** component that
ever sees raw IdP assertions. Consumers (portaliq first, procest's leverancier
portal later) receive a **verified subject envelope** and nothing else:

- eHerkenning → `sub` = KvK number (or RSIN for the intermediary/ketenmachtiging
  case), `subType: "kvk" | "rsin"`.
- DigiD → `sub` = **BSN-pseudonym** (never the BSN itself), `subType:
  "bsn-pseudonym"`.
- eIDAS inbound → `sub` = eIDAS PersonIdentifier (already pseudonymous per
  scheme), `subType: "eidas-uid"`.

**Alternatives considered:**

- *Each consuming app hosts its own SP* (procest's original fallback path) —
  rejected: N× certificates, N× broker contracts, N× BSN exposure surfaces;
  violates the one-write-path unification rule and ADR-017's explicit
  placement of the broker in openconnector.
- *Portaliq hosts the SP itself* — rejected: portaliq is a projection/portal
  surface, not the integration plane; it must stay BSN-free by design, and
  every non-portal consumer (procest) would be locked out.

### D2 — BSN pseudonymisation at the broker edge

Raw BSN MUST NOT leave the broker edge — not in the envelope, not in logs,
not in any OpenRegister object, not in error messages.

- **Preferred: polymorphic pseudonymisation** (eToegang *polymorphe
  identiteit/pseudoniemen*): the broker/IdP delivers an encrypted polymorphic
  pseudonym that openconnector decrypts to a per-service-provider pseudonym.
  BSN never materialises in our stack at all. Requires the capability to be
  **contracted** with the broker (part of Open Decision D1).
- **Fallback: salted-HMAC pseudonym at the broker edge.** When polymorphie is
  not contracted, the DigiD assertion contains the BSN; openconnector computes
  `pseudonym = HMAC-SHA256(salt_org, bsn)` **in-memory during callback
  processing only**, with a per-organisation salt stored in the ADR-016
  encrypted credential store, then discards the BSN. The BSN exists only for
  the lifetime of one callback request. Same-BSN → same-pseudonym per
  organisation (stable `subjectRef` across logins), different across
  organisations (no cross-tenant linkability).

**Alternatives considered:** storing BSN encrypted in openconnector and
handing out lookup ids — rejected: creates a BSN store with all the legal
obligations that implies; the pseudonym designs need no store at all.

### D3 — Dormant seam (procest precedent, generalised)

Mirror procest's proven pattern, on the openconnector side:

- Interfaces per provider conversation (e.g. a broker-adapter contract shaped
  like procest's `EHerkenningSamlAdapterInterface::decodeAssertion() +
  isActive()`), one per provider family (digid / eherkenning / eidas), plus a
  provider-agnostic envelope-issuer seam.
- Default binding = **Log-adapters** that log the call and **throw** ("broker
  not configured") so nothing can silently fall through to an authenticated
  state.
- Activation ladder (all four required, documented for the operator):
  1. broker entry configured in *Beheer > Authenticatie* (entrypoint URL,
     broker EntityID, IdP metadata XML);
  2. SP signing private key + X.509 certificate loaded via the ADR-016
     encrypted store;
  3. feature flag (e.g. `portal_idp.feature_flag`) flipped `0→1`;
  4. DI binding swapped from the Log-adapter to the live adapter.
- **Portaliq keeps its debug-gated `session#devLogin` edge until the flip**;
  the consumption change (chain link 3) makes dev-login refuse when a live
  broker is active for the instance.

**Alternatives considered:** shipping the runtime disabled-but-complete in one
change — rejected: certificates and a broker contract don't exist (D1/D2 open),
and the 2026-05-07 mixed-spec failure mode shows big speculative changes burn
budget without a shippable PR.

### D4 — Subject envelope: one-time code + server-to-server exchange, A6-shaped JWT

Handoff mechanics (see Flow below): after verifying the IdP assertion,
openconnector redirects the browser back to the consumer with a **one-time
opaque code** (≥256-bit random, single-use, TTL ≤ 60s). The consumer redeems
it **server-to-server** at openconnector's exchange endpoint (authenticated
with a shared per-consumer secret, `hash_equals` comparison — same model as
the OR Consumer secret and the credential-broker signed tokens) and receives
the **subject envelope**: a signed HS256 JWT that mirrors portaliq's A6
assertion shape:

```jsonc
{
  "sub":          "<pseudonym | kvk | rsin | eidas-uid>", // subjectRef source
  "subType":      "bsn-pseudonym | kvk | rsin | eidas-uid",
  "provider":     "digid | eherkenning | eidas",
  "audience":     "citizen | organisation | supplier | client | ...", // open string (fleet review §open-1/2)
  "organisation": "<tenant the login was initiated for>",
  "trust":        "low | substantial | high",              // per the mapping table below
  "use":          "idp-envelope",                          // token-confusion guard, mirrors A6 `use: assertion`
  "jti":          "<unique id>",                           // single-use, replay-rejected
  "iat":          1234567890,
  "exp":          1234567950,                              // iat + 60s max
  "iss":          "openconnector-idp-broker"
}
```

The consumer (portaliq) then mints **its own** session JWT via
`PortalSessionService::issueSession()` and upserts `portalAccount`
(`identityType` = provider, `identityRef` = `sub`, `lastLoginAt`) — the
envelope itself is never used as a session. Procest's activation change MUST
adopt this envelope (not its bespoke `BrokerAssertionResult` shape) so both
consumers share one contract.

**Alternatives considered:**

- *Pass the envelope JWT through the browser* (fragment/query) — rejected:
  lands in browser history, proxy logs, and Referer headers; bigger replay
  surface. The one-time code keeps the envelope server-to-server only.
- *Asymmetric signatures (RS256/EdDSA)* — deferred: HS256 + per-consumer
  secret mirrors the proven portaliq/procest/OR-Consumer model; an asymmetric
  upgrade is compatible later (`alg` header) if third-party consumers appear.

### D5 — Trust mapping table (normative)

Mapping to portaliq's vocabulary (`PortalSessionService::TRUST_ORDER`:
`low < substantial < high`). Only eIDAS-notified levels map above `low`;
anything unknown is **rejected at the broker** (no envelope), and portaliq
additionally normalises unknowns to `low` on its side (defence in depth —
fail-closed both ways).

| Provider     | Provider level            | eIDAS LoA (notified)      | Envelope `trust` |
| ------------ | ------------------------- | ------------------------- | ---------------- |
| DigiD        | Basis                     | — (not notified)          | `low`            |
| DigiD        | Midden                    | — (not notified)          | `low`            |
| DigiD        | Substantieel              | substantial               | `substantial`    |
| DigiD        | Hoog                      | high                      | `high`           |
| eHerkenning  | EH2 / EH2+                | — (not notified)          | `low`            |
| eHerkenning  | EH3                       | substantial               | `substantial`    |
| eHerkenning  | EH4                       | high                      | `high`           |
| eIDAS inbound| low                       | low                       | `low`            |
| eIDAS inbound| substantial               | substantial               | `substantial`    |
| eIDAS inbound| high                      | high                      | `high`           |
| any          | unknown / absent          | —                         | **reject — no envelope issued** |

Whether Wave-2 apps accept `low` DigiD logins at all is Open Decision D4;
per-collection/action `minTrust` enforcement already exists on the portaliq
side (`trustSatisfies()`).

### D6 — Flow, session lifetime, and logout (SLO)

```
Portal SPA            portaliq                    openconnector (broker)            commercial broker / IdP chain
   |                     |                                |                                   |
   | "Inloggen met       |                                |                                   |
   |  DigiD/eHerkenning" |                                |                                   |
   |-------------------->| GET /portal/auth/{provider}    |                                   |
   |                     |  mint signed `state`           |                                   |
   |                     |  {org, audience, return, nonce}|                                   |
   |                     |--302-------------------------->| /idp/{provider}/authorize?state= |
   |                     |                                |  build AuthnRequest / OIDC        |
   |                     |                                |  authz request                    |
   |                     |                                |--302/POST------------------------>| user authenticates at
   |                     |                                |                                   | DigiD / eHerkenning / eIDAS
   |                     |                                |<--SAML Response / OIDC code-------| (POST to ACS /idp/{provider}/callback)
   |                     |                                | verify: signature, Audience,      |
   |                     |                                |  InResponseTo==state.nonce,       |
   |                     |                                |  NotOnOrAfter, single-use         |
   |                     |                                | map: level→trust, id→pseudonym    |
   |                     |<--302 /portal/auth/callback?code=<one-time>                        |
   |                     |--POST /idp/exchange (S2S, consumer secret)-->|                     |
   |                     |<--subject envelope JWT---------|                                   |
   |                     | verify envelope (sig, exp,     |                                   |
   |                     |  use, jti single-use)          |                                   |
   |                     | upsert portalAccount;          |                                   |
   |                     | issueSession() → portal JWT    |                                   |
   |<--portal session----|                                |                                   |
```

- **Assertion TTLs:** IdP assertion validity per `NotOnOrAfter` (typically
  ≤ 5 min, broker-set); one-time code and envelope TTL ≤ 60s (mirrors
  `PortalJwtService::ASSERTION_TTL`); portaliq session TTL stays 2h
  (`DEFAULT_TTL`, procest-proven).
- **Session lifetime:** the portal session never outlives its own TTL and is
  revocable via the `portalSession` jti record (portaliq's next slice). The
  envelope's `trust` is frozen into the session; a step-up (higher LoA needed
  mid-session) is a NEW login flow, never an in-place mutation.
- **Logout (SLO):** local logout = `DELETE /portal/api/session` (exists).
  Broker SLO: user-initiated logout SHOULD trigger SP-initiated SLO at the
  broker where the contract supports it; **IdP-initiated SLO** arrives at
  openconnector, which propagates a revocation signal to consumers (portaliq
  marks the `portalSession` revoked). Full SLO fan-out depth is
  vendor-dependent (D1) and specced as a consideration, not a hard MUST, in
  the delta spec.

### D7 — Configuration placement (ADR-017 Rules 1, 3, 7)

- *Adapters* catalogue entry `digid-eherkenning-auth-adapter` (domain tag
  `Overheid-NL`) — discovery only.
- Tenant-wide broker config (broker entry, IdP metadata, certificates, flags,
  trust-table overrides — none of which are per-connection) in *Beheer >
  Authenticatie*.
- NO new top-level menu, NO per-adapter settings page, NO inline
  provider-create on connections (Rule 3 "picker only").
- This is the exact split ADR-017 Rule 7 already sanctions for this adapter —
  no ADR amendment needed.

## Threat notes

- **Replay:** one-time code and envelope `jti` are single-use (second
  redemption → reject + audit log); SAML `InResponseTo` must match an
  outstanding request nonce; assertions outside `NotBefore/NotOnOrAfter` are
  rejected; all artefacts ≤ 60s TTL except the IdP assertion window itself.
- **Audience confusion:** SAML `AudienceRestriction` / OIDC `aud` must match
  our SP/RP EntityID; the envelope is bound to the initiating consumer +
  `organisation` from the signed `state` — an envelope minted for tenant A is
  rejected by portaliq when redeemed in tenant B's context; the `use:
  "idp-envelope"` claim means neither portaliq's session resolver nor its A6
  assertion consumers will ever accept an envelope as a session/assertion
  (and vice versa — a portal session can't be replayed into the exchange
  endpoint).
- **IdP-initiated flows: rejected.** Any response without a matching
  outstanding `InResponseTo`/`state` is refused. Unsolicited SSO is a classic
  session-fixation / login-CSRF vector and no Wave-2 flow needs it.
- **BSN exposure:** see D2 — in-memory only, never logged (log scrubbing is a
  normative requirement in the delta spec), never stored, never in envelopes.
- **Fail-open via optional adapters:** the Log-adapter default **throws**; per
  the unsafe-auth-resolver gate, no `catch (Throwable) → null → skip check`
  shapes are permitted in the broker path.
- **Secret handling:** per-consumer exchange secrets + HMAC salts in the
  ADR-016 encrypted store, never in register JSON or logs; `hash_equals` for
  all comparisons.

## Risks / Trade-offs

- [HS256 shared secrets between openconnector and each consumer] → one secret
  per consumer, stored encrypted, rotatable via *Beheer > Authenticatie*;
  asymmetric upgrade path kept open (D4 alternatives).
- [Salted-HMAC fallback means the salt becomes linkability-critical] → salt
  per organisation in the encrypted store; rotation = subjectRef migration,
  documented as a known operational cost; prefer polymorphie (D1).
- [Vendor lock-in of flow details (SAML vs OIDC front-channel)] → spec keeps
  both; only the envelope + boundary + trust table are normative.
- [Spec-only change can rot if the chain stalls] → chain links + issues are a
  task in tasks.md; the fleet review already tracks Wave 2 against this spec.

## Migration Plan

Not applicable — spec-only (no schema, no data, no deploy). The
implementation chain (proposal §Chaining narrative) carries its own migration
plans; `migration.md` is skipped per schema `skipWhen` (no tables, columns,
OR schemas, or data transformations are introduced by this change).

## Open Questions

Mirrored from the proposal (single source: proposal.md §Open Questions —
decisions for the human): D1 broker vendor/contract + polymorphie, D2
certificate custody (doriath vs ADR-016 vault vs HSM), D3 per-organisation vs
shared SP metadata, D4 DigiD Midden/`low` acceptance floor, D5 Berichtenbox
identity-linkage deferral. One engineering question additionally open for the
runtime change: SAML library selection (bundled vs `onelogin/php-saml`-class
dependency) — decide at `portal-idp-broker-runtime` time with a
`composer audit` pass.
