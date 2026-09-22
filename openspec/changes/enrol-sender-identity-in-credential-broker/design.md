# Design: enrol-sender-identity-in-credential-broker

## Architecture Overview

The key stops living in the register and starts living in the broker. What stays on
the object is a reference, which is deliberately readable.

```
  BEFORE                                  AFTER
  ──────                                  ─────
  sender_identity                         sender_identity
    smimeCertificate  (public, readable)    smimeCertificate     (public, readable)
    smimePrivateKey   ← PEM, READABLE       smimePrivateKeyRef   ← "{credentialRef}"
         by anyone who may read                                     READABLE on purpose
         the schema                         smimePrivateKey      ← writeOnly, emptied
                                                                    once migrated
         │                                       │
         ▼                                       ▼
  OutboundSecurityService::protect()       CredentialBrokerService
    reads the property directly              ::resolveInjectable()
                                                  │
                                           CredentialStoreResolver
                                             ├── keepiq leaf   (preferred)
                                             └── NC-vault leaf (fallback)
```

The readable reference is the design, not a concession. A write-only secret with no
readable handle produces a system where signing fails and nobody can say which
credential is wrong. The reference is what makes that diagnosable.

## API Design

No endpoint changes. The change is visible in what an object read returns:

**Before:**
```json
{
  "displayName": "Gemeente Voorbeeld",
  "smimeCertificate": "-----BEGIN CERTIFICATE-----\n...",
  "smimePrivateKey": "-----BEGIN PRIVATE KEY-----\n..."
}
```

**After:**
```json
{
  "displayName": "Gemeente Voorbeeld",
  "smimeCertificate": "-----BEGIN CERTIFICATE-----\n...",
  "smimePrivateKeyRef": "{credentialRef}"
}
```

`smimePrivateKey` is absent from every response, administrators included, because
`writeOnly` stripping in OpenRegister is unconditional on the render boundary.

## Database Changes

No table, column or Nextcloud migration. The change is to an OpenRegister schema
plus a one-way data transformation of existing objects — specified in
`migration.md`, because it moves secret material and is not reversible by reverting
code.

## Nextcloud Integration

- **Controllers:** none changed.
- **Services:**
  - `OCA\Integriq\Outbound\Identity\SenderIdentityService` — resolves the reference.
    **Revised during implementation:** only the new `signingMaterial()` reads with
    `_rbac: false`. The design said "switches its own reads", which would have been
    wrong: `SenderIdentityController` uses this service to render identities to a
    person, so a blanket switch would hand a caller exactly what the lockdown in
    Task 1 refuses. `resolve()`, `all()` and `defaultIdentity()` stay RBAC-scoped.
  - `OCA\Integriq\Outbound\Identity\OutboundSecurityService` — consumes resolved key
    material rather than an inline property, and distinguishes "no key configured"
    from "key could not be resolved".
  - `OCA\Integriq\Service\Security\InlineSecretMigrationPlanner` / `…Executor` —
    generalised to a second schema. See "Generalising the planner".
  - `OCA\OpenRegister\Service\Credential\CredentialBrokerService` — consumed via the
    existing lazily-resolved FQCN constant; **not modified**.
- **Mappers/Entities:** none.
- **Events/Hooks:** `CredentialRelinkRequiredEvent` (OpenRegister) already exists for
  the re-link case and is consumed, not defined here.
- **OCP:** none for the write guard — it ships as a schema `pattern`, so there is no debug-mode check and no `IAppConfig` dependency (see Implementation deviations).

## Generalising the planner

`InlineSecretMigrationPlanner` currently hardcodes a single schema:

```php
public const SCHEMA = 'source';
public const SECRET_FIELDS = ['apikey', 'secret', 'password', 'jwt', 'authenticationConfig'];
public const PROVIDER_MAP  = ['apikey' => 'generic-apikey', 'password' => 'generic-basic', …];
```

Two options, and the recommendation is not close:

| | Generalise to a schema→fields map | A parallel `SenderIdentitySecretPlanner` |
|---|---|---|
| verify-before-null | implemented once | implemented twice |
| Phase D `clean` gate | one gate over all schemas | two gates to keep in agreement |
| diff size | larger, touches working code | smaller, touches nothing existing |
| risk | regression in the `source` path | the two drift, silently |

**Recommended: generalise.** The verify-before-null rule is the whole safety
property of this machinery, and the failure mode of duplication is that it gets
implemented correctly once. The `source` path is covered by existing tests, which is
what makes the refactor checkable.

Shape:

```php
public const MIGRATABLE = [
    'source' => [
        'fields'   => ['apikey', 'secret', 'password', 'jwt', 'authenticationConfig'],
        'providers'=> ['apikey' => 'generic-apikey', 'password' => 'generic-basic', …],
        'refField' => null,           // source writes the placeholder in place
    ],
    'sender_identity' => [
        'fields'   => ['smimePrivateKey'],
        'providers'=> ['smimePrivateKey' => 'generic-apikey'],  // interim — see below
        'refField' => ['smimePrivateKey' => 'smimePrivateKeyRef'],
    ],
];
```

