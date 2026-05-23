# Migration: openconnector-app-manifest

## Not Applicable

This change is `kind: config` and introduces no database schema changes, no new tables,
no new columns, and no data transformations.

The deliverables are:
- `src/manifest.json` — a static JSON file bundled by webpack
- Two-line glue in `src/main.js`
- A script addition in `package.json`

No Nextcloud migration class is needed. No PHP is modified.

## Current State

The OpenConnector database schema is unchanged. Tables defined by `openconnector-register-storage`
(chain B) remain as-is. No frontend state is persisted to the database by this change.

## Target State

Identical to current state. No database changes.

## Rollback Procedure

If `src/manifest.json` causes a build error (invalid JSON) or a CI failure
(`check:manifest`), the rollback is:

1. Delete `src/manifest.json`.
2. Revert the two lines added to `src/main.js`.
3. Remove the `check:manifest` script from `package.json`.

No database rollback is required.
