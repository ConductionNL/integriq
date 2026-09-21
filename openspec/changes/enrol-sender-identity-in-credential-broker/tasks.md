# Tasks: enrol-sender-identity-in-credential-broker

>
> **Tasks 1 and 2 ship in the same commit.** Task 1 alone makes the key unreadable;
> Task 2 is what keeps signing able to obtain it. Splitting them across releases
> sends mail unsigned under a message that reads like an unconfigured identity.

## Implementation Tasks

### Task 1: Declare the readable reference and close the disclosure
- **spec_ref**: `openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-signing-key-is-held-in-the-broker-not-in-the-register`
- **files**: `lib/Settings/integriq_register.json`, `lib/Settings/register.d/99-sender-identity-secrets-writeonly.json`, `lib/Settings/register.d/99-sender-identity-lockdown.json`
- **acceptance_criteria**:
  - GIVEN the deployed schema WHEN a `sender_identity` is read as an administrator THEN no S/MIME private key appears in the body, in `@self.relations`, or in a list or search response
  - GIVEN the same read THEN `smimePrivateKeyRef` IS returned — the reference is readable on purpose, so a failing identity can be diagnosed
  - `smimeCertificate` stays inline and readable; it is public material and is not marked write-only
  - The lockdown fragment carries an `authorization` block with an explicit `read` entry, because an absent block grants reads unconditionally
  - Each fragment's `_comment` states what it closes and why, matching the convention in `99-source-lockdown.json`
  - The inline `smimePrivateKey` property is NOT removed — that is Phase D, gated on the estate reporting `clean: true`
- [x] Implement
- [x] Test

### Task 2: Resolve through the broker in system context, and report failure honestly
- **spec_ref**: `openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-011-signing-keeps-working-once-the-key-is-brokered`
- **files**: `lib/Outbound/Identity/SenderIdentityService.php`, `lib/Outbound/Identity/OutboundSecurityService.php`
- **acceptance_criteria**:
  - GIVEN the write-only fragment from Task 1 is deployed WHEN a message is protected THEN signing still obtains key material — `SenderIdentityService` reads with `_rbac: false` AND `_render: false`, because write-only stripping happens in the render pass and is computed from the schema alone — it has no `_rbac` term, and `find()` renders by default
  - GIVEN a reference the broker cannot resolve WHEN a message is protected THEN the recorded reason says the key could not be resolved, NOT "carries no S/MIME certificate and key"
  - GIVEN an identity with no key configured at all THEN the existing reason is unchanged
  - A test asserts the two reasons differ, because sharing one message is how a stripped field masquerades as a configuration mistake
  - Ships in the SAME commit as Task 1
- [x] Implement
- [x] Test

### Task 3: Generalise the migration machinery to a second schema
- **spec_ref**: `openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-signing-key-is-held-in-the-broker-not-in-the-register`
- **files**: `lib/Service/Security/InlineSecretMigrationPlanner.php`, `lib/Service/Security/InlineSecretMigrationExecutor.php`, `lib/Command/MigrateInlineSecrets.php`, `lib/Repair/RecordInlineSecretMigrationStatus.php`
- **acceptance_criteria**:
  - Replace the hardcoded `SCHEMA = 'source'` with a schema→fields→providers map per design.md; do NOT add a parallel planner, so verify-before-null stays implemented once
  - The map expresses `refField`: `source` writes its placeholder in place, `sender_identity` writes into the separate readable `smimePrivateKeyRef`
  - GIVEN a mint that does not resolve back byte-for-byte WHEN the field is processed THEN the inline value is left exactly as it was, no reference is written, and the failure names the identity
  - Mint at `SCOPE_ORGANISATION`, never `personal`; `APP_ID` stays `'openconnector'` — renaming it fails every existing brokered resolve closed
  - The existing `source` migration path keeps its current behaviour, proven by the existing tests still passing unchanged
  - `--dry-run --json` reports `"clean": true` only when no schema in the map holds an unmigrated inline secret
  - Mint `smimePrivateKey` under the existing `generic-apikey` provider (decided 2026-09-20). It is `inject_only: true`, so `resolveInjectable()` returns the PEM and integriq signs locally; its `authScheme` is unused. The label is wrong and correcting it later means a re-mint — do NOT add a provider to OpenRegister in this change (tracked as ConductionNL/openregister#4008)
- [x] Implement
- [x] Test

### Task 4: Refuse key material written where a reference belongs
- **spec_ref**: `openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-012-an-inline-secret-is-refused-outside-debug`
- **files**: `lib/Settings/integriq_register.json` — implemented as a JSON Schema `pattern`, not a PHP guard; integriq has no write hook (see design.md, Implementation deviations)
- **acceptance_criteria**:
  - GIVEN an instance not in debug configuration WHEN a write places `-----BEGIN` PEM material in `smimePrivateKeyRef` THEN the write is refused and the refusal names the field that accepts key material
  - GIVEN a genuine reference THEN the write is accepted — a test asserts this, because a guard that refuses legitimate input trains operators to work around it
  - Match on the `-----BEGIN` header only; do NOT classify by entropy or heuristics
  - Model the refusal on `FlowConfigGuard`
- [x] Implement
- [x] Test

### Task 5: Seed data for the modified schema
- **spec_ref**: `openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-signing-key-is-held-in-the-broker-not-in-the-register`
- **files**: `lib/Settings/integriq_register.json`
- **acceptance_criteria**:
  - Three `sender_identity` objects per the design's Seed Data table (`algemeen`, `vergunningen`, `no-reply-nieuwsbrief`), each with the `@self` envelope (`register`, `schema`, `slug`)
  - NO seed object carries key material, real or placeholder, and none carries a PEM-shaped string — a fixture that looks like a key is indistinguishable from a leaked one to `gitleaks`
  - Every seeded identity sets `signOutgoing: false`, because a signing identity would need a broker credential that seed data cannot mint
  - Related seeds: a `mail_message` per identity for the timeline, and a `recipient_opt_out` on `no-reply-nieuwsbrief`
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [ ] Manual testing against acceptance criteria
- [x] Code review against spec requirements

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`)
- [ ] Newman/Postman tests for new/changed API endpoints — N/A, no endpoint is added or changed in shape
- [ ] Vitest tests for new/changed frontend logic — N/A unless the sender-identity page surfaces the guard's refusal
- [ ] Playwright e2e for new/changed user journeys — N/A, the journey needs a minted broker credential that cannot be staged in a browser

## Notes

The three checks that carry the risk, and why each exists:

- **Read as an administrator**, not as an ordinary user, when asserting the key is
  gone. `writeOnly` stripping is unconditional, so an admin-visible key means the
  fragment is not in effect — a non-admin read would pass either way.
- **Assert signing still obtains key material** after Task 1. This is the one
  regression that ships silently: mail keeps sending, just unsigned.
- **Assert the two failure reasons differ.** Sharing one message between "no key
  configured" and "key unresolvable" is what lets a stripped field look like an
  operator's mistake for as long as nobody checks.

Deliberately untested here: that keepiq specifically holds the credential.
`CredentialStoreResolver` picks the keepiq leaf or the Nextcloud vault leaf by
eligibility, and both satisfy this change — asserting which leaf answered would
test OpenRegister's resolver, not this change.
