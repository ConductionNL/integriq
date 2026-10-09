# zgw-consumer-connectors Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- zgw-connectors-for-dossiq

## Purpose

Packaged connector sets that consume an external ZGW store (Open Zaak,
Objecten API, Open Notificaties) into OpenRegister objects with `external`
storage, keep them fresh on notification, and write changes back. A case app
binds a set to its own schema and reads the objects as its own. Requested by
the dossiq competitor analysis, finding B21.

## ADDED Requirements

### Requirement: Six packaged, slug-referenced ZGW consumer sets (REQ-ZGWC-001)

Integriq MUST ship configuration sets `zgw-zaken`, `zgw-documenten`,
`zgw-catalogi`, `zgw-besluiten`, `zgw-objecten` and `zgw-notificaties`, each
a source template with `jwt-zgw` auth (the Objecten API's `token` for
`zgw-objecten`) and `apiVersion`, synchronizations and
mappings referenced by slug (ADR-015). Every mapping MUST pass the store's
own ZGW resource through unchanged, so a bound schema holds the store's own
shape; no set MUST translate it to another shape or version (design D5). No set MUST name a fleet app.

#### Scenario: The zaken set pulls a 1.6 store
- GIVEN a mock-mode source with `apiVersion = 1.6` and three fixture zaken
- WHEN the `zgw-zaken` set is installed and its synchronization runs
- THEN three objects carrying the remote urls exist in the bound schema, each with a synchronization contract whose origin id is that url
- @e2e exclude synchronization run; covered by PHPUnit with the mock-mode fixture

#### Scenario: A bound schema holds the store's own shape
- GIVEN a mock-mode source whose zaken carry the store's own ZGW fields
- WHEN the `zgw-zaken` set's synchronization runs
- THEN each object in the bound schema carries every field of its zaak as the store sent it, untranslated, and no set file names a translator
- @e2e exclude synchronization run; covered by PHPUnit with the mock-mode fixture and the seed test

### Requirement: A set binds to an operator-chosen register and schema (REQ-ZGWC-002)

Installing a set MUST ask for a target `register` and `schema`, bind the
set's pulls to write into it and its write-backs to read from it, and refuse a second set on a
schema already bound, naming the set that holds it.

#### Scenario: An operator binds zaken to the case schema
- GIVEN the installer and a target schema
- WHEN the operator installs `zgw-zaken` against it
- THEN the synchronization targets that schema and the source page shows the binding
- e2e: `tests/e2e/zgw-set-install.spec.ts`

#### Scenario: A second set on the same schema is refused
- GIVEN `zgw-zaken` bound to a schema
- WHEN the operator installs `zgw-objecten` against the same schema
- THEN the installer refuses and names `zgw-zaken`
- e2e: `tests/e2e/zgw-set-install.spec.ts`

### Requirement: An external change shows within a minute and a local change writes back (REQ-ZGWC-003)

`zgw-notificaties` MUST register one abonnement per installed component and
an inbound notification MUST trigger a pull of the one resource it names. A
local change on a `zaken`, `documenten`, `besluiten` or `objecten` object
MUST be pushed by the set's write-back synchronization; a remote refusal
MUST keep the local change and set `syncStatus = conflict`.

#### Scenario: A remote status change arrives
- GIVEN an installed `zgw-zaken` set and an abonnement
- WHEN a notification for one zaak arrives at the callback
- THEN only that zaak is pulled and its local object reflects the new status
- @e2e exclude callback and pull; covered by PHPUnit with a recorded notification

#### Scenario: The store refuses a write-back
- GIVEN a local edit on a zaak and a remote that answers 400
- WHEN the push runs
- THEN the local edit stays and the object carries `syncStatus = conflict`
- @e2e exclude push failure path; covered by PHPUnit
