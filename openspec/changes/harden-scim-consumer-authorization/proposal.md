---
kind: code
---

# Proposal: harden-scim-consumer-authorization

## Summary

The SCIM 2.0 endpoint authenticates a caller but never authorises one. Any valid
consumer API key — issued for any integration — reaches all nine SCIM routes, and
`ScimProvisioningService::setGroupMembers()` applies no restriction on which
Nextcloud group it may write. A single `PATCH /apps/integriq/api/scim/v2/Groups/admin`
therefore grants the caller Nextcloud administrator, or, with an empty member list,
removes every existing administrator. This change makes the resolved consumer
identity available to the endpoint, refuses writes to the `admin` group
unconditionally, and confines group writes to the groups a directory connection
declares it manages.

## Motivation

Found during the security review of PR #1983 (review `5260087915`, blocker 1).
Verified end to end: `ScimController::authorize()` calls
`AuthorizationService::authorizeApiKey(header: $presented, keys: [])`. The empty
`keys` array means the rule-inline comparison loop never executes, so control
falls through to `resolveConsumerByApiKey()`, which compares the presented key
against every registered consumer and returns on the first match. No scope, role
or group constraint is applied to the consumer that comes back.

`authorize()` then returns `?JSONResponse`. The resolved identity is discarded, so
no route can know which consumer called even if it wanted to.

`setGroupMembers()` looks up the group by id and reconciles membership. It treats
`admin` as an ordinary group name, and because it also removes any current member
absent from the incoming list, the same endpoint supports both escalation and
lockout:

| Call | Effect |
|---|---|
| `PATCH /Groups/admin` with `members: [attacker]` | attacker becomes a Nextcloud administrator |
| `PATCH /Groups/admin` with `members: []` | every administrator is removed |

**This is not a defect against its specification.** `REQ-DS-003` in
`directory-and-group-sync` requires the endpoint be *"gated by its own credential
and rejecting an unauthenticated call before any read"*, and its only
authorisation scenario is *"an unauthenticated SCIM call reads nothing"*. The
implementation satisfies that requirement exactly. The requirement asked for
authentication and never asked for authorisation, so the gap is in the spec first
and the code second — which is why this change amends `REQ-DS-003` rather than
only patching the controller.

Why now: the code is merged to `development` and PR #1983 (`development` → `beta`)
is blocked on it. The exposure is live on every instance running `development`.

## Affected Projects

- [x] Project: `integriq` — `ScimController::authorize()` returns the resolved
  consumer; `ScimProvisioningService::setGroupMembers()` gains an unconditional
  refusal for privileged groups and a managed-group allow-list; `REQ-DS-003`
  amended to require authorisation, not only authentication.

## Capabilities

### Modified Capabilities

- `directory-sync` — `REQ-DS-003` currently requires only that the SCIM endpoint be
  gated by its own credential and reject an unauthenticated call. It is amended to
  require that an authenticated call is additionally authorised for the group it
  writes, and new requirements are added for the two refusals. The capability is not
  yet archived to `openspec/specs/`; its live spec is
  `openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md`.

### New Capabilities

None.

## Scope

### In Scope

- `ScimController::authorize()` returns the resolved consumer instead of
  discarding it, so the caller's identity is available to every route and to the
  log line. Rejections and accepted calls both name the consumer.
- `ScimProvisioningService::setGroupMembers()` refuses, before any membership is
  read or written:
  1. the literal `admin` group — unconditionally, not configurable, whatever the
     caller presents;
  2. any group that no directory connection declares as managed, via the union of
     `GroupMappingResolver::managedGroups()` across
     `DirectorySource::findConnections()`.
- A refusal is logged naming the consumer and the refused group, and returns a
  SCIM-shaped error rather than a generic failure.
- Amend `REQ-DS-003` so the requirement covers authorisation, and add scenarios
  for the refusal cases.
- Unit tests for each refusal, plus a regression test asserting that a group write
  outside the managed set is refused even when the credential is valid.

### Out of Scope

- **Per-consumer scoping.** Confining a given consumer to its own subset of SCIM
  operations requires a `scopes` (or equivalent) property on the `consumer`
  schema, which does not exist — the schema carries `uuid, name, description,
  domains, ips, authorizationType, authorizationConfiguration, created, updated,
  userId, rateLimit, quota` and nothing describing permission. That is a schema
  change with a migration and belongs in its own change. **After this change any
  valid consumer key still reaches SCIM; it simply cannot reach `admin` or any
  unmanaged group.**
- Linking a consumer to a specific directory connection. No such relation exists
  today, and inventing one is the same data-modelling work as the `scopes` field.
- Nextcloud admin-delegation groups beyond the literal `admin` group.
  `IGroupManager` exposes `isAdmin()` and `isDelegatedAdmin()` for a *user*;
  enumerating delegated-admin *groups* requires `OCA\Settings\Service\AuthorizedGroupService`,
  which is another app's private API. Deferred — see Open Questions.
- The other two findings from the same review (`smimePrivateKey` readability, the
  ten mail schemas with no `authorization` block). Tracked separately.

