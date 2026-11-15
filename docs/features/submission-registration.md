<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

# Registering form submissions

Portaliq can register a form submission in an outside system through integriq. An administrator defines a registration target. Portaliq names the target on a form and hands each submission over with one command. Integriq creates the record outside and returns its reference.

This page is for the administrator who sets up targets and for the developer of the app that hands submissions over.

## Registration targets

A target is a `registrationTarget` object in integriq's register. Only administrators can read or change one.

| Property | What it holds |
|---|---|
| `slug`, `title` | How apps name the target, and how the portal's picker shows it |
| `kind` | `json`, `objects-api`, `zgw` or `stuf-zds` |
| `sources` | The integriq sources to send on: `endpoint` for json, `objects` for objects-api |
| `mapping` | The Mapping that turns the hand-over into the outside shape |
| `json` | `path` on the endpoint source, and an optional JSON Schema the mapped body must match |
| `objectsApi` | `objecttype` url and `objecttypeVersion` |
| `allowedApps` | The apps that may use the target. Defaults to `portaliq` |
| `enabled` | Whether apps may use the target |

Kinds available now:

- **json**: integriq checks the mapped body against the schema, if one is set, then sends one POST to the path. The reference is the `id` in the answer, or the submission reference when there is no `id`.
- **objects-api**: integriq creates one object of the configured type and version, with the mapped submission as `record.data`. The reference is the object's uuid. A target that sends files to the Documenten API (`informatieobjecttype`) is refused for now.

`zgw` and `stuf-zds` targets can be stored, but integriq refuses to register at them until their legs are built.

## The picker list

`GET /apps/integriq/api/registration-targets?app=portaliq` lists the enabled targets the app may use. Any signed-in user can call it. Each entry has only `slug`, `title` and `kind`, never the sources, mapping or settings.

## The command

An app dispatches `OCA\Integriq\Event\SubmissionRegistrationRequestedEvent` with its app id, the target slug and the hand-over: `reference`, `form`, `submittedAt`, `applicant`, `answers`, `fields`, and `pdf` and `attachments` as Nextcloud file ids. Integriq never accepts file bytes.

Integriq never throws. It writes one of two answers on the event:

- `{ ok: true, externalReference, externalUrl }`
- `{ ok: false, code, retryable, message, status }`

| Code | Retryable | When |
|---|---|---|
| `unknown-target` | no | The target does not exist or is disabled |
| `not-allowed` | no | The app is not in the target's `allowedApps` |
| `invalid-handover` | no | No reference, no answers, or a file that is not a file id |
| `mapping-failed` | no | No mapping, the mapping failed, or the body does not match the schema |
| `source-unavailable` | yes, when the outside system answered 5xx or 429 | The outside system is down, or the target cannot be served |
| `outside-refused` | no | The outside system refused the request (any other non-2xx status) |

An event that `isHandled()` reports as false means integriq is not installed. Treat it as a retryable refusal.

## Each submission once

Integriq keeps a `registrationRecord` for each target, app and submission reference. A repeat of a registration that is already done returns the stored reference without contacting the outside system. A repeat of a registration that failed picks up after the last step that was completed.
