# data-infra-connectors Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- sources-database-adapter

## Purpose

A relational database without an API becomes an integriq source, read and written through statements an administrator declares. Row `integriq:src-database`. The adapter follows the category contract REQ-DIC-001 to REQ-DIC-006.

## ADDED Requirements

### Requirement: A relational database is a source with declared statements (REQ-DBSF-001)

Integriq MUST ship a database adapter in the data infrastructure category that connects to PostgreSQL, MySQL, MariaDB and Microsoft SQL Server through Doctrine DBAL, with the password taken from the credential broker. It MUST run only statements declared on the source, MUST bind parameters by name, MUST apply a timeout and a row cap, and MUST list tables, views and columns for schema discovery. A driver whose PHP extension is missing MUST be reported as unavailable.

#### Scenario: an administrator connects a back-office database
- GIVEN an administrator creating a source of type database for a PostgreSQL server with a read-only account
- WHEN they declare the read statement `zaken-sinds` and press test
- THEN the first page of rows and the duration are shown
- e2e: `tests/e2e/database-source.spec.ts`

#### Scenario: free SQL from a caller is refused
- GIVEN a database source with two declared statements
- WHEN a flow asks the adapter to run a statement name that is not declared
- THEN the call is refused before a connection opens
- @e2e exclude covered by PHPUnit

### Requirement: A synchronization reads from a database page by page (REQ-DBSF-002)

Integriq MUST let a synchronization with source type `database` name a read statement and MUST read it page by page on the statement's key until a page is shorter than the cap, handing each row to the existing mapping.

#### Scenario: a table of 1,200 rows arrives in three pages
- GIVEN a synchronization on `zaken-sinds` with a page size of 500
- WHEN it runs
- THEN it reads three pages, and 1,200 objects are mapped into the target schema
- @e2e exclude synchronization run; covered by PHPUnit and a run against the PostgreSQL container
