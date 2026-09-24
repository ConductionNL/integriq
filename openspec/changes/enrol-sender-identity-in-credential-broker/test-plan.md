# Test Plan: enrol-sender-identity-in-credential-broker

The three cases that carry this change are TC-1, TC-2 and TC-3. Each covers a
failure that ships **silently** — no exception, no failing build, no error in the
log — which is why they are specified rather than left to judgement.

## Test Cases

### TC-1: The private key is gone from an administrator's read
- **spec_ref**: `openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-signing-key-is-held-in-the-broker-not-in-the-register`
- **type**: security
- **persona**: Sem (developer/operator with admin rights)
- **preconditions**: A `sender_identity` carrying an inline `smimePrivateKey`; the write-only fragment deployed
- **steps**: Read the object over the generic OpenRegister object API **as an administrator**. Check the response body, the `@self.relations` mirror, and a list/search response over the same schema.
- **expected result**: No PEM private key material in any of the three. `smimePrivateKeyRef` IS present.
- **test command**: `/test-api` + PHPUnit on the render boundary
- **why as an administrator**: `writeOnly` stripping is unconditional, so an admin-visible key means the fragment is not in effect. A non-admin read passes whether the fragment works or not, and would prove nothing.

### TC-2: Signing still obtains key material after the field is write-only
- **spec_ref**: `openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered`
- **type**: regression
- **persona**: Sem
- **preconditions**: An identity with `signOutgoing: true` whose key is held in the broker; the write-only fragment deployed; `SenderIdentityService` reading with `_rbac: false`
- **steps**: Protect a message through `OutboundSecurityService::protect()`.
- **expected result**: `signed: true` and a signed payload.
- **test command**: `/test-functional` + PHPUnit on the identity and security services
- **why it matters**: this is the regression that ships silently. Without the system-context read the key reads as empty, `protect()` returns `signed: false` with *"This identity is set to sign but carries no S/MIME certificate and key"*, and the message **still sends** — unsigned, under a reason that reads like an operator's misconfiguration. Nothing fails. Nothing logs an error.

### TC-3: An unresolvable key is reported differently from an absent one
- **spec_ref**: `openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered`
- **type**: functional
- **persona**: Sem
- **preconditions**: Two identities — one whose `smimePrivateKeyRef` the broker cannot resolve, one with no key configured at all
- **steps**: Protect a message under each.
- **expected result**: Neither is reported signed. The recorded reasons **differ**: the first says the key could not be resolved, the second says none is configured.
- **test command**: PHPUnit on the security service
- **why it matters**: TC-2's failure is only detectable if these two causes do not share a message. If they do, a stripped field is indistinguishable from an unconfigured identity for as long as nobody checks — which is exactly how the original defect survived review.

### TC-4: A mint that does not verify changes nothing
- **spec_ref**: `openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-signing-key-is-held-in-the-broker-not-in-the-register`
- **type**: security
- **persona**: Sem
- **preconditions**: An identity holding an inline key; a broker double whose resolve returns material differing by one byte
- **steps**: Run the migration for that identity.
- **expected result**: The inline value is byte-identical to before. No reference is written. The failure names the identity.
- **test command**: PHPUnit on `InlineSecretMigrationExecutor`
- **why it matters**: verify-before-null is the entire safety property. A later refactor that finds the verification redundant would destroy keys, so the test exists to refuse that refactor.

### TC-5: The `source` migration path is unchanged by the generalisation
- **spec_ref**: `openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-signing-key-is-held-in-the-broker-not-in-the-register`
- **type**: regression
- **persona**: Sem
- **preconditions**: The existing `source` inline-secret tests, unmodified
- **steps**: Run them against the generalised planner and executor.
- **expected result**: All pass with no edits to the test files.
- **test command**: `vendor/bin/phpunit tests/Unit/Service/Security/`
- **why it matters**: the generalisation touches working, shipped code protecting live credentials. Needing to edit these tests to make them pass would mean the `source` path changed behaviour, which is out of scope.

### TC-6: The PEM guard refuses key material and accepts a reference
- **spec_ref**: `openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-012-an-inline-secret-is-refused-where-a-reference-belongs`
- **type**: functional
- **persona**: Henk (operator configuring an identity)
- **preconditions**: None — the guard is a schema `pattern`, so there is no debug exemption to configure
- **steps**: (a) Write `-----BEGIN PRIVATE KEY-----…` into `smimePrivateKeyRef`. (b) Write a genuine credential reference into the same field. (c) Leave the field empty.
- **expected result**: (a) refused by schema validation, naming `smimePrivateKeyRef` as the property that failed. (b) accepted. (c) accepted — an identity that has not been enrolled yet is a normal state.
- **test command**: `vendor/bin/phpunit tests/Unit/Settings/SenderIdentityRefPatternTest.php`
- **why both halves**: a guard tested only on the refusal can be trivially over-broad. (b) is what stops it refusing legitimate references and training operators to route around it.

### TC-7: Migration reporting reflects the estate
- **spec_ref**: `openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-signing-key-is-held-in-the-broker-not-in-the-register`
- **type**: functional
- **persona**: Sem
- **preconditions**: One identity with an inline key, one already migrated
- **steps**: `occ integriq:migrate-inline-secrets --dry-run --json`, migrate, re-run.
- **expected result**: First run lists the unmigrated identity and reports `"clean": false` and writes nothing. After migrating, `"clean": true`. Counts reconcile: identities reported before == identities carrying a reference after, zero remaining inline.
- **test command**: PHPUnit on the planner + command
- **why it matters**: `clean` is the Phase D gate. If it can report true while an inline key remains, Phase D removes the property and destroys it.

## Coverage Summary

| Requirement | Covered by |
|---|---|
| REQ-OSI-010 (broker custody, verify-before-null) | TC-1, TC-4, TC-5, TC-7 |
| REQ-OSI-011 (signing keeps working, honest failure) | TC-2, TC-3 |
| REQ-OSI-012 (inline secret refused) | TC-6 |
| REQ-OSI-003 (modified — private brokered, certificate readable) | TC-1 |

Every scenario in the spec delta maps to at least one case. TC-3 and TC-6(b) have no
matching scenario in the "happy path" sense — they exist because the *absence* of a
distinction is the defect, which a scenario asserting correct behaviour cannot catch.

## Not covered, deliberately

- **That keepiq specifically holds the credential.** `CredentialStoreResolver` picks
  the keepiq leaf or the Nextcloud vault leaf by eligibility and both satisfy this
  change. Asserting which leaf answered would test OpenRegister's resolver.
- **End-to-end signing in a browser.** A SCIM-style machine flow and a minted broker
  credential cannot be staged in Playwright; the spec scenarios carry `@e2e exclude`
  with that reason.
- **Phase D property removal.** Separate, gated change.
