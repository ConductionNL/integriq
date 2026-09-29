# Migration: integriq-adapter-uwlr-eduv

## Current State

`lib/Settings/integriq_register.json` has no `uwlr_eduv_message` schema.

## Target State

A `uwlr_eduv_message` schema entry (declarative, ADR-031) with the fields
listed in design.md's Database Changes table.

## Migration Class

None — declarative JSON, same as `rod_message`/`verzuim_message`/`oso_message`.

## Migration Steps

1. Append the `uwlr_eduv_message` schema object to `integriq_register.json`.
2. On next app load / `occ upgrade`, OpenRegister's schema sync creates the
   backing storage.

## Data Impact

Zero existing records affected — purely additive. Safe on a live instance.

## Rollback Procedure

Remove the `uwlr_eduv_message` entry and revert the branch.

## Validation

- The schema is present after the app loads.
- No pre-existing schema's field count or type changes.
