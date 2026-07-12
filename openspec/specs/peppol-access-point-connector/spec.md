---
status: in-progress
---

# peppol-access-point-connector Specification

## Purpose

OpenConnector connects to the Peppol network through a certified Access Point
(AP) so sibling apps can look up Peppol participants, transmit UBL documents
(e-invoices, orders), and receive inbound documents without embedding an
AS4/Peppol client. It is an NL-infrastructure adapter in the same family as
`digikoppeling-adapter`: delivered as source/adapter configuration (ADR-017),
resolving AP credentials through the OpenRegister credential broker (ADR-007),
and integrating via CloudEvents (`events-cloudevents`) and the inbound
`webhook_signature` rule (`webhook-signing`). Per ADR-022 the Peppol
integration lives here; leaf apps (e.g. shillinq) consume it via events and its
REST surface. This capability owns the cross-app Peppol transport contract
(`nl.conduction.peppol.outbound.requested`,
`nl.conduction.peppol.delivery.status`, participant lookup).

@e2e exclude backend Peppol transport (SMP lookup, AP transmission, inbound webhook) — covered by PHPUnit/Newman, no browser UI

**OpenSpec changes**
- `peppol-access-point-connector` (active) — introduces the participant/SMP
  lookup endpoint, the `PeppolAccessPointProviderInterface` abstraction
  (`log`/sandbox + generic `rest` bindings), the event-driven outbound
  transmission path with a `peppol_transmission` status lifecycle, delivery-
  status CloudEvent emission, and the signed inbound receive webhook. While
  active, the normative requirements (REQ-001..006) live in the change's delta
  spec (`openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md`)
  and merge here on archive.

## Requirements

_Defined in the active change delta (REQ-001..006); merged here on archive._
