<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

# SFTP and FTPS sources

Many partners still exchange files over SFTP: a nightly export in a folder, a batch of documents to pick up in the morning. A source of type `sftp` or `ftps` connects such a server. A synchronization on it picks new files up, stores them in Nextcloud or maps them to objects, and only then archives them on the server.

This page is for the administrator who connects a partner's file server.

## Connect a server

1. Create a source with type `sftp` (or `ftps`). Set `location` to the host, for example `sftp.example.nl` or `sftp://sftp.example.nl:2222`. SFTP uses port 22 and FTPS port 21 when you name none.
2. Set `rootPath` to the folder you may use, for example `/outgoing`. Every path stays inside it, and a path with `..` is refused.
3. Store the password or the private key in the credential broker. Under `configuration.authentication` set `type` (`basic` for a password, `ssh-key` for a key; FTPS takes a password only), `username`, and `credentialRef` naming the credential. Integriq never reads a password from the source itself.
4. On the sources list, choose **Test connection**. For a new SFTP source the test shows the server's host key fingerprint and sends no credential yet. Check the fingerprint with the partner, then choose **Confirm and pin**.

From then on every connection compares the server's key with the pin before it logs in. A server that presents another key is refused, and the message names both fingerprints. An FTPS server is checked through its TLS certificate instead: the certificate chain and the host name must be valid.

The host goes through the same egress guard as outbound HTTP calls, so a source cannot point at an internal address.

## Pick files up

A synchronization with `sourceType` `sftp` or `ftps` is a pickup. Its `sourceConfig`:

| Key | Meaning |
|---|---|
| `path` | The folder to read, relative to `rootPath`. |
| `pattern` | Which files, for example `besluiten-*.csv`. |
| `target` | `files` stores each file in Nextcloud; `mapping` reads CSV, JSON or XML and saves each record to the synchronization's target register and schema, through its mapping. |
| `filesOwner`, `filesFolder` | For `files`: whose Nextcloud and which folder, for example `Partner/besluiten`. |
| `after` | `move` (to `archivePath`), `delete`, or `keep`. |
| `archivePath` | For `move`: the folder on the server that takes processed files. |
| `stableSeconds` | Files changed more recently than this (default 60) wait for the next run, so a half-written file is never fetched. |

Each file is fetched once: integriq remembers it by path and modification time. The remote move or delete runs only after the local write succeeded, so a file whose write failed stays on the server and is tried again. A mapped pickup never sets `published` or `publicatiedatum`: a file arriving is not a publication.

A test run lists the files it would fetch and changes nothing. Integriq ships a dormant example source `example-sftp-partner` and a disabled pickup `example-sftp-pickup`.

## Not covered

Plain FTP without TLS is not supported: it sends the password in the clear. Integriq does not serve files to partners as an SFTP server.
