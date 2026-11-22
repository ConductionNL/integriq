# Telephony (CTI) sources

## Overview

A contact centre agent who picks up the phone should see who is calling and
which cases that caller has open, without typing the number into a search box.
A **CTI source** lets the phone system tell integriq about each call. Integriq
checks that the request really came from that phone system, turns the vendor's
event into one of four call kinds, looks the calling number up in a KISS
contact register, and hands the call to the app that shows it as a typed
`CallEvent`.

Integriq writes nothing to the contact register when a call comes in. A
contact moment is recorded only when the agent's app asks for one, against the
call id it names.

## Create a CTI source

1. Open **Sources** and create a source.
2. Set **Type** to **Telephony (CTI)**. The raw configuration editor is
   replaced by the telephony settings below.
3. Pick a **Telephony provider**:
   - **Phone system webhook**: a real phone system that posts its events to
     integriq. You map its fields below.
   - **Sandbox (log only)**: for trying the flow out. It reads the event in
     integriq's own field names and needs only a shared secret.
4. Set a secret. A source with no secret refuses every call. The form shows a
   warning until one is set.
   - **Signature secret** (webhook only): the phone system signs each request
     body with it (HMAC, header `X-Signature`, with `X-Timestamp`). Use this
     when the phone system can sign, because a signature covers the body as
     well as the sender.
   - **Shared secret**: the value the phone system sends in the `X-Api-Key`
     header.
5. Pick the **Caller lookup source**: the KISS source whose contact register is
   searched for the calling number. Only enabled KISS sources on the REST
   provider are offered, because the sandbox register cannot search. Without a
   lookup source every caller is shown as unknown.
6. Save. The form then shows the **Events URL**,
   `/apps/integriq/api/cti/<source id>/events`. Enter it in the phone system as
   the address it posts call events to.

## Map the phone system's events (webhook)

**Events path** is the dotted path to the list of events in the request, for a
phone system that sends several at once. Leave it empty when each request is
one event.

**Field mapping** says where each call field is in an event, as a dotted path.
Leave a field empty when the phone system already uses integriq's name.

| Field | Integriq name | What it holds |
|-------|---------------|---------------|
| Event kind | `kind` | The vendor's word for what happened |
| Call id | `callId` | The phone system's id for the call; an event without one is dropped |
| Caller number | `callerNumber` | The calling number, normalised to E.164 |
| Agent id | `agentId` | The agent the call is for |
| Time | `at` | When it happened |
| Duration in seconds | `durationSeconds` | How long the call took, on an ended call |

**Call kinds** names the vendor's word for each of integriq's four kinds:
ringing, answered, ended and transferred. Separate several words with a comma,
for example `alerting, ring` for ringing. An event whose word matches no kind is
dropped. The form stores this as the `kindMapping` setting, vendor word to
integriq's kind.

## What is stored

The form writes these keys in the source's `configuration`, under the names
the backend reads:

| Key | Read by |
|-----|---------|
| `provider` | `CtiSourceResolver`; an unknown id is refused, never sent to the sandbox |
| `signatureSecret`, `sharedSecret` | `WebhookCtiProvider`, `LogCtiProvider` |
| `eventsPath`, `fieldMapping`, `kindMapping` | `WebhookCtiProvider`, `CallEventNormaliser` |
| `callerLookupSource` | `ConfiguredCallerSearch` |

Other keys in the configuration are kept as they are.

A phone system that retries an event is answered as if it were new, but the
call is dispatched only once. Each accepted event is kept in the `call_event`
log for 30 days, so an agent's app can record the contact moment after the call
has ended.
