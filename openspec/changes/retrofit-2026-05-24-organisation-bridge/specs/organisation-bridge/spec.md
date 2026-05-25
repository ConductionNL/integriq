---
retrofit: true
status: draft
---

# Organisation bridge

## Purpose

Adapter that lets openconnector consume OpenRegister's organisation features
without taking a hard dependency on openregister. Reflects on `IAppManager` +
the server DI container at every call to decide whether to forward to OR's
`OrganisationService` or return an unavailable-shape default.

This spec retroactively documents the observed contract of every public method.

## ADDED Requirements

### REQ-001: Soft-fail lazy accessor for OR OrganisationService

`getOrganisationService(): ?\OCA\OpenRegister\Service\OrganisationService` MUST
first check that `'openregister'` is in `IAppManager::getInstalledApps()`. If not,
the method MUST return `null` without touching the DI container.

If openregister is installed, the method MUST attempt to resolve
`OCA\OpenRegister\Service\OrganisationService` from the container. On
`ContainerExceptionInterface` or `NotFoundExceptionInterface`, the method MUST
log a `LoggerInterface::warning('OpenRegister OrganisationService not
available', ['exception' => ...])` and return `null`.

On successful resolution the method MUST return the service instance.

#### Scenario: openregister not installed returns null silently

- **GIVEN** `IAppManager::getInstalledApps()` does NOT include `'openregister'`
- **WHEN** `getOrganisationService()` is called
- **THEN** the method returns `null`
- **AND** the DI container is NOT queried
- **AND** no log entry is emitted

#### Scenario: container resolution failure returns null with warning

- **GIVEN** openregister is installed
- **AND** `$container->get('OCA\OpenRegister\Service\OrganisationService')` throws `NotFoundExceptionInterface`
- **WHEN** `getOrganisationService()` is called
- **THEN** the method returns `null`
- **AND** a `LoggerInterface::warning` is emitted with `exception => <message>`

#### Notes

- **HIGH (OWASP A01:2021 / CWE-863):** The `catch → return null` shape is the
  unsafe-auth-resolver pattern (`hydra-gate-unsafe-auth-resolver`). Every
  consumer guards with `if ($org !== null) { ... }`. The "OR is briefly
  unavailable" path looks identical to "this user has no org affiliation",
  which can degrade an authorization decision into "checks skipped." The
  bridge is not itself an auth gate, but the org context it surfaces feeds
  authz decisions downstream; this REQ documents the observed shape so any
  hardening lands as a separate change rather than an implicit fix.

---

### REQ-002: OR-availability predicate and unavailable-shape returns

`isOrganisationServiceAvailable(): bool` MUST return `true` iff
`getOrganisationService()` returns a non-null value. Consumers switch on this
predicate to decide between forwarding to OR and returning their own
unavailable-shape default.

The predicate MUST NOT cache the result — every call re-evaluates
`getOrganisationService()`.

#### Scenario: OR present returns true

- **GIVEN** openregister is installed and `OrganisationService` resolves cleanly
- **WHEN** `isOrganisationServiceAvailable()` is called
- **THEN** the method returns `true`

#### Scenario: OR missing returns false

- **GIVEN** openregister is not installed
- **WHEN** `isOrganisationServiceAvailable()` is called
- **THEN** the method returns `false`

#### Notes

- No memoisation — the bridge re-checks on every call. Cheap because the
  `IAppManager::getInstalledApps()` short-circuit fires first when OR is
  absent. When OR is present, a container `get` is invoked per call, which is
  amortised by the container's own caching.

---

### REQ-003: User organisation statistics with availability fallback

`getUserOrganisationStats(): array` MUST forward to
`OrganisationService::getUserOrganisationStats()` when the OR service is
available and return that result merged with `['available' => true]`.

When OR is not available, the method MUST return the static fallback:

```php
['total' => 0, 'active' => null, 'results' => [], 'available' => false]
```

When OR is available but its `getUserOrganisationStats` throws `\Exception`, the
method MUST log `LoggerInterface::error('Failed to get user organization stats',
['exception' => ...])` and return the same fallback shape PLUS
`'error' => 'Failed to retrieve organization data'`.

#### Scenario: happy path forwards OR stats with available flag

- **GIVEN** OR returns `{ total: 3, active: 'org-1', results: [<orgs>] }`
- **WHEN** `getUserOrganisationStats()` is called
- **THEN** the method returns `{ total: 3, active: 'org-1', results: [<orgs>], available: true }`

#### Scenario: OR unavailable returns empty-shape

- **WHEN** OR is not installed
- **AND** `getUserOrganisationStats()` is called
- **THEN** the method returns `{ total: 0, active: null, results: [], available: false }`
- **AND** the `error` key is NOT present

