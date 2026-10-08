---
kind: code
depends_on: [connectors-case-system-document-delivery, zgw-connectors-for-dossiq]
---

# Proposal: form-submission-registration-targets

## Summary

Let a form submission be registered in an outside system through integriq. An administrator defines a registration target of one of four kinds: a ZGW case system (Zaken and Documenten API), the Objects API, a StUF-ZDS case system, or a plain JSON endpoint. Portaliq names a target on a form binding and hands over each submission with one typed command; integriq creates the case, object or record, uploads the PDF and the attachments, and answers the outside reference. A repeated hand-over of the same submission creates nothing twice.

Rows covered: portaliq `int-registration-per-form` (decision 104). This is integriq's half of portaliq's `form-governance-availability-retention-and-routing` (merged in portaliq #1387).

## Why

Portaliq's change gives a form binding a `delivery` with target kinds `caseType`, `email` and `integriq` (`{ "kind": "integriq", "source": "<connection id>" }`), with rules that pick a target from the answers. Its proposal says: "integriq owns the external registration targets (ZGW, Objects API, StUF-ZDS, JSON). Portaliq hands over the submission and the named connection; it builds no ZGW or StUF client (ADR-022)." The PtFormulierInstellingen board draws it on the "Doorsturen" tab.

Open Formulieren 4.0.1 has a registration backend per form: `zgw_apis`, `objects_api`, `stuf_zds_create_zaak`, `json_dump` and `email` (`src/openforms/registrations/contrib/`). Many municipalities run their case handling in a system that only speaks one of these.

What integriq has today:

- The ZGW connector sets (`zgw-connectors-for-dossiq`) and the document push with chunked upload (`connectors-case-system-document-delivery`), both open changes.
- A StUF-ZKN client with mTLS (`lib/Service/StufZkn/StufZknClient.php`) that sends `zakLk01`, and the document message `voegZaakdocumentToe` once `connectors-case-system-document-delivery` lands.
- No command another app can call to create a case, an object or a record from one submission.

## What changes

- **A `registrationTarget` object** per outside system: kind (`zgw`, `objects-api`, `stuf-zds`, `json`), the source or sources, a mapping from the submission to the outside shape, and the kind's own settings (ZGW case type and organisation numbers; Objects API object type and version; StUF-ZDS zender and ontvanger; JSON path).
- **A typed command `SubmissionRegistrationRequestedEvent`.** Portaliq passes the target slug, the submission reference, the answers with their field metadata, the applicant, the PDF and the attachments as file references. Integriq answers `{ ok, externalReference, externalUrl }` or a refusal with `retryable` true or false.
- **The four legs.** ZGW: case, initiator role, documents and their links. Objects API: one object of the configured type. StUF-ZDS: a case identification, `creeerZaak` and one `voegZaakdocumentToe` per document. JSON: one POST of the mapped submission.
- **Idempotency.** Integriq records each registration by target and submission reference and answers the earlier result for a repeat. A half-finished ZGW or StUF registration resumes where it stopped.
- **A list for portal administrators.** `GET /api/registration-targets` lists the enabled targets by slug, title and kind, for the "Doorsturen" picker.

## Out of scope

- Rules that pick a target, retries, the digest and the failed state: portaliq.
- E-mail as a target: portaliq sends it.
- Payment status updates on a registered case (Open Formulieren's `update_payment_status`): a follow-up once `intake-pay-on-submit` lands.

## Impact

- Specs: new capability `submission-registration`.
- New: `lib/Settings/register.d/registration-target.json` (schemas `registrationTarget` and `registrationRecord`), `lib/Event/SubmissionRegistrationRequestedEvent.php`, `lib/Listener/SubmissionRegistrationListener.php`, `lib/Service/Registration/` (`RegistrationService`, `ZgwRegistrationLeg`, `ObjectsApiRegistrationLeg`, `StufZdsRegistrationLeg`, `JsonRegistrationLeg`), `lib/Controller/RegistrationTargetController.php`, `src/views/admin/RegistrationTargetsPage.vue`, `src/modals/RegistrationTargetModal.vue`.
- Changed: `lib/Service/StufZkn/` (`genereerZaakidentificatie` and `creeerZaak`), `appinfo/routes.php`, `lib/AppInfo/Application.php`, `l10n/`.

## Cross-project dependencies

- portaliq `form-governance-availability-retention-and-routing` dispatches the command from its delivery queue, stores `externalReference` on the submission and retries on a `retryable` refusal.
