# nextcloud-event-triggers Specification

## Purpose
TBD - created by archiving change nextcloud-event-hub. Update Purpose after archive.
## Requirements
### Requirement: File events MUST be normalized to CloudEvents (REQ-001)

`OCA\OpenConnector\EventListener\NextcloudFileEventListener` (`implements IEventListener`) MUST be
registered via `IEventDispatcher::addServiceListener` in `Application.php::register()` against
`OCP\Files\Events\Node\NodeCreatedEvent`, `NodeWrittenEvent`, and `NodeDeletedEvent`, and
`OCA\OpenConnector\EventListener\NextcloudFileTagEventListener` against `OCP\SystemTag\MapperEvent`
(filtered to file-object tag assignment/removal). On each fired event the listener MUST call
`EventService::handleNextcloudEvent(string $type, array $data)` with `type` one of
`com.nextcloud.files.node.created`, `com.nextcloud.files.node.updated`,
`com.nextcloud.files.node.deleted`, `com.nextcloud.files.node.tagged`, `source =
'/nextcloud/files'`, `subject` = the node's fileid (string-cast), and `data` carrying at minimum `path`,
`fileid`, `mimetype`, `owner`, and (for the tag event) the affected tag name(s). `handleNextcloudEvent`
MUST persist a new `event` OR-object with this shape (mirroring REQ-004's `handleObjectCreated` shape) and
then invoke `EventService::processEvent` on it, unchanged.

These four listener registrations MUST be unconditional (no feature-detection) because
`OCP\Files\Events\Node\*` and `OCP\SystemTag\MapperEvent` are stable public `OCP` API present on every
supported Nextcloud version this app targets (NC 28–34; `NodeCreatedEvent` has shipped since NC 20).

#### Scenario: a file upload produces a matching event

- **GIVEN** an active `event_subscription` with `types = ["com.nextcloud.files.node.created"]`
- **WHEN** a user uploads a new file and NC dispatches `NodeCreatedEvent`
- **THEN** a new `event` record SHALL be persisted with `type = 'com.nextcloud.files.node.created'`,
  `source = '/nextcloud/files'`, and `data.fileid`/`data.path` populated
- **AND** `EventService::processEvent` SHALL be invoked with that event, producing a matching
  `event_message`

#### Scenario: a file tag change produces a distinctly-typed event

- **GIVEN** an active `event_subscription` with `types = ["com.nextcloud.files.node.tagged"]`
- **WHEN** a user applies a system tag to a file and NC dispatches `MapperEvent`
- **THEN** the persisted `event.type` SHALL be `com.nextcloud.files.node.tagged`, distinct from the create/
  update/delete types, so a subscription can filter on tagging specifically

### Requirement: Calendar events MUST be normalized to CloudEvents, with an OCA stability caveat (REQ-002)

`OCA\OpenConnector\EventListener\NextcloudCalendarEventListener` MUST be registered against
`OCA\DAV\Events\CachedCalendarObjectCreatedEvent`, `CachedCalendarObjectUpdatedEvent`, and
`CachedCalendarObjectDeletedEvent`, emitting `com.nextcloud.calendar.object.created`,
`.updated`, `.deleted` respectively, `source = '/nextcloud/calendar'`, `subject` = the calendar object's
URI, and `data` carrying `calendarId`, `objectUri`, and (where available on the fired event) the parsed
`summary`/`dtstart`/`dtend` of the VEVENT/VTODO. This registration MUST be unconditional (the `dav` app
ships bundled with every Nextcloud instance), but because `OCA\DAV\Events\*` is app-internal API without
NC's `OCP` stability guarantee, the listener MUST defensively check for the expected accessor methods
(`method_exists`) before reading event data and MUST log-and-skip (never throw into the NC event
dispatcher) when the fired event's shape does not match what the listener expects, so an NC-version-specific
DAV event signature change degrades this one listener rather than breaking event dispatch NC-wide.

#### Scenario: a calendar event creation is captured

- **GIVEN** an active subscription with `types = ["com.nextcloud.calendar.object.created"]`
- **WHEN** a user creates a calendar event and NC dispatches `CachedCalendarObjectCreatedEvent`
- **THEN** a new `event` record SHALL be persisted with `type =
  'com.nextcloud.calendar.object.created'` and `data.calendarId`/`data.objectUri` populated

#### Scenario: an unexpected DAV event shape does not break dispatch

- **GIVEN** an NC version where `CachedCalendarObjectCreatedEvent` lacks an expected accessor
- **WHEN** the event fires
- **THEN** `NextcloudCalendarEventListener::handle()` SHALL log a warning and return without persisting an
  `event` or throwing
- **AND** no other registered listener SHALL be affected

### Requirement: Tables row events MUST be normalized to CloudEvents when the Tables app is installed (REQ-003)

`OCA\OpenConnector\EventListener\NextcloudTablesEventListener` MUST be registered against the Tables app's
row create/update/delete events ONLY when `IAppManager::isEnabledForAnyUser('tables')` returns true at
`Application.php::register()` time, emitting `com.nextcloud.tables.row.created`, `.updated`, `.deleted`,
`source = '/nextcloud/tables'`, `subject` = the row id, and `data` carrying `tableId`, `rowId`, and the
row's column values. The exact Tables event class names and payload accessors MUST be confirmed against a
live Nextcloud instance with the `tables` app installed before implementation (flagged TENTATIVE in
`discovery.md` of this change) — the requirement's `com.nextcloud.tables.row.*` type vocabulary and
feature-detection gate are normative regardless of the exact upstream class name discovered.

#### Scenario: Tables listeners are not registered when the app is absent

- **GIVEN** a Nextcloud instance without the `tables` app installed
- **WHEN** OpenConnector boots
- **THEN** no `NextcloudTablesEventListener` registration SHALL occur
- **AND** no error SHALL be logged (absence is a normal, expected state, not a fault)

#### Scenario: a row update produces a matching event when Tables is installed

- **GIVEN** the `tables` app is installed and enabled, and an active subscription with `types =
  ["com.nextcloud.tables.row.updated"]`
- **WHEN** a user edits a row and Tables dispatches its row-updated event
- **THEN** a new `event` record SHALL be persisted with `type = 'com.nextcloud.tables.row.updated'` and
  `data.tableId`/`data.rowId` populated

### Requirement: Forms submission events MUST be normalized to CloudEvents when the Forms app is installed (REQ-004)

`OCA\OpenConnector\EventListener\NextcloudFormsEventListener` MUST be registered against the Forms app's
submission-created event ONLY when `IAppManager::isEnabledForAnyUser('forms')` returns true, emitting
`com.nextcloud.forms.submission.created`, `source = '/nextcloud/forms'`, `subject` = the submission id,
and `data` carrying `formId` and the submitted answers. As with REQ-003, the exact Forms event class name
is TENTATIVE pending live-instance verification; the type vocabulary and feature-detection gate are
normative.

#### Scenario: Forms listeners are not registered when the app is absent

- **GIVEN** a Nextcloud instance without the `forms` app installed
- **WHEN** OpenConnector boots
- **THEN** no `NextcloudFormsEventListener` registration SHALL occur

#### Scenario: a form submission produces a matching event when Forms is installed

- **GIVEN** the `forms` app is installed and enabled, and an active subscription with `types =
  ["com.nextcloud.forms.submission.created"]`
- **WHEN** a user submits a form
- **THEN** a new `event` record SHALL be persisted with `type = 'com.nextcloud.forms.submission.created'`
  and `data.formId` populated

### Requirement: Non-admin subscription requests for NC-native types MUST be gated via the existing ADR-023 action matrix (REQ-005)

`EventsController::subscribe()` and `updateSubscription()` MUST call
`requireAction($user, 'event.subscribe-nextcloud-<domain>')` once per distinct NC-native domain present in
the request's `types[]`, in addition to the coarse ADR-023 actions (`event.subscribe` /
`event.update-subscription`) those methods already enforce at HEAD via
`ActionAuthService::requireAction(IUser $user, string $action)`.
A `types[]` entry maps to a domain when it matches `com.nextcloud.<domain>.*` for
`<domain>` ∈ {`files`, `calendar`, `tables`, `forms`} — explicitly EXCLUDING entries beginning with
`com.nextcloud.openregister.` (the pre-existing OR-object producer namespace, which shares the same
`com.nextcloud.` top-level reverse-DNS root; a bare prefix check would incorrectly also gate OR-object
subscription requests). Per `ActionAuthService` semantics: NC admins always pass; a non-admin passes only
when at least one of their NC groups is granted the per-family action in the matrix; a rejection is the
service's `OCSForbiddenException` surfaced as HTTP 403. Because the four per-family actions are seeded
`["admin"]` (REQ-006) and `ActionAuthService::getAllowedGroups` falls back to `["admin"]` for any action
absent from the matrix, every NC-native family is admin-only until an admin explicitly broadens it
(default-deny, including on upgraded installs whose matrix predates the new seed entries). Requests whose
`types[]` contains ONLY `com.nextcloud.openregister.*` or other non-NC-native entries MUST trigger no
per-family check — the pre-existing coarse-action-only posture for those requests is unchanged.

