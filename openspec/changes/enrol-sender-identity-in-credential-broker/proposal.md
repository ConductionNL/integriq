---
kind: code
---

# Proposal: enrol-sender-identity-in-credential-broker

## Summary

`sender_identity.smimePrivateKey` holds an S/MIME signing key inline, in
plaintext, and returns it over the generic OpenRegister object API to anyone who
can read the schema — despite the property's own description stating *"Write only;
it is never read back over the API."* Every other secret-bearing schema in this app
was migrated to the `credentialRef` custody model under ADR-064; `sender_identity`
is the one that never enrolled. This change enrols it, so the key lives in the
credential broker and the schema holds only a reference the operator can read and
verify.

## Motivation

Found during the security review of PR #1983 (review `5260087915`, blocker 2).

The property carries `type`, `description` and `title` and nothing else — no
`writeOnly`, no schema `authorization` block, no `register.d` fragment. Combined
with OpenRegister's read default (`DEFAULT_CLOSED_WRITE_ACTIONS` is
`create/update/delete/destroy`, so `read` is granted when no authorization block is
present), the signing key is readable by any authenticated account on the instance.
Whoever holds it can sign mail as the organisation.

Why the full migration rather than a one-line `writeOnly` fragment:

1. **A `writeOnly` marker hides the key; it does not stop storing it.** The key
   would remain in the register in plaintext, which is ADR-007's open problem, not
   a fix for it.
2. **`writeOnly` alone is actively hostile to the operator.** Once the field is
   never returned, nobody can check what is configured. The whole point of the
   reference model is that the *reference* stays readable — an operator has to be
   able to see which credential entry an identity points at, and which one to
   correct when signing fails.
3. **The pattern already exists and has run.** `source` walked this path:
   `InlineSecretMigrationPlanner` → `InlineSecretMigrationExecutor` (mint → verify
   → write ref → null) → `RemoveMigratedSourceSecretFields`. This change adds the
   tenth schema to machinery that is built, tested and in use.

**Nothing is required from keepiq.** The chain already exists end to end:

```
  integriq  {credentialRef}
      │
      ▼
  OpenRegister  CredentialBrokerService  (mint / resolveInjectable)
      │
      ▼
  CredentialStoreResolver
      ├── keepiq leaf      ← preferred, when all four eligibility conditions hold
      └── NC-vault leaf    ← fail-closed fallback
```

`CredentialStoreResolver::CREDENTIAL_APP` is `keepiq` and its `resolve()` returns
the keepiq leaf whenever the app is enabled, its service classes and
application-scoped seam methods exist, and OpenRegister's self-registration state
is present in `IAppConfig`; otherwise it returns the Nextcloud vault leaf. So this
change works today with or without keepiq installed, and starts using keepiq
automatically once it is eligible — nothing to build there, and nothing to wait for.

There is one cross-repo item, but it is in OpenRegister rather than keepiq and it
only surfaced while writing the design: the provider catalogue has no entry that
describes a private key. See Cross-Project Dependencies and Open Question 4.

The Phase-C blocker that once held this migration up (organisation-scoped
credentials could not be minted or resolved without a live user session) is
resolved by openregister#450 and or#440.

Why now: the review blocks PR #1983 on this finding, and the feature is inert
today — `OutboundSecurityService::protect()` has no callers, so no key can be in
productive use yet. Migrating a field nobody depends on is as cheap as this will
ever be.

## Capabilities

### Modified Capabilities

- `outbound-sender-identity` — the identity's S/MIME material moves from an inline
  property to a brokered credential reference, and the requirement gains an
  explicit statement that the signing key is never returned by a read.

### New Capabilities

None.

## Scope

### In Scope

- Add `smimePrivateKeyRef` to `sender_identity`, readable, holding a
  `{credentialRef}` placeholder.
- Mark the legacy inline `smimePrivateKey` `writeOnly: true` via a
  `register.d/99-sender-identity-secrets-writeonly.json` fragment, so that
  whatever is already stored stops being returned immediately, before any
  migration runs.
