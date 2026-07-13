# Tasks — LTI Tool-role trust, identity-linking, and resource-mapping seam

> Builds strictly on top of the archived `lti-13-platform` change. No
> existing REQ-LTI-001..010 behaviour is modified. Implementation order:
> schema (nothing else compiles without the new fields/schema), then
> services, then the two new admin routes, then tests, then docs.

## 1. Register schema (lti-platform REQ-LTI-011/012/013)

- [ ] 1.1 Add `status` (`pending | approved | suspended`, default `pending`) to `lti_platform` in `lib/Settings/openconnector_register.json`
- [ ] 1.2 Add `status` (same enum/default) to `lti_tool`
- [ ] 1.3 Add `identityPolicy` (`manualLinkOnly | autoProvisionAsRole`, default `manualLinkOnly`) and `defaultProvisionGroup` (nullable string, required when `identityPolicy: autoProvisionAsRole` — OR conditional `if/then` on the schema, same pattern used elsewhere for conditional-required fields) to `lti_platform`
- [ ] 1.4 Add `resourceLinkMappings` (array of `{resourceLinkId, targetType, targetId}`, mirroring `gradeSink`/`rosterSource`'s object shape) to `lti_deployment`
- [ ] 1.5 Add new `lti_identity_link` schema: `uuid`, `ltiPlatformId` (FK, `x-openregister-onDelete: CASCADE`), `subject`, `userId`, `provisioningMethod` (`manual | auto`), `linkedByUserId` (nullable, set for `manual`), `linkedAt`, `created`, `updated`. Unique constraint on `(ltiPlatformId, subject)` — verify at HEAD which OR mechanism (schema-level `x-openregister-unique` composite index vs application-level check-before-write in `LtiIdentityLinkService`) is actually available today before choosing; do not assume the composite-index keyword exists without checking `ValidateObject.php` the way the archived change verified `oneOf` (tasks.md §1.5 precedent)
- [ ] 1.6 Register `lti_identity_link` under `components.registers[openconnector].schemas`; re-verify the current schema count at HEAD before stating a new total (the archived change found the proposal's stated count had already drifted once — re-check, don't assume 21)
- [ ] 1.7 `openspec sync` of the `openconnector-register-schema` delta — same deferred-to-archive convention the archived change used (§1.7 there)

## 2. Backend services

- [ ] 2.1 `LtiRegistrationResolverService::findPlatformByIssuer()`/`::findToolByClientId()`: filter to `status: approved` only; registrations found but not approved return `null` (same "not found" shape callers already handle) — log the actual `status` at debug level for operator visibility without changing the HTTP-facing behaviour
- [ ] 2.2 New `LtiKeyService`-adjacent (or `LtiRegistrationResolverService`-adjacent — decide at implementation time based on which service already owns registration mutation) `approve(registrationType, registrationUuid)` / `suspend(registrationType, registrationUuid)` methods
- [ ] 2.3 New `LtiIdentityLinkService`: `resolveIdentity(ltiPlatformId, subject): array{status: 'linked'|'unlinked', userId: ?string}` implementing D2's manualLinkOnly/autoProvisionAsRole branches; auto-provisioning creates the Nextcloud user via `IUserManager::createUser()` (or the project's existing user-provisioning helper if one already exists elsewhere in this codebase — check before adding a second one) and adds it to `defaultProvisionGroup` via `IGroupManager`
- [ ] 2.4 `LtiLaunchService::resolveResourceMapping(deploymentUuid, resourceLinkId): ?array` per REQ-LTI-013's exact-match → empty-default → null resolution order
- [ ] 2.5 Extract the `resource_link` claim constant (`CLAIM_RESOURCE_LINK = 'https://purl.imsglobal.org/spec/lti/claim/resource_link'`) alongside the existing `CLAIM_*` constants in `LtiLaunchService`

## 3. Endpoints