#### Scenario: a non-admin is rejected for a family not granted to their groups

- **GIVEN** the action matrix maps `event.subscribe-nextcloud-files` to `["admin"]` (the seeded default)
  AND the non-admin caller's groups hold the coarse `event.subscribe` grant
- **WHEN** the non-admin calls `subscribe()` with `types = ["com.nextcloud.files.node.created"]`
- **THEN** `requireAction($user, 'event.subscribe-nextcloud-files')` SHALL throw
- **AND** the response SHALL be HTTP 403

#### Scenario: a non-admin succeeds once their group is granted the family action

- **GIVEN** the action matrix maps both `event.subscribe` and `event.subscribe-nextcloud-files` to
  `["openconnector-power-users"]`
- **WHEN** a non-admin member of `openconnector-power-users` calls `subscribe()` with
  `types = ["com.nextcloud.files.node.created"]`
- **THEN** the subscription SHALL be created and the response SHALL be HTTP 200

#### Scenario: the coarse grant alone is insufficient for NC-native self-service

- **GIVEN** the matrix grants a non-admin's group `event.subscribe` but NOT
  `event.subscribe-nextcloud-calendar`
- **WHEN** they call `subscribe()` with `types = ["com.nextcloud.calendar.object.created"]`
- **THEN** the response SHALL be HTTP 403 (both layers MUST pass)

