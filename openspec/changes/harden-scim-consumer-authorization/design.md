# Design: harden-scim-consumer-authorization

## Architecture Overview

Today the SCIM endpoint answers one question — *is this credential valid?* — and
then acts on the answer as though it had asked a second one. This change adds the
second question and puts the decisive half of it below the controller.

**Before:**

```
  PATCH /api/scim/v2/Groups/{id}
        │
        ▼
  ScimController::authorize()
        │  authorizeApiKey(header: $presented, keys: [])
        │      keys is empty ⇒ scope loop never runs
        │      ⇒ resolveConsumerByApiKey() matches ANY consumer
        │
        ├─ invalid ──▶ 401 (undifferentiated, logged)
        └─ valid ────▶ returns null   ← identity discarded here
                          │
                          ▼
              ScimProvisioningService::setGroupMembers($groupId, $members)
                          │  no check on $groupId
                          ▼
                    IGroup::addUser() / removeUser()
```

**After:**

```
  PATCH /api/scim/v2/Groups/{id}
        │
        ▼
  ScimController::authorize()
        │  authorizeApiKey(...)  then  getResolvedConsumer()
        │
        ├─ invalid ────▶ 401 (undifferentiated, logged)
        └─ valid ──────▶ still returns null, AND records the resolved
                          consumer on $this->callingConsumer
                          │
                          ▼
              ScimProvisioningService::setGroupMembers($groupId, $members, $consumer)
                          │   throws DirectorySyncRefusalException on refusal
                          │
                          ├─ 1. $groupId === 'admin'?           ──▶ REFUSE  (never configurable)
                          ├─ 2. $groupId ∈ managedGroups union? ──▶ no: REFUSE (names the group)
                          │
                          ▼  both pass
                    IGroup::addUser() / removeUser()   (unchanged)
```

The two refusals live in the service, not the controller. That is the load-bearing
decision in this design: a controller gate protects the routes that remember to call
it, whereas a service refusal protects every present and future caller of the
service. The controller keeps the identity plumbing because that is where the
request is; the service keeps the guarantee because that is where the damage is.

## API Design

No endpoint is added, removed or renamed, and the success path is byte-identical.
Two refusal responses are new on existing routes.

### `PATCH /apps/integriq/api/scim/v2/Groups/{id}`

**Request** (unchanged):
```json
{
  "schemas": ["urn:ietf:params:scim:api:messages:2.0:PatchOp"],
  "Operations": [
    { "op": "add", "path": "members", "value": [{ "value": "<user-id>" }] }
  ]
}
```

**Response — refused, privileged group (new):** `403`
```json
{
  "schemas": ["urn:ietf:params:scim:api:messages:2.0:Error"],
  "status": "403",
  "detail": "This group cannot be managed over SCIM."
}
```

**Response — refused, unmanaged group (new):** `403`
```json
{
  "schemas": ["urn:ietf:params:scim:api:messages:2.0:Error"],
  "status": "403",
  "detail": "The group 'finance' is not managed by a directory connection."
}
```

Note the asymmetry, and it is deliberate. The unmanaged-group refusal **names the
group**, because that is a configuration mistake an operator must be able to
diagnose from the response. The `admin` refusal **does not** name it or explain the
rule, because that refusal is a security boundary and a caller probing it should
learn as little as possible. Both are fully attributed in the log.

`401` for a bad credential is unchanged and stays undifferentiated.

## Database Changes

None. No table, column, migration, OpenRegister schema or data transformation is
touched. `consumer` is explicitly *not* modified — adding a permission property to
it is the follow-up change, not this one.

## Nextcloud Integration

- **Controllers:** `OCA\Integriq\Controller\ScimController` — `authorize()` keeps its
  `?JSONResponse` return and additionally records the resolved consumer on a
  `private ?ObjectEntity $callingConsumer` property. **Revised during implementation:**
  the original design had `authorize()` return a union of consumer-or-response, which
  would have rewritten all nine call sites. Keeping the existing
  `$rejected = $this->authorize(); if ($rejected !== null)` idiom leaves eight routes
  untouched and confines the security-relevant diff to `authorize()` and
  `updateGroup()` — which matters more than idiomatic purity on a change a reviewer
  has to audit line by line. The controller is a per-request instance, so the
  property is request-scoped.
- **Services:**
  - `OCA\Integriq\Service\AuthorizationService` — **unchanged.** `getResolvedConsumer()`
    already exists (`:922`) and `authorizeApiKey()` already populates
    `$this->resolvedConsumer` (`:840`). This change consumes existing behaviour
    rather than extending it.
  - `OCA\Integriq\Directory\ScimProvisioningService` — gains the two refusals and
    the collaborators below.
  - `OCA\Integriq\Directory\DirectorySource` — `findConnections()` supplies the
    configured directory connections.
  - `OCA\Integriq\Directory\GroupMappingResolver` — `managedGroups(array $configuration)`
    supplies the declared group set per connection.
- **Mappers/Entities:** none added. The consumer travels as the `ObjectEntity` that
  `getResolvedConsumer()` already returns.
