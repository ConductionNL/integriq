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
targeted pull of the one resource it names, not a full sync. Full sync stays
scheduled as the safety net.

Decided at build time (2 Oct 2026), task 3:

- **Where the pull starts.** `NotificatiesSubscriberService::handleInboundNotification()`
  emits the CloudEvent as before and then hands the notification to
  `ZgwNotificationPullListener`. A pull that fails never fails the callback: the
  notification was received, the CloudEvent exists, and the scheduled full sync
  catches the resource up. The failure is logged with the resource url.
- **Which set.** The notification's `kanaal` names the component:
  `zaken` to `zgw-zaken`, `documenten` to `zgw-documenten`, `besluiten` to
  `zgw-besluiten`, `objecten` to `zgw-objecten`, and `zaaktypen`,
  `informatieobjecttypen` and `besluittypen` to `zgw-catalogi`. Only an
  installed set pulls (its slug holds a binding in `zgw_set_bindings`); a
  notification for a component nobody installed does nothing.
- **Which resource.** The `hoofdObject`, falling back to `resourceUrl`. A set's
  objects are main objects (a zaak, not its status), so a status notification
  pulls the zaak it belongs to, which is what "its local object reflects the
  new status" needs. A `destroy` of the main object itself pulls nothing (the
  resource is gone); the scheduled full sync removes it, as it does today.
- **Which host.** The url must lie under the set's source `location`. A
  notification naming any other host is refused and logged: an inbound message
  must never make Integriq send a set's credentials somewhere else.
- **How.** `SynchronizationService::getObjectFromSource()` reads the one
  resource through the set's pull synchronization and its source, then
  `replaySynchronizationItem()` runs it through the same per-item path a full
  run uses (mapping, contract keyed by the origin id `url`, so the existing
  object is updated). Running `synchronize()` with a narrowed endpoint was
  rejected: it persists the synchronization it ran with (the narrowed endpoint
  would replace the real one) and a full-mode run garbage-collects every object
  the narrowed fetch did not return.

**How `zgw-notificaties` installs without a data schema.** The guard keeps
requiring a register and a schema for every set that carries data: a data set
installed against nothing still runs and writes nowhere while reporting
success, which is what the guard exists to stop. `zgw-notificaties` carries no
data, so it is a subscription set (`ZgwSetCatalogue::SUBSCRIPTION_SETS`), and
the guard asks a different question of it: is there something to subscribe to?
Installing it

1. is refused while no data set is installed (an abonnement on nothing would
   be a live remote subscription that pulls nothing);
2. registers, through `NotificatiesSubscriberService::createAbonnement()` on the
   seeded source `zgw-set-notificaties`, one abonnement per installed data
   set's kanalen that has none yet, and records them in the app value
   `zgw_set_subscriptions` (`{kanaal: abonnement uuid}`), so installing it again
   after a new data set only adds the new kanalen;
3. binds no synchronization. The set file therefore names no synchronization
   or mapping: the pull is the listener above, not a mapping, and the two
   slugs it used to name were never seeded.

Rejected: exempting `zgw-notificaties` inside `refuse()` by slug (one more
special case in the check that guards the data sets), and a dummy schema (an
empty schema bound to a set that writes nothing reads as a real binding and
blocks a data set from that schema).

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
