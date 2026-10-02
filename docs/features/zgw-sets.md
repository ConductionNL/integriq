<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

# ZGW consumer sets

A case app keeps its cases in a register you choose. When those cases also live in a ZGW store, such as Open Zaak, you install a ZGW consumer set. The set reads the store into that register and sends local changes back.

This page is for the administrator who installs a set.

## The six sets

| Set | Component | Reads | Writes back | Source to fill in | Credential |
|---|---|---|---|---|---|
| `zgw-zaken` | Zaken API | `/zaken` | yes | `zgw-set-zaken` | `zgw-set-zaken-client-secret` |
| `zgw-documenten` | Documenten API | `/enkelvoudiginformatieobjecten` | yes | `zgw-set-documenten` | `zgw-set-documenten-client-secret` |
| `zgw-catalogi` | Catalogi API | `/zaaktypen` | no | `zgw-set-catalogi` | `zgw-set-catalogi-client-secret` |
| `zgw-besluiten` | Besluiten API | `/besluiten` | yes | `zgw-set-besluiten` | `zgw-set-besluiten-client-secret` |
| `zgw-objecten` | Objecten API | `/objects` | yes | `zgw-set-objecten` | `zgw-set-objecten-token` |
| `zgw-notificaties` | Notificaties API | notifications | no | `zgw-set-notificaties` | `zgw-set-notificaties-client-secret` |

Five sets carry data. `zgw-notificaties` carries none: it subscribes the data sets you installed to the store's notifications, so a change in the store shows here within a minute.

## What lands in your schema

Your schema holds the store's own ZGW shape. Each resource arrives field for field as the store sends it, with its `url`. Nothing translates it to another shape or another ZGW version.

So match your schema to the version your store speaks. A store on Zaken API 1.5 fills your schema with 1.5 fields. A store on 1.6 fills it with 1.6 fields. The source's `apiVersion` records which one you connected.

The store's `url` is also the key of each synchronization contract. A second run updates the same objects and creates no duplicates.

## Install a data set

1. Open the set's source, for example `zgw-set-zaken`. Set its location to your store's address, such as `https://open-zaak.example.nl/zaken/api/v1`.
2. Store the client secret in the credential broker under the name in the table. The Objecten API takes a token instead of a secret.
3. Check `apiVersion` on the source and set it to the version your store speaks.
4. Turn the source on. Every source ships off.
5. Add a `syncStatus` property (a string) to the schema you bind, if the set writes back. Integriq marks a refused write-back there as `conflict`.
6. Install the set against your register and schema:

```http
POST /index.php/apps/integriq/api/zgw-sets/zgw-zaken/install
Content-Type: application/json

{"register": "cases", "schema": "case"}
```

The answer names the bound synchronizations. `GET /index.php/apps/integriq/api/zgw-sets` lists every set with its binding.

## Install notifications

Install `zgw-notificaties` after at least one data set. Fill in and turn on its source `zgw-set-notificaties` the same way, then install it without a register and schema. Integriq registers one abonnement per installed data set. A store that refuses an abonnement is named in the answer under `refused`, with the store's reason.

## When the installer refuses

The installer says why in your own language. It refuses when:

- you name a set that is not one of the six;
- you install `zgw-notificaties` before any data set;
- you leave out the register or the schema;
- another set is already bound to that schema. The refusal names that set. Two sets on one schema overwrite each other on every run, and both still report a healthy synchronization;
- the packaged set file, its synchronizations or its source are missing on this instance. Repair or reinstall Integriq, then install the set again.

## Writing back

A set that writes back sends a local change to the place the object came from, by its `url`, as a PATCH. A store that refuses the change (a 4xx answer) leaves your edit in place and sets `syncStatus` to `conflict`. The next accepted push sets it back to `synced`. A server error (5xx) is handled like any other synchronization error.
