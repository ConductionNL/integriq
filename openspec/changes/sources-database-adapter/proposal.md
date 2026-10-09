---
kind: code
depends_on: []
---

# Proposal: sources-database-adapter

## Summary

A municipality's back-office data often lives in a database that has no API, and integriq cannot read it. The source schema already names a `database` type and the synchronization engine has a `database` branch, but both are empty. This change ships a database adapter: an administrator connects PostgreSQL, MySQL, MariaDB or Microsoft SQL Server, writes the read and write statements once, and synchronizations and flows use them like any other source.

## Why

Row `integriq:src-database` (rated no, built none), sources area (the core area), decided `build` in the OpenSpec pass of 2026-09-27: three competitors rate yes.

- MuleSoft https://docs.mulesoft.com/db-connector/latest/index.md "Anypoint Connector for Database (Database Connector) establishes communication between your Mule app and a relational database" over JDBC.
- n8n 2.40.7 `packages/nodes-base/nodes/Postgres/v2/actions/database/Database.resource.ts:28` executeQuery and `:34` insert, with MySQL, Microsoft SQL, Oracle and others in the same tree.
- Frank!Framework v10.2.0 `core/src/main/java/org/frankframework/jdbc/FixedQuerySender.java:72` "runs SELECT/UPDATE/INSERT or stored procedures against any JDBC datasource".

The matrix evidence: `PDO` and `DBAL` appear in `lib/` only in internal migration code (`lib/Service/Migration/LegacyToRegisterMigrator.php`, `lib/Repair/RenameDutchColumns.php`).

## What integriq already has

- The source schema's `type` description (`lib/Settings/integriq_register.json`, source) lists `database` (DB query) as a recognised type, but `CallService::call()` only branches on `soap` and sends everything else over HTTP (`lib/Service/CallService.php:1213-1220`). The description promises what the code does not do; this change makes it true.
- `SynchronizationService` has two empty `database` cases: `case 'database': // @todo: implement` in the read switch of `getAllObjectsFromSource()` (`lib/Service/SynchronizationService.php:5871`) and in the target write switch (`:5705`).
- The data infrastructure connector category: `openspec/specs/data-infra-connectors` REQ-DIC-001 to REQ-DIC-006 (register through the integration registry, a manifest entry, credentials on the integriq source, a polling posture and schema discovery, scheduled pulls as OpenRegister ScheduledWorkflow records, health on the metrics endpoint). `S3Adapter` is its reference adapter (`lib/Service/Adapter/DataInfra/S3Adapter.php`).

REQ-DIC-007 asks that each adapter ship in its own change. This change carries the database adapter only; SFTP is `sources-sftp-adapter`. REQ-DIC-007's name pattern `add-openconnector-{slug}-adapter` predates the rename to integriq; this pass names changes `<area>-<slug>`.

## What this change builds

1. A database adapter in the data infrastructure category, with capabilities `read`, `write`, `schema-discover` and `query`, meeting REQ-DIC-001 to REQ-DIC-006.
2. Named statements on the source: read statements with bound parameters and keyset paging, and write statements, authored by an administrator. A caller never sends SQL.
3. The `database` branch of the synchronization engine, so a synchronization reads from a table or view and maps as usual.
4. Schema discovery: tables, views and columns listed in the source page to help write statements.

## Out of scope

- Oracle. It needs the `oci8` extension, which a standard Nextcloud image lacks; the adapter reports it as unavailable when the extension is missing.
- Change data capture. The adapter polls.
- NoSQL stores such as MongoDB or Redis.