Note the asymmetry in `refField`. `source` writes a `{credentialRef}` placeholder
into the same property; `sender_identity` writes the reference into a *separate*
readable property and empties the original. That difference is the point of this
change — the reference must be readable while the original becomes write-only — so
it has to be expressible rather than assumed.

`APP_ID` stays `'openconnector'`. The executor's docblock is explicit that every
credential minted so far carries that value and renaming it fails every brokered
resolve closed. It is a poor name for this app, and correcting it is a separate
migration with its own risk; it is not corrected here.

## The provider entry

`lib/Settings/credential-providers.json` in OpenRegister is runtime-immutable and
read directly by the broker — no app can register a provider into it. Its generic
entries are `generic-apikey`, `generic-bearer`, `generic-basic`, `generic-oauth2`.

An S/MIME private key is none of those. Every inject-only provider stores exactly
one opaque secret string, so `generic-apikey` would function as a container, and
this change could ship today by reusing it.

**Decided: reuse `generic-apikey`.** It is `inject_only: true`, and the catalogue's
own comment for it reads: *"resolved app-side via resolveInjectable(): the calling
app reads the raw key"*. `resolveInjectable()` returns the raw secret for any
inject-only provider that is not an OAuth2 token set, so integriq reads the PEM back
and signs locally. Its `authScheme` (`Authorization: {secret}`) is simply unused —
integriq is not making an HTTP request with this credential.

