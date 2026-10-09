---
kind: code
depends_on: []
---

# Proposal: sources-sftp-adapter

## Summary

Connect a partner's SFTP or FTPS server as a source with a pinned host key, and pick its files up once, archiving them only after a safe local write.

- Rows: closes matrix row `integriq:src-sftp`, and is the base for Woo row 1.7 "Records arrive from a watched folder or a file drop" (not statutory). 1.7 closes with the second and third parts.
- Wave: 1.
- Depends on: nothing. `integriq/sources-sftp-adapter-watched-folder` (part 2) depends on this one.
- Decision: D4 (2026-10-05), integriq owns the folder watcher. This part builds the pickup that D4's watcher reuses.

Build rules: openspec/woo-build-rules.md

## Overview

Many partners still exchange files over SFTP: a nightly export dropped in a folder, a batch of documents picked up in the morning. Integriq has no way to fetch or deliver them. This change ships an SFTP and FTPS adapter: an administrator connects a partner's server with a pinned host key, and a synchronization or flow lists, fetches, delivers, moves and deletes files, with each fetched file landing in Nextcloud Files or handed on as an object.

## Why

Row `integriq:src-sftp` (rated no, built none), sources area (the core area), decided `build` in the OpenSpec pass of 2026-09-27: three competitors rate yes.

- MuleSoft https://docs.mulesoft.com/sftp-connector/latest/index.md "Anypoint Connector for SFTP (SFTP Connector) manages secure file transfers over the Secure File Transfer Protocol".
- n8n 2.40.7 `packages/nodes-base/nodes/Ftp/Ftp.node.ts:166` "'protocol' parameter chooses ftp or sftp with list, download, upload, rename, delete operations".
- Frank!Framework v10.2.0 `filesystem/src/main/java/org/frankframework/senders/SftpFileSystemSender.java:23` and `FtpFileSystemSender.java:23` "read, write, move and delete".

The matrix evidence: `grep -rliE "\bsftp\b|\bftp\b"` over `lib/` and `src/` returns nothing.

## What integriq already has

- The data infrastructure connector category, `openspec/specs/data-infra-connectors` REQ-DIC-001 to REQ-DIC-006, which names SFTP and FTPS among the file stores it covers and allows a schema posture of `none` "for an SFTP file-list adapter" (REQ-DIC-004). `S3Adapter` is the reference adapter.
- File handling in synchronizations: `parallel-file-fetch` and `stream-file-content` stream file content to disk instead of memory.
- `IRootFolder` is already used to store fetched files in Nextcloud Files (`lib/Service/IBabsConnectorService.php:71`).

REQ-DIC-007 asks for one adapter per change; the database adapter is `sources-database-adapter`.

## What this change builds

1. An SFTP adapter (password or key) and an FTPS adapter (explicit TLS), with capabilities `read`, `write` and `bulk-import`, meeting REQ-DIC-001 to REQ-DIC-006.
2. A mandatory host key pin for SFTP and a certificate check for FTPS. A changed key stops the connection and says so.
3. Operations: list a folder with a name pattern, fetch, deliver, move and delete.
4. A pickup synchronization: fetch every new file matching a pattern, store it in a Nextcloud folder or hand it to a mapping, then move it to an archive folder on the remote or delete it.

## Out of scope

- Plain FTP without TLS. It sends the password in the clear.
- Serving files to partners from integriq (integriq as an SFTP server).
