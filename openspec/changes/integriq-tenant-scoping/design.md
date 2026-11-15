# Design: integriq-tenant-scoping

## Approach

Each call site that passes `_multitenancy: false` falls into one of two groups.

- **(a) User-facing**: a controller action or a service called from one, acting on an id or list the user asked for. The flag goes back to OpenRegister's default (on). A missing or foreign object raises `DoesNotExistException`, which the controllers already turn into 404.
- **(b) System**: a background job, a repair step, a command, or internal resolution that runs after a request was already authorised and scoped (for example the gateway resolving the target of an endpoint it already matched). The flag stays off and the line above it says why in one comment.

`_rbac: false` stays where integriq's own action check runs first, because integriq's schemas carry no OpenRegister authorisation rules; the action check is the RBAC layer for these objects.

## Delivery

Small PRs by area, each landing on its own: sources, synchronisations (with contracts, dead letters and jobs), logs and events, endpoints. Each PR carries the tests for its area.

## Risks

- An admin who used to see every organisation's sources sees only their active organisation's, unless OpenRegister's `adminOverride` is on. That is the intended behaviour.
- Objects created before multitenancy was configured have no organisation. OpenRegister's filter decides whether they stay visible; integriq does not override it.