- Extend the inline-secret migration to cover `sender_identity.smimePrivateKey`
  with the existing mint → **verify** → write-ref → null sequence, reusing
  `InlineSecretMigrationPlanner` / `InlineSecretMigrationExecutor`.
- Resolve the reference at signing time through the broker, in system context.
- A write-time guard refusing raw PEM in the ref field outside debug, modelled on
  `FlowConfigGuard`. PEM is self-identifying (`-----BEGIN`), so the check is exact
  and cannot false-positive on a legitimate reference.
- An `authorization` block on `sender_identity` restricting reads, since the
  schema will still carry the certificate and identity configuration.

### Out of Scope

- **The other nine schemas from the same review finding.** `sender_identity` is
  here because it holds a secret; the rest is blocker 3 and awaits Ruben's answer
  on whether those schemas are open by design.
- **Removing the inline `smimePrivateKey` property.** Phase D removes a property
  only once the migration reports `clean: true` across the estate, and that is a
  separate, gated step — the same discipline `source` followed.
- **Any change to keepiq.** Its leaf already exists and is already preferred.
- **Anything in OpenRegister beyond the one provider entry** described under
  Cross-Project Dependencies. The broker, the store resolver and the sessionless
  mint are all in place and are consumed as-is.
- **Field encryption at rest via `x-openregister-encrypted`.** It is available and
  integriq uses it nowhere, but a brokered credential is not stored in the register
  at all, so encrypting the register column is not the right tool for this field.
  Worth a separate look for fields that must stay inline.

## Approach

Three steps, each safe on its own and each leaving the system correct if the next
never happens.

1. **Stop the bleeding.** The `writeOnly` fragment takes effect on deploy and
   closes the disclosure for data already in the register, without waiting for any
   migration.
2. **Move custody.** The migration mints the key into the broker, verifies it
   resolves back byte-for-byte, writes the reference, and only then nulls the
   inline value. Verify-before-null is the existing executor's rule and is what
   makes the step reversible in practice.
3. **Read through the reference.** Signing resolves the ref via the broker. This
   is also where the `_rbac: false` question is settled — see Risk 1.

## New Dependencies

None. `CredentialBrokerService`, `CredentialStoreResolver`,
`InlineSecretMigrationPlanner`, `InlineSecretMigrationExecutor` and
`RecordInlineSecretMigrationStatus` all exist and are in use.

## Impact

| Surface | Change |
|---|---|
| `lib/Settings/integriq_register.json` | `smimePrivateKeyRef` added to `sender_identity` |
| `lib/Settings/register.d/99-sender-identity-secrets-writeonly.json` | new |
| `lib/Settings/register.d/99-sender-identity-lockdown.json` | new — the `authorization` block |
| `lib/Service/Security/InlineSecretMigration*` | `sender_identity` added to the migrated set |
| `lib/Outbound/Identity/SenderIdentityService.php` | resolves the ref; reads in system context |
| `lib/Outbound/Identity/OutboundSecurityService.php` | consumes the resolved key |
| `lib/Command/MigrateInlineSecrets.php` | reports the new field |

## Cross-Project Dependencies

**Nothing is needed from keepiq.** OpenRegister's broker and keepiq's leaf are both
in place, and `CredentialStoreResolver` falls back to the Nextcloud vault leaf when
keepiq is not eligible, so custody works either way.

**One thing is needed from OpenRegister**, found while writing the design and not
visible from the integriq side: `lib/Settings/credential-providers.json` is
**runtime-immutable and read directly by the broker**, and no app can register a
provider into it. Its generic entries are `generic-apikey`, `generic-bearer`,
`generic-basic` and `generic-oauth2` — none of which describes a PEM private key.

Every inject-only provider stores exactly one opaque secret string, so
`generic-apikey` would *function* as a container. It would also mislabel an S/MIME
signing key as an API key everywhere an operator looks at it, and a provider label
is not cheap to change afterwards: the executor's own docblock warns that renaming
the minted `APP_ID` "fails every brokered resolve CLOSED", and the same coupling
applies to the provider a credential was minted under. Choosing the wrong label now
buys a re-mint later.

