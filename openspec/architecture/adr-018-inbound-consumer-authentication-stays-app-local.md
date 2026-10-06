# ADR-018: Inbound consumer authentication stays app-local

- **Status:** Proposed (2026-10-06)
- **Exception to:** ADR-022 (consume OpenRegister abstractions), rule `consume-or-rbac-fleet-wide` only

## Inbound Consumer Authentication (ADR-022 exception)

- `lib/Service/AuthorizationService.php` (gate 23 rules: 6) authenticates the CALLER of an Integriq endpoint: consumer JWT, Basic, OAuth bearer, Nextcloud session, API key. It is not a permission layer over register objects.
- Users, groups and keys it checks come from the Endpoint's `authentication` configuration (and the SCIM / Notificaties subscriber controllers). OpenRegister RBAC has no equivalent for an external consumer, so there is nothing to migrate to; no sunset date.
- NEVER add a permission check on OpenRegister objects to this service. Object access after authentication goes through OpenRegister RBAC (ADR-022); this exception does not cover it.
- Supersede this ADR if OpenRegister gains inbound consumer authentication for published endpoints.
