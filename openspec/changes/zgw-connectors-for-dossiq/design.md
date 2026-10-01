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

Decided at build time (2 Oct 2026), task 3:

- **Which remote resource a push writes.** A bound object was pulled, so it
  already exists in the store and carries its remote `url` (the inbound
  mapping passes it through, D2). The push contract is keyed by the local
  object and starts without a target id, which the engine read as "create":
  the first local edit of a pulled zaak would have POSTed a second zaak. The
  push synchronizations therefore declare `targetConfig.targetIdPosition: url`:
  when the contract has no target id and the object carries one at that path,
  the engine updates that resource (`updateMethod`, PATCH) instead of creating
  one. The url must lie under the target source's location, for the same
  reason as the pull (D3): the call carries the set's credentials. An object
  without a url (made locally) is still created, and the store's answer
  becomes its target id, as before.
- **What counts as a refusal.** A 4xx answer to the create or update. The
  engine calls with `http_errors` off, so a refusal used to read as success:
  the update path merged the error body into the target object and the push
  reported done. A push synchronization that declares
  `targetConfig.conflictStatusProperty` (the ZGW pushes declare `syncStatus`)
  now raises `TargetWriteRefusedException` on a 4xx; one that does not keeps
  today's behaviour, so no other synchronization changes. A 5xx is an outage,
  not a conflict: it is logged as today and marks nothing.
- **Keeping the local edit.** The push runs after the local save (the
  OpenRegister object event), and nothing on the refusal path writes the
  object's data back, so the edit stays. The object handler catches the
  refusal and writes only `syncStatus = conflict` onto the object, as a silent
  save (no object events), because a normal save would fire the push again,
  be refused again and loop. A later push the store accepts clears
  `conflict` to `synced` the same way, so the marker means "the store refused
  the last write", not "the store once refused a write".
- **Where it shows.** `synced-from-tab` renders the synchronization contracts
  of an object and does not read `syncStatus` yet; showing the marker there is
  task 4 (docs, i18n and the screen), not this task.
- **What the bound schema needs.** The marker is a property of the operator's
  own schema. If that schema refuses the property, the marker write is
  refused, logged with the schema named, and the refusal itself stays in the
  synchronization log. The install guide (task 4) tells the operator to add
  `syncStatus` (string) to the schema they bind.

## D5. Versions

Every mapping goes through `ZgwResourceTranslatorInterface`
(`zgw-version-translation`) so one set serves a 1.x and a 1.6 store; the
`apiVersion` on the source decides.

## Risks
- A remote store with millions of zaken. Initial sync is paged and
  resumable; the operator can scope by `zaaktype`.
- Two sets targeting one schema. The installer refuses a second binding on
  the same schema and says which set holds it.
