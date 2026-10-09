# data-infra-connectors Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- sources-sftp-adapter

## Purpose

A partner's SFTP or FTPS server is a source: files are listed, fetched, delivered, moved and deleted, and a pickup runs on a schedule. Row `integriq:src-sftp`. The adapter follows the category contract REQ-DIC-001 to REQ-DIC-006.

## ADDED Requirements

### Requirement: A partner's SFTP or FTPS server is a source (REQ-SFTP-001)

Integriq MUST ship SFTP and FTPS adapters in the data infrastructure category with list, fetch, deliver, move and delete. An SFTP source MUST pin the server's host key fingerprint and MUST refuse a connection when the key differs. Paths MUST stay inside the source's root path. Credentials MUST come from the credential broker.

#### Scenario: an administrator pins a partner's server
- GIVEN an administrator creating an SFTP source for `sftp.example.nl`
- WHEN they test it for the first time
- THEN the server's SHA-256 fingerprint is shown, and after they confirm it the source is saved with the pin
- e2e: `tests/e2e/sftp-source.spec.ts`

#### Scenario: a changed host key stops the connection
- GIVEN a source pinned to one fingerprint
- WHEN the server presents a different key
- THEN the connection is refused and the message gives both fingerprints
- @e2e exclude covered by PHPUnit against an SFTP container

### Requirement: New files are picked up and archived after a safe local write (REQ-SFTP-002)

Integriq MUST let a synchronization pick up new files matching a pattern from a folder, store each in a Nextcloud folder or pass its content to a mapping, and only then move it to an archive folder or delete it on the server. A file fetched once MUST NOT be fetched again.

#### Scenario: the morning batch arrives
- GIVEN three new files `besluiten-*.csv` in `/outgoing`
- WHEN the pickup runs
- THEN the three files are in the Nextcloud folder `Partner/besluiten`, they have moved to `/outgoing/processed` on the server, and the next run fetches nothing
- @e2e exclude scheduled pickup; covered by PHPUnit and a run against an SFTP container
