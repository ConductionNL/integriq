# Ask the opt-out list before every send

Part of the hydra change `opt-out-before-send` (ConductionNL/hydra#739). That change holds the fleet contract, the full sender table and Ruben's decisions of 2026-10-05. This change is integriq's share.

## Why

The unsubscribe link writes an opt-out (`lib/Controller/SenderIdentityController.php:252`). Nothing reads it before a send. `OptOutRegistry::decide()` (`lib/Outbound/Identity/OptOutRegistry.php:115`) and `MessageComposer::compose()` (`lib/Outbound/Identity/MessageComposer.php:59`) have no caller in `lib/`. So a person who clicks the link is still messaged. This is #2167.

The change `outbound-sender-identity-and-deliverability` requires the check (REQ-OSI-004) and the link (REQ-OSI-005). Its tasks 4.2 and 5.1 are ticked without a call site. Ruben decided on 2026-10-05 to leave them ticked. This change does the wiring.

## What changes

- **integriq's own senders ask first.** NotifyNL SMS (`lib/Service/SmsDispatchService.php:136`), intake replies (`lib/Intake/IntakeReplyService.php:67`), digital post (`lib/Service/DigitalPost/DigitalPostService.php:77`) and personal deliveries (`lib/Service/EventService.php:2870`).
- **Two public events** for sibling apps (ADR-041): `OutboundSendDecisionRequestedEvent` and `OptOutChangeRequestedEvent`, each with a listener.
- **The table grows** so it can hold pipelinq's consent: state, channel, purpose, list, contact, lawful basis, evidence, withdrawal.
- **A log table** records suppressions, overrides and state changes.
- **The exempt set is fixed**: `besluit`, `statutory`, `account`, `security`. `ontvangstbevestiging` and `invordering` stay as aliases of `statutory`.
- **The link fits the channel.** A new token version carries scope, channel and ref. GET confirms, POST writes, one-click works. SMS gets a short link.
- **The digital post event carries a category**, so a besluit by Berichtenbox stays exempt and a case update does not.

## Capabilities

### New capabilities

- `outbound-opt-out-authority`: integriq decides, records and links for every citizen message in the fleet.

## Impact

- `lib/Outbound/Identity/`: `OptOutRegistry`, `UnsubscribeTokenService`, `MessageComposer` get callers and grow.
- `lib/Event/`: two new events. `DigitalPostSendRequestedEvent` gains an optional `category`.
- `lib/EventListener/`: two new listeners, registered in `lib/AppInfo/Application.php`.
- `lib/Migration/`: two migrations.
- `appinfo/routes.php`: POST `/unsubscribe/{token}`, GET `/u/{id}`.
- Sibling apps that dispatch the events: openregister, dossiq, pipelinq. Each has its own `opt-out-before-send` change.

## Reuse

ADR-011 check. Phone numbers go through the existing `PhoneNumberValidator::toE164()` (`lib/Service/Sms/PhoneNumberValidator.php:81`). OpenRegister has no phone or email format in `lib/Formats/`. Nothing else here duplicates an OpenRegister utility. The opt-outs stay in integriq's own table, not in OpenRegister, as #2528 decided: the public unsubscribe write has no session and OpenRegister refuses it.

## Rollback

The flag `outbound.optout_authority` (default `true`) turns the check off in integriq's own senders. The listeners then answer unhandled, so sibling apps fail closed for non-exempt categories. The migrations only add columns and a table.
