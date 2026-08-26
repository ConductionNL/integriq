# integration-leaves Specification Delta — leaf-integrations

New capability: which OpenRegister integration leaves Integriq declares, on which schemas and surfaces, and why the rest are OFF. This delta claims REQ-OCL-001..REQ-OCL-004.

## ADDED Requirements

### Requirement: The leaf surface is declared in the register and the manifest, and is exactly four leaves on two schemas (REQ-OCL-001)

Integriq's integration-leaf surface SHALL consist solely of `configuration.linkedTypes` declarations in `lib/Settings/integriq_register.json` plus manifest integration widgets in `src/manifest.json`, and SHALL be exactly: `source` → `["files", "deck", "talk"]` with three widgets on SourceDetail (`src-files` "Supplier documents", `src-deck` "Incident follow-ups", `src-talk` "Incident war-room"); `synchronization` → `["calendar"]` with no manifest widget (surfaced via the shared object sidebar, because SynchronizationDetail is a `type: "custom"` page the manifest cannot reach). No other schema SHALL carry `linkedTypes`, Integriq SHALL NOT implement an `IntegrationProvider`, and every declared id MUST survive OpenRegister's import-time validation (`Schema::validateLinkedTypesValue()` rejects ids absent from the provider registry, failing the import loudly).

#### Scenario: the leaf surface is enumerable from two files

- GIVEN the repository at this change's completion
- WHEN `lib/Settings/integriq_register.json` is searched for `linkedTypes` and `src/manifest.json` for `"type": "integration"`
- THEN exactly the surface above is found (2 schema declarations, 3 widgets)
- AND `lib/` contains no `IntegrationProvider` implementation
- @e2e exclude static repo-shape assertion — verified by the task acceptance-criteria greps, not a DOM behaviour

#### Scenario: an invalid leaf id cannot ship

- GIVEN a `linkedTypes` value naming a provider that does not exist
- WHEN the register is imported
- THEN the import fails with the list of valid ids rather than silently dropping the leaf
- @e2e exclude backend import validation — OpenRegister's validator, covered by its own suite

### Requirement: Leaves are pure link surfaces and never read or write source properties (REQ-OCL-002)

No leaf on `source` SHALL read or write any register property of the source object — in particular none of the plaintext credential properties (`password`, `apikey`, `secret`, `jwt`, `authenticationConfig`). The files, deck, and talk leaves link external entities (Nextcloud files, Deck cards, Talk conversations) to the object by id; linking, unlinking, or interacting with a linked entity SHALL leave the source object unchanged, and no credential value SHALL be rendered inside any leaf widget. Leaves SHALL render only after the page's normal object read has succeeded, so no user gains sight of a source through a leaf that they could not already open.

#### Scenario: an incident war-room is linked without touching the source

- GIVEN a source whose sync has failed repeatedly
- WHEN an admin links a Talk conversation via the `src-talk` widget on SourceDetail
- THEN the conversation is linked to the source object and shown in the widget
- AND the source object's properties are byte-identical before and after linking
- @e2e tests/e2e/spec-coverage/integration-leaves.spec.ts

#### Scenario: no credential value appears inside a leaf widget

- GIVEN a source with a configured `apikey` and linked files, cards, and a conversation
- WHEN the three leaf widgets on SourceDetail are rendered
- THEN no widget's DOM contains the credential value
- @e2e tests/e2e/spec-coverage/integration-leaves.spec.ts

### Requirement: The synchronization calendar leaf is planning-only and never a scheduler (REQ-OCL-003)

The `synchronization` schema SHALL declare `calendar` in `configuration.linkedTypes` so maintenance windows and cutover dates can be linked as CalDAV events to the data flow they affect, surfaced through the shared object sidebar. The leaf SHALL NOT read or write any synchronization property and SHALL NOT influence execution: scheduling remains exclusively the job mechanism (`job.interval`, `job.nextRun`, the job list), and linking, moving, or deleting a calendar event SHALL never trigger, delay, or suppress a synchronization run.

#### Scenario: a maintenance window is linked to a synchronization

- GIVEN a synchronization whose supplier announces a maintenance window
- WHEN an admin links a calendar event for that window to the synchronization object
- THEN the event is visible from the synchronization's object sidebar
- AND the synchronization's schedule, status, and next run are unchanged
- @e2e exclude sidebar surface on a `type: "custom"` page — the shared-sidebar render path is OpenRegister's, covered there; Integriq asserts the schema declaration in Task 1 and revisits a widget-level e2e when SynchronizationDetail becomes manifest-driven

### Requirement: Every other schema and leaf type stays OFF until a spec change argues otherwise (REQ-OCL-004)

No schema other than `source` and `synchronization` SHALL carry `linkedTypes`, and no leaf type other than the four declared SHALL be adopted, without a change to this capability that argues against the recorded rationale: credential/authorization-bearing schemas (`endpoint`, `consumer`, `event_subscription`, `rule`, `lti_*`, `eudi_*`) because link surfaces invite secrets into linked entities; `mapping` and `job` because their collaboration artefact is versioned export, not cards or chats; log schemas because nothing should be attachable to evidence; `sync_item_dead_letter` deferred (not refused) until a per-object surface exists; and consumer-style leaf types (mail intake, contacts, forms, polls, photos, maps, and the rest) because no integrator workflow maps to them on control-plane objects.

#### Scenario: the OFF list is enforced by the declared surface

- GIVEN the imported register
- WHEN every schema's configuration is inspected
- THEN only `source` and `synchronization` carry `linkedTypes`
- AND no dead-letter, log, endpoint, mapping, job, rule, or consumer schema carries any
- @e2e exclude static register-shape assertion (absence) — covered by the Task 1 acceptance-criteria grep