## Approach

Three moves, smallest first.

**Identity.** `authorize()` currently answers "is this caller valid?". It changes to
answer "which caller is this?" — returning the resolved consumer on success and a
`JSONResponse` on refusal. `AuthorizationService` already records the match in
`$this->resolvedConsumer` and exposes `getResolvedConsumer()` (`AuthorizationService:922`),
so no new resolution logic is introduced. `NotificatiesSubscriberController:334`
is the in-repo precedent for consuming it.

**Refusal.** The `admin` refusal lives in `ScimProvisioningService`, not in the
controller, so it holds for every present and future caller of that service rather
than for the routes that remember to ask. This is deliberate: the controller gate
is convenience, the service refusal is the guarantee.

**Allow-list.** Group writes are confined to groups a directory connection declares
it manages. This reuses `GroupMappingResolver::managedGroups()` and
`DirectorySource::findConnections()` rather than introducing a deny-list, because a
deny-list is only as good as its authors' imagination, while the managed set is
already the definition of "groups this integration is responsible for".

## New Dependencies

None. Every collaborator used (`AuthorizationService`, `GroupMappingResolver`,
`DirectorySource`, `IGroupManager`) is already constructed in this app.

## Impact

| Surface | Change |
|---|---|
| `lib/Controller/ScimController.php` | `authorize()` return type; call sites in the nine route methods |
| `lib/Directory/ScimProvisioningService.php` | `setGroupMembers()` gains refusals; new dependencies for the managed-group lookup |
| `openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md` | `REQ-DS-003` amended |
| `tests/Unit/Controller/ScimControllerTest.php` | updated for the new return shape |
| `tests/Unit/Directory/ScimProvisioningServiceTest.php` | new refusal cases |

**Behavioural change for existing integrations.** An identity system that today
writes to a group outside the managed set will begin receiving a refusal. That is
the intended effect, but it is a breaking change for any deployment relying on the
current permissiveness. See Risk 1.

No database migration. No schema change. No API shape change on the success path.

## Cross-Project Dependencies

None. Self-contained within integriq.

## Risks

### Risk 1: A live integration writes to an unmanaged group and starts failing

**Severity:** Medium — **Mitigation:** The managed set is derived from existing
connection configuration, so a correctly configured directory connection is
unaffected. The refusal is logged naming the consumer and the group, so a
deployment that breaks says exactly which group to add to the mapping. SCIM is six
days old (`0f50e2b33`, 2026-09-14) and has not shipped in a release, so the
population of live integrations is expected to be zero or near it — this is the
cheapest moment this change will ever be available.

### Risk 2: The `admin` refusal is bypassed through a path that does not call `setGroupMembers()`

**Severity:** Medium — **Mitigation:** Every group-membership write in the app was
enumerated rather than assumed:

| Writer | Reachable by a SCIM caller? | Covered |
|---|---|---|
| `ScimProvisioningService::setGroupMembers()` (`:271`, `:281`) | yes — the reported path | **yes** |
| `ScimProvisioningService::upsertUser()` | no — writes displayName, email and active only, never membership | n/a |
| `DirectorySyncService` (`:484`, `:489`) | no — scheduled, driven by connection configuration | out of scope |
| `GroupMappingResolver::createGroup()` (`:159`) | no — same scheduled path | out of scope |
| `LtiIdentityLinkService` (`:247`) | no — separate subsystem | out of scope |

The SCIM attack surface is exactly one method and the refusal covers it.
`DirectorySyncService` can still place a user in `admin` if an operator maps a
directory group onto it, but that is an operator's explicit decision on a scheduled
job rather than something a credential holder can trigger, and refusing it could
break a deployment that legitimately syncs its administrator group. Known
limitation, not a solved problem.

### Risk 3: Delegated-admin groups remain reachable

**Severity:** Low — **Mitigation:** Explicitly out of scope and stated as such. A
delegated-admin group is a privilege escalation of lesser degree than `admin`, and
it is additionally constrained by the managed-group allow-list — reaching one
requires an operator to have declared it managed by a directory connection, which
is an explicit local decision rather than a default. See Open Questions.

## Rollback Strategy

Revert the commit. The change is additive refusal logic with no migration, no
persisted state and no data transformation, so reverting restores the previous
behaviour exactly. Per repository policy the revert is `git revert`, never a
history rewrite.

If a deployment needs the old behaviour urgently without a revert, the
managed-group allow-list is the part that can break an integration; the `admin`
refusal is not, and must not be made configurable.

## Open Questions

1. **Should delegated-admin groups be refused alongside `admin`?** Doing it
   properly needs a group-oriented delegation API that OCP does not expose today.
   Options: accept the managed-group allow-list as sufficient containment (current
   proposal); depend on `OCA\Settings\Service\AuthorizedGroupService` and accept
   the cross-app coupling; or contribute an OCP accessor upstream. Deferred to
   review.
2. **Should a consumer be required to opt in to SCIM at all?** The honest answer is
   yes, and it is the `scopes` follow-up. Flagged here so the follow-up is not
   forgotten once the escalation is closed and the urgency drops.