The cost is a mislabelled credential, and it is real: the provider is recorded on
the minted credential, so correcting it later means a re-mint. It is accepted
because the alternative serialises an OpenRegister merge in front of a release chain
that is already three repos deep (keepiq#712 waits on integriq's NC35 beta, which
waits on the #1983 findings, which is this change) in order to fix a label.

Tracked as ConductionNL/openregister#4008.

**Longer term this is not a provider-entry problem at all.** openregister#2720 case 3
("Signing operations") describes exactly this shape — *"the key signs a payload; it
is never transmitted. There is no request to proxy — the app needs a signature
back"* — and proposes a broker `sign` endpoint so the key never leaves custody. That
is strictly better than reading a PEM back into integriq's memory, and it makes the
provider label question moot. It is out of scope here and worth recording integriq
as a second affected app on that issue.

## Security Considerations

**What this closes.** An S/MIME signing key readable by any authenticated account.
Whoever reads it can sign mail as the organisation, and nothing in the schema
prevented the read.

**The trap that must not be sprung — and it is the whole risk of this change.**
OpenRegister strips `writeOnly` values only when `_rbac === true`. System-context
readers still see them. `SenderIdentityService` passes `_rbac: false` nowhere:

```
  fragment lands, read NOT switched
        │
        ▼
  identity['smimePrivateKey'] === ''
        │
        ▼
  protect(): "This identity is set to sign but carries no
              S/MIME certificate and key."
        │
        ▼
  message sent UNSIGNED, with a reason that reads like
  an unconfigured identity
```

The fragment and the system-context read land in the same commit. This is exactly
what `99-source-secrets-writeonly.json` documents for its own engine — it verified,
rather than assumed, that both consumer paths already passed `_rbac: false`. A test
asserts the signing path still obtains key material after the fragment is applied,
and REQ-OSI-011 requires the two failure reasons to be distinguishable so this can
never again masquerade as a configuration problem.

**Why the reference is readable.** A reference is not a secret. Making it write-only
would produce a system whose failures are undiagnosable, and an operator who cannot
diagnose a signing failure will eventually be given the private key to paste
somewhere — which is the state this change exists to leave.

**Why the write guard is narrow.** PEM is self-identifying by `-----BEGIN`. A
broader "does this look secret" heuristic would refuse legitimate references and
train operators to work around the guard. Narrow and exact beats clever here.

**Residual exposure.** Until an instance runs the migration, the inline value is
still stored — write-only, so no longer disclosed, but present. `RecordInlineSecretMigrationStatus`
makes that visible rather than assumed. The population is expected to be empty:
`protect()` has no callers, so no working signing configuration exists yet.

**Out of scope, stated plainly.** This does not encrypt anything at rest in the
register, and it does not address the other nine schemas from the same review.

## Declarative-vs-imperative decision (ADR-031)

Partly declarative, and the split is deliberate.

- **Declarative:** the new property, the `writeOnly` marker and the `authorization`
  block are `lib/Settings/integriq_register.json` plus two `register.d` fragments.
  No service class is introduced for any of them.
- **Imperative:** the migration and the write guard. ADR-031's own exception list
  covers this — a mint→verify→write→null sequence against an external credential
  broker is external integration with an ordering requirement, not a derived field,
  and there is no `x-openregister-*` annotation that expresses "move this value into
  another system and prove it arrived".

No lifecycle, aggregation, calculation, notification, relation or widget behaviour
is introduced, so none is being written imperatively that could have been declared.

## File Structure

```
lib/
  Settings/
    integriq_register.json                          (modified — smimePrivateKeyRef)
    register.d/
      99-sender-identity-secrets-writeonly.json     (new)
      99-sender-identity-lockdown.json              (new)
  Service/Security/
    InlineSecretMigrationPlanner.php                (modified — MIGRATABLE map)
    InlineSecretMigrationExecutor.php               (modified — refField handling)
    SenderIdentitySecretGuard.php                   (new — the PEM write guard)
  Outbound/Identity/
    SenderIdentityService.php                       (modified — resolve ref, _rbac: false)
    OutboundSecurityService.php                     (modified — distinguish failures)
  Command/
    MigrateInlineSecrets.php                        (modified — reports the new field)
tests/Unit/…                                        (matching tests)
```

## Seed Data

`sender_identity` is modified, so seed data is in scope — and the rule for it is
unusually simple: **no seed object carries key material, real or fake.**

A seeded identity sets `signOutgoing: false` and omits both `smimeCertificate` and
`smimePrivateKeyRef`. A seeded PEM-shaped string would be indistinguishable from a
leaked key to `gitleaks`, would train readers that keys belong in fixtures, and
would have to be excluded from the very scanner this change exists to satisfy.

Three objects, general-organisation shaped, all under
`@self: { register: integriq, schema: sender_identity, slug: <slug> }`:

| slug | displayName | address | isDefault | signOutgoing | quotingLevel |
|---|---|---|---|---|---|
| `algemeen` | Gemeente Voorbeeld | `info@example.org` | true | false | `last` |
| `vergunningen` | Vergunningen | `vergunningen@example.org` | false | false | `none` |
| `no-reply-nieuwsbrief` | Nieuwsbrief | `no-reply@example.org` | false | false | `none` |

The third exists so REQ-OSI-008 (mail to a no-reply address) has something to
exercise. Related items: each identity links to a `mail_message` seed for the
timeline, and `no-reply-nieuwsbrief` carries a `recipient_opt_out` so the opt-out
path is visible on install.

An identity demonstrating signing is deliberately **not** seeded. It would need a
credential in the broker, which seed data cannot mint, so it would be a permanently
broken fixture.

## Open Questions

1. `smimeCertificate` stays inline and readable — a certificate is public material.
   Confirm, because treating it as secret would break certificate inspection.
2. Which group the `sender_identity` `authorization` block names for `read`.
   Overlaps the blocker-3 conversation.
3. Refuse-vs-accept for the PEM write guard. Recommend refuse; see above.
4. ~~`generic-private-key`, or reuse `generic-apikey`.~~ **Resolved: `generic-apikey`.**
   No `depends_on`; implementation unblocked. Re-mint accepted as the cost.
5. Generalise the planner or add a sibling. Recommend generalise; see the table.


## Implementation deviations

Recorded as they happened, so the artifacts do not read as though the code
followed them exactly.

**The write guard is declarative, not a PHP class.** The design named
`SenderIdentitySecretGuard`. There is nowhere in integriq to hang it:
`SenderIdentityController` has no write endpoint, and identities are written
through the generic OpenRegister object API, which this app cannot intercept. The
refusal is therefore a JSON Schema `pattern` on `smimePrivateKeyRef`
(`^(?!.*-----BEGIN)[^\s]*$`), which OpenRegister enforces through Opis JsonSchema
on **every** write — including the one path a PHP guard could never see.

A consequence worth stating: the debug exemption is gone, because a schema pattern
cannot be conditioned on instance configuration. That is arguably better. An escape
hatch on a guard whose whole job is to stop key material being pasted where a
reference belongs is a hatch someone eventually leaves open.

**`planAll()` stays `source`-only, and `planEverything()` is new.** Folding a second
schema into `planAll()` would have changed the number
`RemoveMigratedSourceSecretFields` reads to decide whether SOURCE properties may be
removed — blocking source's Phase D on an unrelated schema's state. The OCC
command's dry-run gate uses `planEverything()` because the gate an operator reads
should be estate-wide; the repair step keeps the narrow one.

**The executor carries the run's schema as a property, not a parameter.**
`mintVerifyNull()` already takes nine arguments and a tenth would trip phpmd's
parameter-list rule, while being passed unchanged the whole way down. It is set
once at the top of a run and never mutated inside one.

**One real bug the tests caught.** `migrateSource()` re-classifies from fresh raw
data, and its `planSource()` call defaulted to `source`. Without threading the run's
schema into it, a `sender_identity` migration found no declared fields, migrated
nothing, and reported success with no error. Covered now by
`testASeparateReferenceFieldIsWrittenAndTheKeyEmptied`.
