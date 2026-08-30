# ADR-002: Mapping and Rule engine stays app-local

## Status
Accepted (capturing existing decision)

## Date
2026-05-20

## Context

Integriq's data-flow contract is "fetch from source -> transform -> persist
to target". The transformation half is implemented by two app-local services:

- `lib/Service/MappingService.php` — Twig-based field mapping with type casts,
  dot-notation paths, conditional mapping via JSON Logic, and runtime loaders
  for `AuthenticationExtension` + `MappingExtension`.
- `lib/Service/RuleService.php` — Rule processing for endpoint logic
  (authentication, sync triggers, download/upload, locking, audit trails).

`MappingService` already includes a `@deprecated` docblock noting that the
mapping engine is moving to OpenRegister and the service now delegates
`executeMapping()` to `OCA\OpenRegister\Service\MappingService` when available
(see `MappingService.php:21-50, 75-80`). The wrapper layer stays, however,
because:

1. The Twig environment is bootstrapped per-app with connector-specific
   extensions (`MappingExtension`, `AuthenticationRuntimeLoader`,
   `MappingRuntimeLoader`) that source from integriq's mappers + sources.
2. Rules are an integriq-specific concept (authentication, sync triggers,
   download/upload, locking, audit-trail enforcement at the endpoint layer);
   they have no OR equivalent.
3. The 2026-05-03 OR-abstraction audit (stream 1) flagged this and explicitly
   recommended KEEPING the mapping/rule engine as app-local "by-design
   transforms between schemas".

## Decision

Keep `MappingService` and `RuleService` as integriq-local services. The
mapping execution engine (Twig expression evaluation) is delegated to OR where
available, but the surrounding orchestration (engine bootstrap, extension
loading, rule processing, conditional logic, download/upload handling) stays in
integriq.

## Consequences

- `MappingService` keeps its `@deprecated` annotation as a signal that the
  EXECUTION engine has moved, even though the wrapper service itself stays.
  New code should call OR's mapping service directly when possible (the
  delegation path), and fall back to integriq's wrapper only when
  connector-specific Twig extensions or runtime loaders are needed.
- `RuleService` has no OR equivalent and remains the sole owner of endpoint
  rule processing. Future audits should NOT flag it as duplicated
  abstraction.
- Authentication rules, sync triggers, locking, and audit-trail rules stay
  bound to the endpoint lifecycle in integriq; they will not be
  generalised into OR.
- Cross-reference: hydra ADR-022 (apps consume OR abstractions) — this ADR
  specialises ADR-022 for the case where the OR abstraction (mapping
  execution) is consumed but the surrounding domain logic stays app-local.
- Cross-reference: hydra ADR-031 (schema declarative business logic) — does
  NOT apply to connector rules, which are call-shaped not schema-shaped.

## Evidence

- `lib/Service/MappingService.php:21-50` — `@deprecated` notice and class-level
  docblock describing the delegation.
- `lib/Service/MappingService.php:75-80` — `$container->get(\OCA\OpenRegister\
  Service\MappingService::class)` delegation lookup.
- `lib/Service/RuleService.php:18-34` — class docblock noting custom rules
  are experimental but app-local.
- `openspec/changes/openconnector-adopt-or-abstractions/proposal.md:38-39` —
  "Mapping/Rule rewrite engine: by-design transforms between schemas,
  app-local. KEEP."
- `openspec/changes/openconnector-adopt-or-abstractions/design.md:26-30` —
  Non-Goals enumerating the KEEP.