So this change SHOULD be sequenced behind a small OpenRegister PR adding a
`generic-private-key` provider entry. That is a single JSON entry in a file whose
immutability is the point, which makes it a low-risk change — but it is a
cross-repo dependency and a merge order, and it was not visible when this proposal
was first written. See Open Question 4.

## Risks

### Risk 1: `writeOnly` stripping starves the signing path

**Severity:** High — **Mitigation:** OpenRegister strips write-only values only
when `_rbac === true`, so a consumer reading in system context still sees them.
`SenderIdentityService` passes no `_rbac: false` anywhere today. If the field is
marked `writeOnly` without switching that read, signing will silently observe an
empty key and fall through to *"This identity is set to sign but carries no S/MIME
certificate and key"* — a message that reads like a configuration problem, on a
message that then goes out unsigned. **The fragment and the system-context read
must land in the same change**, exactly as `99-source-secrets-writeonly.json`
documents for its own engine. A test asserts the signing path still obtains key
material after the fragment is applied.

This is the same class of trap that `harden-scim-consumer-authorization` hit from
the other direction, where an RBAC-filtered policy read would have refused every
SCIM write.

### Risk 2: A minted credential is unresolvable later and signing breaks

**Severity:** Medium — **Mitigation:** The executor verifies a byte-for-byte
round-trip before nulling anything, so a key is never lost to a mint that did not
take. Beyond that, `CredentialRelinkRequiredEvent` already exists in OpenRegister
for the re-link case, and the readable `smimePrivateKeyRef` is what makes the
failure diagnosable at all — an operator can see which entry to repair.

### Risk 3: The estate holds inline keys nobody migrates

**Severity:** Low — **Mitigation:** `RecordInlineSecretMigrationStatus` already
reports the gate into appconfig, and `MigrateInlineSecrets --json` emits
`"clean": true` only when zero objects hold an unmigrated inline secret. Extending
both to `sender_identity` means an unmigrated instance is visible rather than
assumed. In practice the population is expected to be empty: `protect()` has no
callers, so no working signing configuration exists yet.

## Rollback Strategy

Steps 1 and 3 are code and configuration: `git revert`, per repository policy,
never a history rewrite.

Step 2 writes data, so it is not revertible by reverting code. It is safe for a
different reason: it only ever nulls an inline value after the minted credential
has resolved back byte-for-byte, so the secret exists in the broker before it
stops existing in the register. A rollback after migration means resolving the
references back into inline values, which is a deliberate operation and not
something a revert should do implicitly.

## Open Questions

1. **Should `smimeCertificate` move too?** A certificate is public by nature, so
   the default answer is no — it stays inline and readable. Confirm, because
   treating it as a secret would needlessly break certificate inspection in the UI.
2. **What group should the `sender_identity` `authorization` block name for
   `read`?** `admin` is the safe default and matches `99-source-lockdown.json`, but
   the sender-identity page may be intended for a wider operator group. This
   overlaps the blocker-3 conversation with Ruben.
3. **Does the write-time PEM guard refuse, or accept-and-migrate?** Refusing is
   cleaner and matches `FlowConfigGuard`; accepting and immediately minting is
   friendlier to an operator pasting a key. Recommend refusing outside debug, with
   the message naming the ref field.
4. **`generic-private-key` in OpenRegister first, or reuse `generic-apikey`?**
   Recommend adding the provider, because a mislabelled provider is expensive to
   correct once credentials are minted under it. The counter-argument is that it
   couples this change to an OpenRegister merge, and the mislabelling is cosmetic.
   **This is the one decision that blocks starting implementation**, because it
   determines whether this change has a `depends_on`.
5. **Does the migration machinery generalise, or get a sibling?**
   `InlineSecretMigrationPlanner` hardcodes `SCHEMA = 'source'` and a
   `PROVIDER_MAP` keyed by source field names. Extending it to a second schema is
   either a generalisation of that class or a parallel planner. Recommend
   generalising — two near-identical planners is how the verify-before-null rule
   ends up implemented twice and correctly once. Detail in design.md.
