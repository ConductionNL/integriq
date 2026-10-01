# Design: zgw-connectors-for-dossiq

Kind: config. Packaged configuration sets over existing engines; the only
code is the set loader, which exists.

## D1. Set layout

`lib/Settings/configurations/zgw-<component>.json`, one per component, in
the packaged-set format `vng-klantinteracties-adapter` introduced. Each set
holds a `source` template (base URL, auth `jwt-zgw` with client id and
secret, `apiVersion`), `synchronizations[]` and `mappings[]` referenced by
slug. Sets never reference each other by id.

## D2. Target binding

An operator installs a set against a target `register` and `schema`
(`POST /api/zgw-sets/{slug}/install`, admin only, `ZgwSetInstaller`). The
installer runs both `ZgwSetInstallGuard` checks, finds every synchronization
the set names before saving any (a set never half-binds), points each pull's
`targetType`/`targetId` at `register/schema` and each write-back's
`sourceId` at the same pair, and records the binding in the app value
`zgw_set_bindings` that the guard reads on the next install. Installing a set
again on another schema moves its binding. Nothing in the set names a fleet
app.

Corrected at build time (1 Oct 2026): OpenRegister at HEAD has neither an
`@self.externalId` nor an `external` storage strategy for synchronization
targets (`external` is an integration-provider notion there). So the pull
writes ordinary objects into the bound schema, the inbound mapping passes the
ZGW resource through unchanged (its `url` stays on the object), and the
remote `url` is the synchronization contract's origin id (`idPosition: url`),
which is what makes a later pull update the same object instead of adding one.

Seeds (`lib/Settings/register.d/zgw-consumer-sets.json`): per data set one
disabled source `zgw-set-<component>`, the inbound and write-back mappings and
the pull and write-back synchronizations, all unbound. The Objecten API
answers a static `Token`, not a ZGW JWT, so `zgw-objecten` declares `token`
auth (`ZgwSetCatalogue::authFor`); the other four sign a ZGW JWT from a
broker credential.

## D3. Freshness

`zgw-notificaties` registers one abonnement per installed component on the
remote Open Notificaties, callback to the existing endpoint
(`notificaties-api-connector` REQ-002). An inbound notification triggers a
targeted pull of the one resource named in `resourceUrl`, not a full sync.
Full sync stays scheduled as the safety net.

## D4. Write-back

For `zaken`, `documenten`, `besluiten` and `objecten` the set includes a
push synchronization (intern to extern) over the mapped properties. A
remote refusal keeps the local change and marks the object with
`syncStatus = conflict`, which `synced-from-tab` renders.

## D5. Versions

Every mapping goes through `ZgwResourceTranslatorInterface`
(`zgw-version-translation`) so one set serves a 1.x and a 1.6 store; the
`apiVersion` on the source decides.

## Risks
- A remote store with millions of zaken. Initial sync is paged and
  resumable; the operator can scope by `zaaktype`.
- Two sets targeting one schema. The installer refuses a second binding on
  the same schema and says which set holds it.
