<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

# Telephony (CTI) for the KCC

When a call comes in, the agent in the klantcontactcentrum (KCC) wants to see who is calling before they pick up. A source of type `cti` is the link between the phone system and the case app's KCC panel. The phone system posts its call events to integriq; integriq looks the caller up in Klantinteracties and hands the case app a typed `CallEvent` with the caller and their cases. Integriq owns the telephony side; the panel itself belongs to the case app.

This page is for the administrator who connects a phone system.

## What happens on a call

1. The phone system posts an event (`ringing`, `answered`, `ended` or `transferred`) to the source's events address.
2. Integriq checks the request against the source's secret. A request that does not pass is answered with 401 and nothing else happens.
3. The caller's number is normalised to E.164 and looked up in the Klantinteracties source you picked. Only an exact match on the full number counts: two numbers that differ only by their country code are two different people, and a number shared by more than one party matches nobody.
4. Every accepted event is written to the call log and dispatched as a `CallEvent`, also on the CloudEvents fan-out as `nl.conduction.integriq.call.<kind>`. A retried event (same call id and kind) is dispatched once.
5. When the agent asks for it after the call has ended, the panel records a contact moment for the call through `POST /api/kiss/klantcontacten` with the `callId`. Integriq never records one by itself.

A withheld number and a number nobody matches both arrive with no caller, but the event says which of the two it is, so the panel can show the right message.

## Connect it

1. Make a source of type "KISS (Klantinteracties)" for your Klantinteracties API, if you do not have one yet, and give it the REST binding.
2. Make a source of type "Telephony (CTI)". The editor shows its telephony settings.
3. Pick the telephony binding: "Webhook" for a real phone system, "Log" to post test events in integriq's own shape.
4. Fill in the secret. For the webhook binding, a signature secret is preferred, because the signature covers the whole request body; use a shared secret only when the phone system cannot sign.
5. For the webhook binding, map the phone system's event onto ours: the path to the events in the body, where each field sits, and the word it uses for each kind of call event.
6. Pick the Klantinteracties source to look callers up in.
7. Save the source and give the events address the editor now shows to the phone system.

## The settings

These live in the source's `configuration`. The editor writes them for you.

| Setting | Key | Notes |
|---|---|---|
| Telephony binding | `provider` | `webhook` or `log`. Any other value refuses every event; there is no fallback. |
| Shared secret | `sharedSecret` | Sent by the phone system in the `X-Api-Key` header. Required for `log`. |
| Signature secret | `signatureSecret` | `webhook` only. The request body is signed with it; the signature arrives in `X-Signature`, with `X-Timestamp`. |
| Events path | `eventsPath` | `webhook` only. Dotted path to the list of events; empty when the body is one event. |
| Field mapping | `fieldMapping` | `webhook` only. Our field (`kind`, `callId`, `callerNumber`, `agentId`, `at`, `durationSeconds`) to the dotted path in the phone system's event. |
| Call kinds | `kindMapping` | `webhook` only. Stored as the phone system's word to ours, for example `{"alerting": "ringing"}`. An event of a kind that maps to none of ours is ignored. |
| Look callers up in | `callerLookupSource` | The id of a KISS source. Only an enabled source with the REST binding is asked; without one, every caller is unknown. |

A webhook source with neither a signature secret nor a shared secret refuses every event: an unauthenticated public endpoint is never what anybody meant to configure.

## The events address

`POST /index.php/apps/integriq/api/cti/{sourceId}/events`

| Answer | When |
|---|---|
| 200 `{"received": n}` | The request passed; `n` events were accepted. A body with nothing integriq handles answers 0, so the phone system does not keep retrying it. |
| 400 `malformed_body` | The request passed, but the body is not a JSON object. |
| 500 `processing_failed` | Handling the events failed; the error is logged. |
| 401 `unauthorized` | Any refusal: an unknown or disabled source, a missing binding, a wrong secret or signature. The answer is the same each time, so the address does not tell a caller which sources exist. |

## What is kept

Each accepted event is a row in the `call_event` schema, readable only by administrators and the row's owner, because it holds a phone number. Rows are dropped 30 days after they were written, by OpenRegister's retention sweep. The duration of a call is kept on the local contact moment, not sent to Klantinteracties, which has no field for it.

## Related

- [Sources](sources.md)
- [Events & Webhooks](events.md)