- [ ] 3.1 `LtiController::approve()`/`suspend()`: `#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]`, mirroring `generateKey()`/`rotateKey()`'s shape exactly (`lib/Controller/LtiController.php:424-462`)
- [ ] 3.2 `appinfo/routes.php`: two new routes, `POST /api/lti/{registrationType}/{registrationUuid}/approve` and `.../suspend`
- [ ] 3.3 No new route for `LtiIdentityLinkService`/`resolveResourceMapping()` — both are consumed cross-app via PHP service injection, same pattern `consumeLaunchReference()`/`buildDeepLinkingResponse()` already establish for the consuming app (no dedicated HTTP surface needed; verify at implementation time that this cross-app DI pattern is actually how another app in this codebase already consumes an OpenConnector service, and cite the precedent — if none exists, flag it as a new pattern rather than asserting it mirrors one)

## 4. Tests

- [ ] 4.1 PHPUnit: `LtiRegistrationResolverServiceTest` additions — `pending`/`suspended` registrations resolve as not-found; `approved` resolves normally
- [ ] 4.2 PHPUnit: registration-trust-gate integration in `LtiLaunchServiceTest` — a `pending` platform's login-initiation and launch requests both reject with the same shape as an unregistered issuer
- [ ] 4.3 PHPUnit: `LtiIdentityLinkServiceTest` — unlinked-under-manualLinkOnly reports unlinked with no user created; first-seen subject under autoProvisionAsRole provisions a user into `defaultProvisionGroup`; two platforms sharing a `sub` value resolve to independent links; identity resolution is never invoked before/inside `validateLaunch()`'s own rejection paths (assert call ordering / that a signature failure never reaches `LtiIdentityLinkService`)
- [ ] 4.4 PHPUnit: `resolveResourceMapping()` — exact `resourceLinkId` match; fallback to empty-`resourceLinkId` default; `null` when unconfigured
- [ ] 4.5 Regression: re-run the full baseline suite; confirm zero new failures and that every REQ-LTI-001..010 test from the archived change's `LtiLaunchServiceTest`/`LtiAgsServiceTest`/`LtiNrpsServiceTest`/`LtiJwksResolverServiceTest`/`LtiKeyServiceTest` still passes unmodified
- [ ] 4.6 `composer check:strict`'s `phpcs`/`phpmd`/`phpstan` against every new/touched file

## 5. Docs

- [ ] 5.1 Extend the LTI adapter docs page planned in the archived change (§6.1, itself not yet written — write both together if that page still doesn't exist at implementation time) with: the registration-trust-gate operational note (an admin must explicitly approve a new Platform/Tool before it can be used), the identity-linking trust model (D2, in plain operator language — default is manual linking, auto-provisioning is an explicit per-platform opt-in with a named group), and the resource-link mapping seam for consuming-app authors
- [ ] 5.2 CHANGELOG entry (union-merge convention — grep for existing markers before editing)
- [ ] 5.3 Cross-link from this change's docs to the scholiq `lti-tool-placement` leaf (separate repo) as the reference consumer of `LtiIdentityLinkService`/`resolveResourceMapping()`

## Acceptance criteria (plain bullets — verified by /opsx-verify)

- An `lti_platform`/`lti_tool` registration defaults to `status: pending` and cannot complete a login/launch/token-issuance flow until an admin transitions it to `approved`; a `pending`/`suspended` registration is rejected with the exact same HTTP status/body as an unregistered issuer
- Under the default `manualLinkOnly` policy, a launch from a `sub` with no existing `lti_identity_link` row is reported unlinked and creates no Nextcloud user; under an explicit `autoProvisionAsRole` policy, a first-seen `sub` provisions a user into the configured `defaultProvisionGroup` and records `provisioningMethod: auto`
- Identity-linking policy has no effect on whether `validateLaunch()` accepts or rejects a launch — a forged/expired/replayed launch is rejected before any identity-linking code runs, regardless of policy
- `resolveResourceMapping()` returns the exact `resourceLinkId` match when configured, falls back to a deployment-default entry, and returns `null` when nothing is configured — it never performs the register/schema read itself
- Every REQ-LTI-001 through REQ-LTI-010 requirement and its existing test coverage remains unmodified and green
