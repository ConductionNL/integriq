<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

# Objecten API and Objecttypen API

Other suppliers in a municipality's landscape read and write the objects a case refers to through the VNG Objecten API: a parking permit, a tree, a report about a street. Integriq serves that API, and the Objecttypen API beside it, over your own registers. No leaf app ships a controller for either standard.

This page is for the administrator who publishes objecttypes and hands out tokens.

## What is served

| Path | Verbs | What it answers |
|---|---|---|
| `/api/v2/objecttypes` | GET | Every objecttype published here |
| `/api/v2/objecttypes/{uuid}` | GET | One objecttype, with `jsonSchema` read from the schema at that moment |
| `/api/v2/objecttypes/{uuid}/versions/{version}` | GET | One allowed version; a version the objecttype does not list is a 404 |
| `/api/v2/objects` | GET, POST | A page of objects of one `type`, or a new object |
| `/api/v2/objects/{uuid}` | GET, PUT, PATCH, DELETE | One object |
| `/api/v2/objects/search` | POST | A search with a geometry |

The paths sit under `/index.php/apps/integriq`. A list needs `type`: the objecttype's published uuid, or its URL as the standard sends it (`https://<host>/api/v2/objecttypes/<uuid>`), which is read as the uuid it ends in. A token holds a permission per objecttype, so a list across every type is refused rather than answered. Lists also take `data_attrs`, `date`, `registrationDate`, `ordering`, `page` and `pageSize` (100 by default, 500 at most).

## Publish an objecttype

An objecttype is an OpenRegister schema you name on purpose. Nothing guesses the mapping from a name, because two registers can both hold a schema called `melding`.

There are two ways to publish one.

1. **Configure it in Integriq.** Create an `objecttype` object in the Integriq register with `publishedUuid`, `name`, `register` and `schema`, and optionally `versions`. Keep the `publishedUuid` when you rebuild a register: counterparties have registered that uuid.
2. **Let a leaf app declare it.** An app ships `lib/Settings/objecttypes.json` with a list under `objecttypes`, each entry carrying `uuid`, `name`, `register`, `schema` and `versions`. Integriq reads the file of every enabled app on each request, so there is no copy to go stale.

When both name the same uuid, your configuration wins. The app's declaration is refused and logged with the app's name, never merged.

## Hand out a token

A caller sends `Authorization: Token` followed by its key. Two checks run, and both start closed:

1. **The token.** An `objecten_token` object names who it belongs to, the credential that holds the key, the user it runs as, and a permission per published objecttype uuid: `read` or `read_write`. The key itself lives in the OpenRegister credential broker; the token only names the credential.
2. **The register's own rights.** A request that passes the token runs as the token's user. OpenRegister's authorization, multitenancy and field rules then apply as they would to that user.

No header, or a key nobody knows, is a 401. An objecttype the token does not name, or a write with a `read` permission, is a 403. A declared objecttype answers nobody until you give a token a permission for it.

## Limits and announcements

- Every route is rate limited per client address: 600 requests a minute to read, 120 a minute to write.
- A write emits a CloudEvent of type `nl.vng.objecten.object.<actie>`. A subscription whose action forwards to a Notificaties API carries it to the `objecten` kanaal.

## In the connector catalogue

The catalogue lists this as one adapter card, *Objecten API and Objecttypen API*. It is always available: the routes exist on every install, and they answer once a token exists.

Next: create your first `objecten_token` with `read` on one objecttype, and call `GET /api/v2/objects?type=<uuid>` with it.
