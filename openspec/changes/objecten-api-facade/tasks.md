# Tasks: objecten-api-facade

> 🔑 **This PR builds the three pieces that decide correctness, and no HTTP.**
> The mapping (who a uuid stands for), the token matrix (who may touch it) and
> the record shape (what leaves the building). Each is pure, each is the thing
> a later handler would have to get right anyway, and each fails invisibly if
> it is got wrong — a name-inferred mapping writes into the wrong register, a
> permission check in the wrong order reads a register for an unauthenticated
> caller, and a blocklisted leak guard ships the next OpenRegister field to
> every counterparty in the landscape.
>
> **Which parts of each standard are implemented is stated in the PR body,
> route by route, rather than implied.** Nothing here serves a request yet.

## Implementation tasks

### Task 1: The objecttype mapping and its configuration
- **spec_ref**: `openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-an-objecttype-is-a-declared-mapping-onto-a-register-and-schema-req-oaf-001`
- **files**: `lib/Settings/integriq_register.json` (the `objecttype` schema), `lib/Service/Objecten/ObjecttypeRegistry.php`
- [x] `ObjecttypeRegistry`: uuid, name, register, schema, allowed versions.
      The mapping is DECLARED — a declaration missing any of the four is
      refused naming it, a second declaration for one uuid is refused rather
      than overwriting (first wins, and the loser is visible), and the uuid is
      the published identity so a reseed does not move it. An empty version
      list means every version, which is a declared default; a list that IS
      present is closed, because answering with a newer version answers a
      different question than the consumer asked.
- [ ] The `objecttype` schema in `lib/Settings/integriq_register.json`, so a
      declaration has somewhere to live. The registry takes declarations as
      data precisely so the storage decision is separable.
- [x] Test

### Task 2: The Objecttypen API v2
- **spec_ref**: `openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-the-objecttypen-api-serves-the-schema-it-stands-for-req-oaf-002`
- **files**: `lib/Service/Objecten/ObjecttypeEndpointHandler.php`, the endpoint seeds
- [ ] The Objecttypen API handler and its three routes. Not started.
- [ ] Test

### Task 3: The Objecten API v2 read path
- **spec_ref**: `openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-the-objecten-api-reads-objects-in-the-standards-shape-req-oaf-003`
- **files**: `lib/Service/Objecten/ObjectEndpointHandler.php`, `lib/Service/Objecten/ObjectRecordTranslator.php`
- [x] The RECORD SHAPE half: `ObjectRecordTranslator` renders `url`, `uuid`,
      `type` and `record` with the standard's fields, and strips the
      OpenRegister metadata envelope from `record.data`.
      🔴 The leak guard is an ALLOWLIST, not a blocklist: `foreignFieldsIn()`
      names every field outside the standard's vocabulary, so a field added to
      the translator without being added to the vocabulary fails a test rather
      than shipping to every counterparty in a release nobody connects to the
      leak.
- [ ] The QUERY half: `type`, `data_attrs`, `date`, `registrationDate`,
      `ordering`, pagination and the geometry search. Each needs the object
      service and a seeded register, and the geometry query plan must be read
      against one rather than assumed — which the change's own verification
      section already says.
- [x] Test, including the leak guard AND a control proving the guard reports a foreign field rather than reporting nothing whatever it is given.

### Task 4: The Objecten API write path
- **spec_ref**: `openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-a-write-lands-in-openregister-and-announces-req-oaf-004`
- **files**: `lib/Service/Objecten/ObjectEndpointHandler.php`, `lib/Service/NotificatiesPublisher.php`
- [ ] The write path and the announcement. Not started: both want the object service and the publisher.
- [ ] Test

### Task 5: Tokens with a permission per objecttype
- **spec_ref**: `openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-a-token-carries-a-permission-per-objecttype-req-oaf-005`
- **files**: `lib/Service/Objecten/ObjectenTokenService.php`, the token schema, the credential broker resolver
- [x] `ObjectenTokenService`, the whole matrix. 🔴 FAIL-CLOSED ORDERING is
      the point: the token is resolved BEFORE an objecttype is looked up and
      before any register is touched, because a lookup that happens first is a
      lookup an unauthenticated caller caused — a timing oracle whether or not
      the answer comes back. 401 no token or unknown token, 404 unknown
      objecttype, 403 refused objecttype, 403 read-token-writing.
      The key is resolved through the broker by REFERENCE and compared with
      `hash_equals`; a declaration carrying a literal `key`, `secret` or
      `token` is refused, because the way a key reaches a configuration export
      is somebody pasting it into the configuration.
      🔑 NAMED, not hidden: the 404/403 split means a VALID token can
      enumerate which objecttypes an instance publishes. That is the
      standard's shape and what interoperability needs; it is asserted in a
      test so the consequence is visible rather than incidental.
- [x] Test: scoped-to-one refused on a second, read token refused a write,
      six malformed `Authorization` headers refused, the scheme matched
      case-insensitively and the key exactly, an unresolvable credential
      authenticating nobody, and a refusal that does not echo the key it
      refused.
- [ ] The log assertion, which wants a logger on a request path that does not
      exist yet.

### Task 6: Declaration, throttling, catalog entry, docs
- **spec_ref**: `openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-a-leaf-app-declares-the-objecttypes-it-publishes-req-oaf-006`
- **files**: the endpoint declaration reader, the throttle configuration (ADR-082), `lib/Settings/catalog.seed.json`, Dutch and English strings, docs
- [ ] Implement
- [ ] Test (`tests/e2e/objecten-api-facade.spec.ts`, Newman over the two APIs)

## Verification

- [ ] `openspec validate objecten-api-facade --strict` passes
- [ ] PHPUnit run in the container, exit code read rather than the summary line
- [ ] The geometry search query plan read against a seeded register, not assumed

## Cross-repo follow-ups

- [ ] Tell dossiq to declare its `caseObject` types as objecttypes and delete the controller answering today; the task sits in dossiq's `competitor-parity-2026-09`
- [ ] Re-point register row 12.3 at this change; the openregister lane already moved the owner
