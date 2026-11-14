# Nextcloud event triggers

Things that happen in Nextcloud itself (a file is uploaded, a calendar item
changes, a Tables row is edited, a form is submitted) reach Integriq as
CloudEvents. An event subscription then decides what a matching event does:
post it to a webhook, run a synchronization or a job, or start a flow. Failed
deliveries are retried and, when the retries run out, land on the dead-letter
page.

Spec: [`openspec/specs/nextcloud-event-triggers/spec.md`](../../openspec/specs/nextcloud-event-triggers/spec.md).

## The four event families

| Family | Event types | Source | Subject | Data | Registered |
| --- | --- | --- | --- | --- | --- |
| Files | `com.nextcloud.files.node.created`, `.updated`, `.deleted`, `.tagged` | `/nextcloud/files` | the file id | `path`, `fileid`, `mimetype`, `owner`; the tag name(s) on `tagged` | always |
| Calendar | `com.nextcloud.calendar.object.created`, `.updated`, `.deleted` | `/nextcloud/calendar` | the calendar object URI | `calendarId`, `objectUri`, and `summary`, `dtstart`, `dtend` when the event carries them | always |
| Tables | `com.nextcloud.tables.row.created`, `.updated`, `.deleted` | `/nextcloud/tables` | the row id | `tableId`, `rowId`, the row's column values | only when the Tables app is enabled |
| Forms | `com.nextcloud.forms.submission.created` | `/nextcloud/forms` | the submission id | `formId`, the submitted answers | only when the Forms app is enabled |

- **Files**: a tag change has its own type, `com.nextcloud.files.node.tagged`,
  so a subscription can react to tagging alone.
- **Calendar**: the calendar events come from Nextcloud's bundled DAV app,
  whose events are not part of Nextcloud's stable public API. When a
  Nextcloud version changes their shape, the calendar listener logs a warning
  and skips the event; it never breaks event handling for the rest of
  Nextcloud.
- **Tables and Forms**: without the app, nothing is registered and nothing is
  logged; an absent app is a normal state. The Tables and Forms event class
  names (`OCA\Tables\Event\RowAddedEvent`, `RowUpdatedEvent`,
  `RowDeletedEvent` and `OCA\Forms\Events\FormSubmittedEvent`) are still to be
  confirmed on a live instance.

Every event is stored as an `event` record and then matched against the
active subscriptions, the same path an OpenRegister object event takes.

## Matching: types, source and filters

A subscription matches an event when:

- the event and the subscription belong to the same organisation;
- `types`, when set, lists the event's type (any of them matches);
- `source`, when set, equals the event's source exactly;
- every entry in `filters` holds for the event's attributes (`type`,
  `source`, `subject`, `data`, ...).

`filters` is a list; each entry names one dialect:

| Dialect | Example | Holds when |
| --- | --- | --- |
| `exact` | `{"exact": {"subject": "4711"}}` | every named attribute equals the value |
| `prefix` | `{"prefix": {"subject": "/admin/files/Invoices/"}}` | every named attribute starts with the value |
| `suffix` | `{"suffix": {"subject": ".pdf"}}` | every named attribute ends with the value |
| `expression` | `{"expression": "type matches '/created$/'"}` | the Symfony expression is true |
| `jsonlogic` | `{"jsonlogic": {"==": [{"var": "source"}, "/nextcloud/files"]}}` | the JsonLogic rule is truthy |

## What a match does: the action

`action.kind` decides where a matching event goes. The three common kinds,
plus starting a flow:

| Kind | Fields | What happens |
| --- | --- | --- |
| `webhook` (also when `action` is empty) | `sink`, or the subscription's own `sink` | the CloudEvent is posted to the URL, signed when the subscription has a signing secret |
| `synchronization` | `synchronizationId` | that synchronization runs |
| `job` | `jobId` | that job runs |
| `flow` | `flowId` | that OpenRegister flow starts, with the CloudEvent as its input |

The subscription modal offers these as an action-type picker, each with the
matching target picker. Other kinds (`notificaties`, `mapping`, `broker`) are
described in [Events and webhooks](events.md).

## Retries and dead letters

A failed delivery, including a flow that fails to start, is retried with
exponential backoff. The defaults are a first retry after 60 seconds, each
wait four times the last, never longer than six hours, and at most five
retries. A subscription can override each of these on its own in
`retryPolicy`:

```json
{ "baseSeconds": 30, "factor": 2, "capSeconds": 3600, "maxRetries": 3 }
```

Keys left out keep their default. A delivery whose retries are used up is a
dead letter: `GET /api/events/dead-letter` lists them, and they can be
replayed or discarded one by one or in bulk. Each subscription's delivery
status is at `GET /api/events/subscriptions/{subscriptionId}/messages`.

## Who may subscribe: per-family self-service

Subscribing goes through the Action authorization matrix in Integriq's
administration settings. Two layers must both pass:

1. The general action: `event.subscribe` to create a subscription,
   `event.update-subscription` to change one.
2. For each Nextcloud family in `types`, its own action:

   | Family | Action |
   | --- | --- |
   | Files | `event.subscribe-nextcloud-files` |
   | Calendar | `event.subscribe-nextcloud-calendar` |
   | Tables | `event.subscribe-nextcloud-tables` |
   | Forms | `event.subscribe-nextcloud-forms` |

All four are seeded for `admin` only, so every family is closed to other
users until an administrator grants it to a group in the matrix. An install
whose stored matrix predates these actions is closed as well: an action
missing from the matrix counts as admin only. A refused request answers 403.

Administrators always pass. OpenRegister object events
(`com.nextcloud.openregister.*`) share the `com.nextcloud.` prefix but are not
a Nextcloud family: subscribing to them needs only the general action.

### Example: let a group subscribe to file events

1. Open the Action authorization matrix.
2. Add the group to both `event.subscribe` and
   `event.subscribe-nextcloud-files`.
3. A member of that group can now create a subscription with
   `types = ["com.nextcloud.files.node.created"]`. Asking for
   `com.nextcloud.calendar.object.created` is still refused until the group
   also holds `event.subscribe-nextcloud-calendar`.

<!-- Screenshot owed: the matrix editor's event.subscribe-nextcloud-* rows
     (nextcloud-event-hub-verification task 5). -->