- **Events/Hooks:** none.
- **OCP:** `IGroupManager`, `IUserManager` — already injected. `IGroupManager::isAdmin()`
  and `isDelegatedAdmin()` are *not* used; see Security Considerations.

## Security Considerations

**The threat this closes.** An attacker holding any valid consumer API key — one
issued for an unrelated integration, or leaked from one — can currently become a
Nextcloud administrator with a single request, or remove every administrator by
sending a reconciling write with an empty member list. `setGroupMembers()` removes
any current member absent from the incoming list, so the escalation and the lockout
are the same code path.

**What remains open after this change, stated plainly.** Any valid consumer key
still reaches all nine SCIM routes and can still provision users into any *managed*
group. This change removes the path to administrator; it does not give consumers
separate identities with separate permissions. That requires a permission property
on the `consumer` schema and is deliberately a separate change. A reviewer should
not read this design as delivering consumer isolation.

**Why an allow-list rather than a deny-list.** A deny-list of dangerous group names
is only as complete as its author's imagination, and it fails open on every group
nobody thought of. The managed set is already the definition of "groups this
integration is responsible for", so confining writes to it fails closed by
construction. The `admin` refusal sits *on top* of the allow-list rather than
inside it, so that an operator who mistakenly declares `admin` managed still cannot
write it.

**Why not `isDelegatedAdmin()`.** It answers a question about a *user*, and the
refusal needs to act on a *group* before any user is touched. Enumerating
delegated-admin groups requires `OCA\Settings\Service\AuthorizedGroupService`, which
is another app's private API; depending on it would couple integriq to `settings`
internals for a secondary escalation vector that the allow-list already constrains.
Deferred as an open question rather than solved badly.

**Fail-closed on an empty configuration.** If no directory connection is configured,
the managed union is empty and every membership write is refused. This is the
correct default — an instance with no directory connection has no reason to accept
directory-driven group writes — but it is a behaviour change, so it is specified
explicitly (REQ-DS-009) rather than left as an implementation artefact.

**Logging.** Refusals name the consumer and the group. Responses to the caller stay
undifferentiated where the distinction would be useful to an attacker. This mirrors
the existing `authorize()` treatment, which already declines to say which check
failed.

**Input validation.** `$groupId` arrives from the URL path. The refusals compare it
before it reaches `IGroupManager::get()`, so a crafted id cannot reach group
resolution through a refused path.

## Declarative-vs-imperative decision (ADR-031)

Not applicable. ADR-031 governs lifecycle and state machines, aggregations and
counts, derived or virtual fields, notifications, declarative relations between
OpenRegister objects, and dashboard widgets. This change introduces none of those —
it is an authorisation refusal on a request path, which has no declarative
expression in the schema register. No `x-openregister-*` annotation is added, and
no behaviour that could have been declarative is being written imperatively.

## File Structure

```
lib/
  Controller/
    ScimController.php              (modified — authorize() return type, 9 call sites)
  Directory/
    ScimProvisioningService.php     (modified — refusals in setGroupMembers(), upsertUser())
openspec/changes/directory-and-group-sync/specs/directory-sync/
    spec.md                         (modified — REQ-DS-003 amended)
tests/Unit/
  Controller/
    ScimControllerTest.php          (modified — new authorize() shape)
  Directory/
    ScimProvisioningServiceTest.php (modified — refusal cases)
```

No new file is created. That is intentional: this is a change of rules inside two
existing units, and introducing a `ScimAuthorizationPolicy` class to hold two
comparisons would add indirection without adding a seam anything else needs.

## Seed Data

Not applicable. This change introduces and modifies no OpenRegister schema, so
there is no object for `_registers.json` to seed. The `consumer` and `source`
objects the refusals read are seeded by the changes that introduced them
(`consumer-management` and `directory-and-group-sync` respectively), and this change
neither extends their shape nor requires additional instances.

The test fixtures stand in for seed data here and are specified in the test plan: a
directory connection declaring a small managed set, a consumer with an API key, and
an `admin` group with at least one existing member — that last one because the
lockout scenario cannot be observed against an empty group.

## Open Questions

1. ~~Should `upsertUser()` carry the same refusals?~~ **Resolved during
   implementation: no.** `upsertUser()` writes `displayName`, the email address and
   the active flag, and never touches group membership. Enumerating every
   `addUser`/`removeUser` call in the app confirmed `setGroupMembers()` is the only
   membership writer a SCIM caller can reach — see the proposal's Risk 2 table. The
   diff therefore stays narrower than first assumed.
2. Should the `admin` refusal return `403` or a deliberately vague `404`? `403` is
   specified above as the more honest answer and the more debuggable one, but `404`
   leaks less. Deferred to review.
3. The refusal is signalled with the existing `DirectorySyncRefusalException` rather
   than a new exception type. Its docblock already states the intent — "stops on
   purpose rather than on an error" and "the message MUST name what was refused" —
   and it carries a `$context` array for machine-readable detail. Reusing it keeps
   one refusal vocabulary in the Directory subsystem; the alternative is a
   SCIM-specific type if reviewers prefer the two refusal kinds distinguishable by
   class rather than by context.
