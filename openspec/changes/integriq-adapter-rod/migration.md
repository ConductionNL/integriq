# Migration: integriq-adapter-rod

## Current State

`lib/Settings/integriq_register.json` declares the existing OR schemas
(`iwmo_ijw_message`, `digitalPostMessage`, etc.) but no `rod_message` schema.
No table or object type exists for ROD audit records.

## Target State

`lib/Settings/integriq_register.json` gains a `rod_message` schema entry
(declarative, per ADR-031 — data, not behaviour) with the fields listed in
design.md's Database Changes table. OpenRegister creates the backing table
from the schema register on next schema sync; no bespoke Doctrine migration
class is needed, consistent with `iwmo_ijw_message`'s own precedent (no
`VersionXXXXXXXXXX.php` was added for it either).

## Migration Class

None. OpenRegister schema registration is declarative JSON, not a Doctrine
migration. If a future need arises to backfill or reshape existing
`rod_message` rows, that migration is a schema-register version bump, not a
new PHP migration class — consistent with how every other OR-backed schema
in this app is versioned.

## Migration Steps

1. Append the `rod_message` schema object to `lib/Settings/integriq_register.json`.
2. On next app load / `occ upgrade`, OpenRegister's schema sync creates the
   backing storage for the new schema (no manual DDL).
3. Seed data (design.md's three `rod_message` seed rows) is added via the
   standard `_registers.json` seed mechanism, not a migration.

## Data Impact

Zero existing records affected — this is a purely additive schema. No table
is altered, no column is dropped, no existing OR object type changes shape.
Safe to run on a live instance.

## Rollback Procedure

Remove the `rod_message` entry from `integriq_register.json` and revert the
branch. Any `rod_message` rows created while the schema was live become
orphaned OR objects (same rollback shape as any other OR schema addition in
this app) — acceptable because this is new functionality with no external
reader yet at rollback time.

## Validation

- `occ openregister:schema:list` (or the app's own schema inspection command,
  if that alias differs) shows `rod_message` present after the app loads.
- The three seed rows from design.md are queryable via the OR API for the
  `integriq` register, `rod_message` schema.
- No pre-existing schema's field count or type changes (diffed against
  `git diff` on `integriq_register.json` — only an addition, no edits to
  other schema blocks).
