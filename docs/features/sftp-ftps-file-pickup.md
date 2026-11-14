<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

# SFTP and FTPS file servers

Many partners still exchange files over SFTP or FTPS: a nightly export dropped in a folder, a batch of documents picked up in the morning. A source of type `sftp` or `ftps` connects such a server, and a synchronization with that source type picks its new files up into Nextcloud Files.

This page is for the administrator who connects a partner's server.

## Connect a server

Make a source of type `sftp` or `ftps`.

| Setting | Where | Notes |
|---|---|---|
| Host | `location` | `sftp.example.nl`, or `sftp://sftp.example.nl:2222` with a port. |
| Port | `configuration.port` | Default 22 for SFTP, 21 for FTPS. |
| User name | `configuration.username` | |
| Root path | `configuration.rootPath` | Every path stays inside it; a path with `..` is refused, and the root itself is never deleted. |
| Host key | `configuration.hostKeyFingerprint` | SFTP only, required. The server's SHA-256 key, as `SHA256:…`. |
| Sign-in | `configuration.authentication.mode` | `password` (default) or `ssh-key` (SFTP only). |
| Credential | `configuration.authentication.credentialRef.credentialId` | The password or private key, kept in OpenRegister's credential broker, never on the source. |

Plain FTP is not offered: it sends the password in the clear. An FTPS connection must switch to TLS and present a certificate that matches the host, or it is refused. The host must be reachable from the outside world; a source cannot point at an internal address.

### The host key

The first time you test a new SFTP source, it has no host key yet, so the test is refused and the message shows the fingerprint the server presented. Check that fingerprint with the partner, then fill it in. From then on a server that presents another key is refused before any password is sent, and the message names both fingerprints.

## Pick files up

Make a synchronization with source type "SFTP (file pickup)" or "FTPS (file pickup)" and pick the source. Its settings:

| Setting | Key | Default |
|---|---|---|
| Remote folder | `path` | the root path |
| File name pattern | `pattern` | every file (`*`) |
| Nextcloud folder | `filesFolder` | none, required |
| After a file is stored | `after` | `move`; also `delete` or `keep` |
| Archive folder | `archivePath` | none, required with `move` |
| Wait before picking up | `stableSeconds` | 60 |

Each run:

1. Lists the files in the remote folder that match the pattern.
2. Skips a file it already picked up, unless the partner sent it again with a new modification time or size.
3. Skips a file changed less than `stableSeconds` ago, so a file still being written is not fetched half.
4. Writes each file into the Nextcloud folder, in the Files of the synchronization's owner. A file with that name already there is kept; the new one gets a free name such as `report (2).csv`.
5. Only after that write has succeeded, moves the file to the archive folder or deletes it on the server. A failed write leaves the remote file where it was, and it is tried again on the next run.

Every picked-up file is remembered in a synchronization contract (`originId` the file name, `targetId` where it was stored). A test run lists the files the next run would pick up and changes nothing.

The run log reports the files picked up, failed and skipped, with the reason for each.

## Not yet

- Handing a file's content to a mapping instead of storing it (`target: mapping`) is refused for now; a pickup stores files.
- SFTP needs `phpseclib3` on the instance. Without it the SFTP adapter reports itself unavailable and says why.

## Related

- [Sources](sources.md)
- [Synchronizations](synchronizations.md)
