---
kind: code
depends_on: []
---

# Proposal: connections-declare-data-residency

## Summary

Every outbound destination integriq sends data to says where it sits: a
country, who said so, and on what evidence. One admin page lists every
destination with its location, including hosts the call log shows that no
source declares. The page gives a verdict, "EU only" or "not proven", and
names what keeps it from being proven. An administrator who wants it enforced
switches on an EU-only mode that refuses calls to a destination outside the
EU or of unknown location.

## Why

Woo capability row 17.11, "The whole thing runs on infrastructure inside the
EU, provably". Our column reads `partial`: "the apps run wherever the instance
runs, so EU hosting is the operator's choice and provable by them. Nothing in
the three apps asserts or verifies locality, and openregister's own broker
host locking is the nearest mechanism". The gap register names the missing
half: "Each declared connection records where its endpoint sits (country or
region), and an admin page lists every outbound destination with its
location, so EU only operation can be shown." Build plan: new spec, wave 2,
size M.

The instance itself is where the operator put it, and this change does not
pretend to measure that. It makes the part integriq controls provable: every
place data leaves to.

## What integriq already has

- The connection registry, `openspec/specs/connection-registry` (REQ-CONN-001
  to REQ-CONN-007, built): apps declare their outside connections in
  `lib/Settings/connections.json`, validated against
  `lib/Settings/connections.schema.json` by
  `lib/Service/ConnectionDeclarationValidator.php`, and synced into
  `app_connection` rows (`lib/Settings/register.d/app-connection-schema.json`)
  that link to an integriq source. The overview page is `/connections`
  (`src/manifest.json`).
- Sources carry their endpoint in `location` (a URL). Two seeded connectors
  carry a country in their own data (`australia-austender-connector.json`,
  `germany-bund-connector.json`), as content, not as a residency fact.
- Every outbound call is recorded with its URL (`outbound-call-log`
  REQ-OCD-001) and passes the egress guard
  (`lib/Service/Security/EgressGuard.php::assertAllowed(string $url)`).

## What changes

1. A source gains `residency`: `country` (ISO 3166-1 alpha-2), `basis`
   (`set-by-admin`, `declared-by-app` or `seeded`), `evidence` (a URL or a
   short text, such as the processor agreement), `recordedBy` and
   `recordedAt`. An administrator sets it on the source page.
2. A connection declaration may carry `residency` too (`country`,
   `evidence`), for a fixed national endpoint the declaring app knows, such
   as the BRP. The sync copies it onto the linked source with basis
   `declared-by-app` unless an administrator has set one.
3. A destinations page lists every enabled source and every host seen in the
   call log over the last 30 days, with host, country, basis, evidence and
   whether the country is in the EU. A host in the call log that matches no
   source is listed as "undeclared destination".
4. A verdict at the top: "EU only" only when every listed destination has a
   country inside the EU. Otherwise "not proven", with the count of unknown
   and non-EU destinations. Unknown is never counted as EU.
5. An opt-in EU-only mode (setting `egress_eu_only`, off by default). When
   on, `CallService` refuses a call to a source whose residency is unknown or
   outside the EU, before any byte leaves, and records the refusal.
6. An export of the page as JSON for an auditor, from the same endpoint the
   page reads.

## What does not change

- The connection registry's status resolver, health job and events
  (REQ-CONN-003 to REQ-CONN-005).
- Calls other apps make without integriq. The page says so in one line,
  because it is the boundary of what integriq can prove.
- No IP geolocation database is bundled. A country is a recorded fact with a
  basis and evidence, not a guess.

## Fail closed

- A destination with no country reads as unknown, and unknown blocks the "EU
  only" verdict.
- With EU-only mode on, an unknown or non-EU destination is refused, never
  allowed with a warning. A settings read that fails refuses the call.
- An EEA country outside the EU (Norway, Iceland, Liechtenstein) is shown as
  EEA and does not count as EU.

## Cross-app contract and app absent

The `residency` key in `connections.json` is new. Today's
`ConnectionDeclarationValidator` refuses an unknown key ("is not allowed"),
so an app that adds `residency` before integriq ships this change would have
its whole declaration file refused. The consuming apps (dossiq first, as the
registry's first adopter) SHALL add `residency` only in a release that
requires this integriq version, and integriq's validator keeps accepting a
file without it. Without integriq installed no app has connection rows, and
this page does not exist.

## Dependencies

- `hydra/connection-registry` (design, 0/0 in the plan). Its integriq half is
  built and archived as `openspec/specs/connection-registry` on
  `development`, so this change builds on that spec and does not wait.
- Wave 2. No Ruben decision governs this row directly.