#### Scenario: OR-side exception returns empty-shape with error key

- **GIVEN** OR is installed but `getUserOrganisationStats()` throws
- **WHEN** the method is called
- **THEN** the method returns `{ total: 0, active: null, results: [], available: false, error: 'Failed to retrieve organization data' }`
- **AND** a `LoggerInterface::error` is emitted

#### Notes

- Consumers that only switch on `available` will treat the OR-side error path
  identically to "OR not installed". The `error` key is the only
  distinguishing signal — see design.md for the data-quality regression.

---

### REQ-004: Set active organisation for the current user

`setActiveOrganisation(string $organisationUuid): array` MUST forward to
`OrganisationService::setActiveOrganisation(organisationUuid: $organisationUuid)`
when OR is available, and return:

```php
[
  'success'   => <bool from OR>,
  'message'   => 'Active organization updated successfully' | 'Failed to update active organization',
  'available' => true,
]
```

When OR is not available, the method MUST return:

```php
['success' => false, 'message' => 'Organization service not available', 'available' => false]
```

When OR is available but `setActiveOrganisation` throws `\Exception`, the method
MUST log `LoggerInterface::error('Failed to set active organization',
['organisationUuid' => ..., 'exception' => ...])` and return:

```php
['success' => false, 'message' => <exception message>, 'available' => true]
```

#### Scenario: success forwards true result

- **GIVEN** OR returns `true` for the UUID
- **WHEN** `setActiveOrganisation('org-1')` is called
- **THEN** the method returns `{ success: true, message: 'Active organization updated successfully', available: true }`

#### Scenario: OR rejects with false

- **GIVEN** OR returns `false` for the UUID
- **WHEN** `setActiveOrganisation('org-1')` is called
- **THEN** the method returns `{ success: false, message: 'Failed to update active organization', available: true }`

#### Scenario: OR throws — exception message echoed back

- **GIVEN** OR throws `\Exception('user not member of org')`
- **WHEN** `setActiveOrganisation('org-1')` is called
- **THEN** the method returns `{ success: false, message: 'user not member of org', available: true }`
- **AND** the error is logged with the UUID

#### Notes

- **Info-disclosure low:** the exception message is forwarded verbatim to the
  API consumer. Internal DB error fragments could leak. Hardening would
  replace the message with a static "Failed to update active organization"
  string on the exception path too.
- **No defence-in-depth membership check:** the bridge does not verify the
  current user actually belongs to `$organisationUuid` — it trusts OR's
  implementation.

---

### REQ-005: Read active and list user organisations as jsonSerialize arrays

`getActiveOrganisation(): ?array` MUST forward to
`OrganisationService::getActiveOrganisation()` and return the
`jsonSerialize()` result of the returned entity. The method MUST return `null`
on any of: OR not available, OR returns `null`, or OR throws `\Exception` (the
latter logged via `LoggerInterface::error('Failed to get active organization',
['exception' => ...])`).

`getUserOrganisations(): array` MUST forward to
`OrganisationService::getUserOrganisations()` and return
`array_map(fn($org) => $org->jsonSerialize(), $organisations)`. The method MUST
return `[]` on any of: OR not available, OR throws `\Exception` (the latter
logged via `LoggerInterface::error('Failed to get user organizations', ...)`).

#### Scenario: getActiveOrganisation forwards entity as JSON

- **GIVEN** OR returns an organisation entity with `jsonSerialize() === { uuid: 'org-1', name: 'Acme' }`
- **WHEN** `getActiveOrganisation()` is called
- **THEN** the method returns `{ uuid: 'org-1', name: 'Acme' }`

#### Scenario: getActiveOrganisation returns null for OR no-active

- **GIVEN** OR returns `null` (user has no active org)
- **WHEN** `getActiveOrganisation()` is called
- **THEN** the method returns `null`

#### Scenario: getUserOrganisations maps entities through jsonSerialize

- **GIVEN** OR returns `[org-1-entity, org-2-entity]`
- **WHEN** `getUserOrganisations()` is called
- **THEN** the method returns `[{org-1 json}, {org-2 json}]`

#### Scenario: OR-side exception returns null/empty without distinguishing

- **GIVEN** OR is available but its method throws
- **WHEN** the bridge method is called
- **THEN** `getActiveOrganisation()` returns `null` / `getUserOrganisations()` returns `[]`
- **AND** the exception is logged via `LoggerInterface::error`
- **AND** the caller cannot distinguish "no orgs" / "no active org" from "OR errored"

#### Notes

- The "null on anything" / "empty array on anything" shape collapses three
  distinct cases (unavailable, no data, errored) into one return value. The
  UX consequence is documented in design.md; this REQ pins the observed shape.