#### Scenario: an admin is never gated

- **GIVEN** any matrix state (including the seeded admin-only defaults)
- **WHEN** an NC admin calls `subscribe()` with any NC-native type
- **THEN** the request SHALL succeed (`requireAction`'s built-in admin bypass)

#### Scenario: subscribing to a non-Nextcloud-native type triggers no per-family check

- **GIVEN** a non-admin user whose groups hold the coarse `event.subscribe` grant
- **WHEN** they call `subscribe()` with `types = ["com.nextcloud.openregister.object.created"]`
  (an OpenRegister object event, excluded from the domain mapping)
- **THEN** no `event.subscribe-nextcloud-*` action SHALL be checked
- **AND** the pre-existing `subscribe()` behaviour (coarse action only) applies unchanged

### Requirement: The four per-family actions MUST be seeded into the existing action matrix (REQ-006)

`lib/actions.seed.json` MUST declare four new actions following the file's existing `<entity>.<verb>`
naming convention, each seeded `["admin"]`:

- `event.subscribe-nextcloud-files`
- `event.subscribe-nextcloud-calendar`
- `event.subscribe-nextcloud-tables`
- `event.subscribe-nextcloud-forms`

Seeding MUST flow through the existing `lib/Repair/InitializeActions.php` mechanism (which merges seed keys
into the matrix without overwriting admin customizations), and the new actions MUST appear in the existing
action-matrix admin surface — `ActionMatrixController::getMatrix()` (`GET /api/admin/action-matrix`,
`#[AuthorizedAdminSetting]`) already unions seed-file keys into its response, and
`src/views/admin/ActionAuthMatrix.vue` renders whatever the endpoint returns — so NO new settings endpoint,
authorization service, or settings view is introduced by this requirement. Admins broaden a family to a
group via the existing `PUT /api/admin/action-matrix` flow, unchanged.

#### Scenario: the seeded actions appear in the admin matrix UI without UI changes

- **GIVEN** a fresh install (or an upgrade) after this change
- **WHEN** an admin opens the existing Action authorization matrix editor
- **THEN** all four `event.subscribe-nextcloud-*` actions SHALL be listed, each defaulting to `["admin"]`

#### Scenario: an admin opens up the Tables family to a group via the existing matrix endpoint

- **GIVEN** the matrix with `event.subscribe-nextcloud-tables = ["admin"]`
- **WHEN** an admin PUTs an updated matrix mapping that action to `["admin", "openconnector-power-users"]`
  via `PUT /api/admin/action-matrix`
- **THEN** the mapping SHALL persist and a subsequent `GET /api/admin/action-matrix` SHALL return it
- **AND** members of `openconnector-power-users` (also holding `event.subscribe`) SHALL then pass REQ-005's
  gate for `com.nextcloud.tables.*` types

#### Scenario: a non-admin cannot read or write the action matrix

- **GIVEN** an authenticated non-admin user
- **WHEN** they call either `GET` or `PUT /api/admin/action-matrix`
- **THEN** the response SHALL be rejected by NC's `AuthorizedAdminSetting` enforcement (pre-existing
  behaviour, unchanged by this requirement)

#### Scenario: an upgraded install whose matrix predates the seed entries stays default-deny

- **GIVEN** an instance upgraded to this change whose stored matrix does not yet contain the new action keys
- **WHEN** a non-admin attempts an NC-native subscribe before the repair step has run
- **THEN** `ActionAuthService::getAllowedGroups` SHALL fall back to `["admin"]` for the missing action
- **AND** the request SHALL be rejected with HTTP 403 (fail-closed)

