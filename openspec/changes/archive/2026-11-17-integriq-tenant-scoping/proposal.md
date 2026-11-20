# Proposal: integriq-tenant-scoping

## Summary

Integriq stores its sources, endpoints, mappings, jobs, synchronisations and logs as OpenRegister objects, and OpenRegister keeps each organisation's objects apart by default. Integriq switches that off (`_multitenancy: false`) in about two hundred places, including the controllers behind the admin pages. A user of one organisation can therefore list, read, run or change another organisation's sources, synchronisations and logs on a shared installation. This change puts the organisation filter back on every path a user reaches, and keeps it off only where integriq runs as the system and says why.

## Why

Row `integriq:acc-tenants`, decision 408 (13 Nov 2026): the row was marked built on the strength of OpenRegister's default scoping, but the verify lane found integriq bypasses it in about 209 places (for example `SourcesController` and `EndpointTargetResolver`). This is a security fix: the row is not built until integriq honours the scoping.

## What integriq already has

- OpenRegister's `ObjectService::find()`, `findAll()`, `searchObjects()`, `saveObject()` and `deleteObject()` filter by the caller's active organisation unless the caller passes `_multitenancy: false`. An admin sees across organisations only when OpenRegister's `adminOverride` setting is on.
- Integriq's own authorisation (`ActionAuthService`, `#[AuthorizedAdminSetting]`) decides which actions a user may take. It does not decide which organisation's objects they may take them on.

## What this change builds

1. Every user-facing read or write of an integriq object leaves OpenRegister's organisation filter on, so an object of another organisation answers 404 and never appears in a list.
2. Paths that run without a user (cron jobs, background synchronisations, the public gateway resolving its own endpoint after the request is authorised) keep `_multitenancy: false`, each with a one-line reason in the code.
3. A unit test per controller or service proves a cross-tenant request is refused and fails without the fix.

## Out of scope

- OpenRegister's RBAC: integriq's object schemas declare no authorisation rules, and integriq's own action checks stay in front of each operation.
- Changing which organisation a new object is stamped with; OpenRegister already stamps the caller's active organisation.
