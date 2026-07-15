# Nextcloud Forms as a synchronization source, and outbound answer mapping

OpenConnector can read a **Nextcloud Forms** form's submissions into a
synchronization, and map a Forms submission's answers — resolved by
question, not raw numeric position — into an outbound call to an external
system on the Forms submission trigger, using the same Source →
Synchronization → SynchronizationContract machinery, `CallService`
transport, `MappingService` transformation, and `EventService`
subscription-dispatch pipeline as every other source/target kind.

Forms is a **soft (feature-detected) runtime dependency**: when the Forms
app is absent or disabled, the `nextcloud-form` kind simply does not appear
in the synchronization editor, and any synchronization or outbound mapping
subscription already configured with it fails cleanly with a "Forms app is
not enabled" config error before any HTTP call is attempted.

> **Note:** the exact Forms OCS route base path
> (`index.php/apps/forms/api/v3/...`) is TENTATIVE — verified against the
> public `nextcloud/forms` upstream source, not a live instance with the
> `forms` app installed. If a live instance shows the OCS-enveloped
> `ocs/v2.php/...` form is required instead, only the internal REST client's
> base path needs to change — nothing else in this document.

## Two directions, one client

- **Inbound (`nextcloud-form` sync source):** reads a form's submissions
  page-by-page into the existing mapping/transformation pipeline — read
  only, there is no `nextcloud-form` target.
- **Outbound (submission → external call):** on a Forms submission event, a
  new `event_subscription.action.kind: 'mapping'` fetches the full
  submission's answers, resolves them by question, runs them through a
  `Mapping`, and calls an external system via `CallService`.

Both directions go through the same Forms API client, so the Source (and
its credential) configured for one direction works for the other.

## How the source is modelled

A `nextcloud-form` synchronization's `sourceId` points at an ordinary
**Source** object (register `openconnector`, schema `source`) — no new
entity type. The Source's `location` is the base URL of the Nextcloud
instance hosting the form, and its `authentication` is a normal Basic Auth
credential (or a brokered `credentialRef`), exactly like any other HTTP
source. Form-specific settings live in the free-form config blob:

| Config key | Side   | Meaning                          |
|------------|--------|-----------------------------------|
| `formId`   | source | The Forms form id (required, integer). |

## Forms as a source

OpenConnector reads submissions page-by-page
(`GET .../forms/{formId}/submissions`) and feeds each submission (including
its `answers`) into the mapping pipeline exactly as any other source's
fetched objects. The Forms submission id is used as the origin id, and
change detection uses the same order-independent hash as every other
source. `nextcloud-form` is **source-only** — writing submissions into
Forms is out of scope.

## Answer-by-question resolution

A `Mapping` (or an outbound `action.kind: 'mapping'` configuration)
references a question either by its numeric **id** (always unambiguous) or
by its exact **text**:

- A text reference matching exactly one question resolves via that
  question's id.
- A text reference matching **two or more** questions is a hard config
  error naming the ambiguous text and every matching question id — never a
  first-match guess.
- A `multiple`/`multiple_unique`-type question (checkbox/multi-select)
  resolves to an **array** of every selected option's text; every other
  question type resolves to a single scalar, or `null` when unanswered.

## Outbound: submission → external call

An `event_subscription` matching a Forms submission event can declare:

```json
{
  "action": {
    "kind": "mapping",
    "mappingId": "<mapping uuid>",
    "sourceId": "<source uuid>",
    "endpoint": "/leads",
    "method": "POST"
  }
}
```

The Forms submission trigger's own event payload does not carry the
submission's answers, so the dispatch independently fetches the full
submission (and the form's questions) via the Forms API before resolving
answers, running the `Mapping`, and calling `CallService::call()` against
the resolved `Source`/`endpoint`. Success and failure follow the same
retry/backoff/dead-letter machinery as a `webhook`/`synchronization`/`job`
action — a resolution or mapping failure for one submission does not
permanently misconfigure the subscription.

## Editor UI

In the synchronization editor, selecting the **Nextcloud Form** source kind
lets you:

1. Pick the **Source** whose credential reaches the Forms API.
2. Pick a **form** the source can access (fetched live).
3. See a read-only **field reference list** of the form's questions (id,
   text, type), which visually flags array-valued (`multiple`/
   `multiple_unique`) questions and any question text that is ambiguous
   within the form — so you know exactly which id/text references are safe
   to use before writing a `Mapping` or outbound action configuration.

The kind is only offered as a **source**; it is never offered as a target
kind, regardless of whether Forms is enabled. It is only offered at all
when the backend reports the Forms app is enabled.

## Out of scope

- Writing submissions into Forms from external data.
- Building/editing forms in OpenConnector.
- Forms submission-created events as a synchronization trigger — that is
  covered by the `nextcloud-event-hub`/`nextcloud-event-triggers` change;
  this document only covers the `nextcloud-form` source and the
  `action.kind: 'mapping'` outbound dispatch it feeds.
