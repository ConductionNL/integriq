# Tasks: allowlisted-expression-sources

Kind: code. Size S. Round 4 discovery depth study D-casetype-20, consolidated
candidate C-access-and-privacy-40, passer Valtimo `V-vr` and D-valtimo-48.
Openregister keeps the expression language under decision D3 and the rest of
cluster 4. Waits on nothing.

## Implementation tasks

### Task 1: The prefixed source contract
- **spec_ref**: `openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md#requirement-a-prefixed-value-source-is-resolved-through-one-contract-req-evs-001`
- **files**: `lib/Expression/ExpressionValueSourceInterface.php`, `lib/Expression/ExpressionValueSourceRegistry.php`, `lib/AppInfo/Application.php` (DI tag)
- [x] Implement. `ExpressionValueSourceInterface` carries `prefix()`,
      `resolve()`, `describe()` and `isSecret()`; the registry is first-wins and
      keeps the collision VISIBLE rather than swallowing the loser, because
      resolving by "last registered" would make the answer depend on app load
      order. It parses nothing and evaluates nothing.
- [x] Test, and the "consults no other source" half is asserted by counting
      calls on a second registered source — not by reading the code.

### Task 2: The `env:` source and its allowlist
- **spec_ref**: `openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md#requirement-env-resolves-only-an-allowlisted-key-req-evs-002`
- **files**: `lib/Expression/Source/EnvironmentValueSource.php`, the allowlist storage
- [x] Implement. 🔴 **What the allowlist admits: exact keys, one at a time.**
      No wildcard, no prefix pattern, no empty entry, no regular expression, no
      case-insensitive match — every one of those is a RULE rather than a list,
      and a rule also admits whatever is added to the environment next year,
      which is where the database password lives. `DB_*` looks like a careful
      narrowing until somebody names a variable `DB_ROOT_PASSWORD`.
      A refusal names the KEY and never the value, and throws rather than
      answering `''`: an empty string renders as nothing, so an email goes out
      with a blank host and the refusal is invisible exactly when it mattered.
      An unreadable stored list allows NOTHING, and a wildcard smuggled into
      storage is dropped on READ as well as on write.
- [x] Test, plus the case-sensitivity of the match and allowlisted-but-unset as its own answer.

### Task 3: The administration surface
- **spec_ref**: `openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md#requirement-the-allowlist-is-administered-and-every-change-is-recorded-req-evs-003`
- **files**: the admin settings panel, the audit write
- [x] Implement. 🔴 **Who may change it: an instance administrator, enforced
      TWICE** — `#[AuthorizedAdminSetting]` before the controller runs, and
      `requireAdmin()` again in every method body. A guard that lives only in an
      attribute disappears the moment somebody adds a route by hand or calls
      the method from another service, and this list is code-execution-adjacent.
      Each key is listed with its principal and timestamp; the value is never
      stored, never returned and never logged. Not even "is it set": telling a
      reader which allowlisted variables happen to be populated is a map of what
      is worth asking for.
- [x] Test. The least privileged principal that should be refused is probed:
      an ordinary signed-in user adding `DATABASE_PASSWORD` gets 403 and the
      list is unchanged. A test also asserts BOTH guards are present on all
      three endpoints.

### Task 4: Redaction before buffering
- **spec_ref**: `openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md#requirement-a-resolved-external-value-is-redacted-before-it-is-buffered-req-evs-004`
- **files**: the resolver, the redaction path of `execution-trace` REQ-003
- [x] The declaration half: `isSecret()` on the contract, and the registry
      answers it for a reference. `env:` declares EVERY value a secret — the
      distinction between `SMTP_HOST` and `SMTP_PASSWORD` is not one a key name
      draws reliably, and redacting all of them costs a hostname in a log while
      redacting by guess costs a password. An unresolvable reference is a
      secret too.
- [ ] The redaction CALL from `execution-trace` REQ-003's buffering path. The
      recorder is the caller; wiring it wants that change's owner, and asserting
      "the buffer holds no value" wants the recorder rather than a double.
- [ ] Test, with the wiring above.

### Task 5: Declared write capability
- **spec_ref**: `openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md#requirement-writing-back-is-declared-and-absent-unless-declared-req-evs-005`
- **files**: the contract, the `env:` source
- [x] Implement. `describe()` states `writable: false`, so a caller can ask
      BEFORE it tries; `store()` throws naming the prefix. The Valtimo interface
      this mirrors has a `store()` half, so a caller written against it WILL
      try, and answering "done" while changing nothing is how a screen reports
      a saved value that was never saved.
- [x] Test

### Task 6: Coordination, docs and the hand-offs
- **files**: `docs/`, Dutch and English strings, this change's row in `competitor-parity-2026-09`
- [ ] Tell the openregister lane that the prefix registry exists, so `field-rules-by-state`, `lifecycle-declarative-conditions` and the JSON-AST evaluator ask it rather than reading the environment themselves
- [ ] Say in the same message that C-access-and-privacy-40 sits in cluster 4, which is openregister's, and that this change takes only the source half
- [ ] Point `rule-pipeline` and `flow-token-helper` at the registry so integriq has one reach outward and not three
- [ ] Test (`tests/e2e/expression-value-sources.spec.ts`, `openspec validate allowlisted-expression-sources --type change --strict`)
