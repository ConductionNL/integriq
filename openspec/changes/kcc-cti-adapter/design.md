# Design: kcc-cti-adapter

Kind: code. A provider interface, a webhook endpoint, a typed event.

## D1. `CtiProviderInterface`

`lib/Service/Kiss/CtiProviderInterface.php`: `getProviderId()`,
`getConfigSchema()`, `normalize(array $rawEvent): CallEvent`,
`verify(IRequest $request, array $sourceConfiguration): bool`. Bindings:
`log` (replays a fixture) and `webhook` (generic JSON, field mapping per
source through the existing mapping engine). A vendor binding is one class
implementing `normalize()`.

## D2. Webhook endpoint

`POST /api/cti/{sourceId}/events`, `#[PublicPage]`, `#[NoCSRFRequired]`,
authenticated with the consumer apiKey verification that
`notificaties-api-connector` REQ-002 reuses. An unverified request is 401
and logged, never processed.

## D3. Caller identification

`CallEvent.callerNumber` normalized to E.164; lookup through the configured
`KlantinteractiesProviderInterface` (`digitaleadressen` by phone) giving
`partij` and, through REQ-003, open case references. A miss yields
`caller: null`; the event still fires so the panel can show an unknown
caller.

## D4. `CallEvent` typed event (ADR-041)

`OCA\Integriq\Event\CallEvent(kind, callId, callerNumber, caller,
openCases[], agentId, sourceId, at)`. Dispatched on every normalized event.
Also published on the CloudEvents fan-out as `nl.conduction.integriq.call.*`
for the panel's live update over the existing channel. No result slot; a
call is a fact, not a command.

## D5. Contact moment

On `ended`, when the agent's panel asks (it calls the REQ-004 push endpoint
with the `callId`), integriq creates a klantcontact with `kanaal = telefoon`
and the call duration. Integriq never creates one unasked.

As built: every accepted event is written to a `call_event` row (system
context, admin and owner only, 30-day retention) before it is dispatched.
The push finds the ended row by `callId` (plus `callSourceId` when one callId
ended on two sources). The duration lives on the local `kiss_klantcontact`
mirror, because the Klantinteracties klantcontact has no field for it. A
second push for the same call returns the first contact moment.

## Risks
- A PBX that posts the same event twice. `callId` plus `kind` is idempotent.
- Phone numbers are personal data. Stored on the `callEvent` log for 30
  days through a declared retention, then dropped.
