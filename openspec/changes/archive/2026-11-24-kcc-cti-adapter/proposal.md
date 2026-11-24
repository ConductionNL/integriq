---
kind: code
---

# Proposal: kcc-cti-adapter

## Summary
Connect a phone system to the KCC panel. When a call rings, integriq looks
up the caller and hands the case app a typed event with the caller context,
so the agent sees who is calling before picking up and can record a contact
moment or start a case from the call. Integriq owns the telephony seam;
dossiq owns the panel.

## Motivation
Zaaksysteem's KCC session shows the caller's contact view and lets the agent
create a case or a contactmoment from the call
(`zs/pages/ContactBeeld-persoon.md`, `zs/pages/Contactmoment-dialog.md`,
under `concurrentie-analyse/procest/_round2/`). dossiq has
`KccContactController`, `BelplanController`, `DoorverbindingService` and a
settings page, and `kcc-klantcontact-integratie` names the contract; nothing
listens to a phone (finding B20, M1 6.13). The analysis puts the CTI adapter
with integriq (D11).

`kiss-kcc-bridge` already binds klantinteracties providers with log and REST
bindings and maps a klantcontact to a case reference. This change adds the
telephony provider next to it.

## Scope
- `CtiProviderInterface` with `log` and `webhook` bindings; a PBX posts
  call events to an integriq endpoint authenticated with the consumer apiKey
  mechanism.
- Caller identification: phone number to `partij` through the configured
  klantinteracties provider.
- `CallEvent` typed event (`ringing`, `answered`, `ended`, `transferred`)
  with the caller context, for the case app's panel.
- A contact moment on call end, written through the existing push endpoint
  (REQ-004) when the agent asks for it.

## Out of scope
- The agent panel page. No fleet app owns a KCC workplace surface yet; the
  case app's `kcc-klantcontact-integratie` is the consumer.
- Click to dial. A later delta.
