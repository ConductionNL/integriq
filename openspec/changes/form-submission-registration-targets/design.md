# Design: form-submission-registration-targets

No board of integriq's own. Portaliq's PtFormulierInstellingen board (canvas `5NkFW28vZUUij43xzxHg5a`), tab "Doorsturen", lists the targets this change serves. The admin page follows integriq's index plus modal pattern.

## D1. The `registrationTarget` schema

| Property | Type | Notes |
| --- | --- | --- |
| `slug`, `title` | string | |
| `kind` | enum `zgw`, `objects-api`, `stuf-zds`, `json` | |
| `sources` | object | `zgw`: `zaken`, `documenten`, optional `catalogi`. `objects-api`: `objects`, optional `documenten`. `stuf-zds`: `zkn`. `json`: `endpoint`. Each an integriq source uuid. |
| `mapping` | uuid | A Mapping from the hand-over (D3) to the outside shape. |
| `zgw` | object | `zaaktype` url, `bronorganisatie` RSIN, `verantwoordelijkeOrganisatie` RSIN, `informatieobjecttype` url for the PDF and for attachments, `vertrouwelijkheidaanduiding`, optional `zaakeigenschappen` map. |
| `objectsApi` | object | `objecttype` url, `objecttypeVersion`, optional `informatieobjecttype` for files. |
| `stufZds` | object | `zender` and `ontvanger` (organisatie, applicatie), `zaaktypeCode`, `documenttypeCode`. |
| `json` | object | `path`, optional `schema` (JSON Schema the mapped body must satisfy). |
| `allowedApps` | array | Default `["portaliq"]`. |
| `enabled` | boolean | |

`registrationRecord` holds `target`, `app`, `submissionReference`, `state` (`started`, `done`, `failed`), `steps` (what was created, with outside urls), `externalReference`, `externalUrl`, `lastError`, timestamps. Admin-only.

## D2. The command

`SubmissionRegistrationRequestedEvent(string $app, string $target, array $handover)` with a result slot. Integriq never throws to the caller. Refusals: `unknown-target`, `not-allowed`, `invalid-handover`, `mapping-failed` (all `retryable: false`), `source-unavailable`, `outside-refused` with the outside status and message (retryable when the status is 5xx or 429, not otherwise).

The command runs inside portaliq's background delivery job, not in a resident's request, so a slow outside system does not hold a page.

## D3. The hand-over

```json
{ "reference": "OF-7Q2K-...", "form": { "slug": "woo-verzoek", "title": "Woo-verzoek" },
  "submittedAt": "2026-10-08T10:12:00+02:00", "language": "nl",
  "applicant": { "kind": "bsn", "value": "999990627", "filledBy": null },
  "answers": { "onderwerp": "milieu", "bewoners": [ { "naam": "Henk de Vries" } ] },
  "fields": { "onderwerp": { "label": "Onderwerp", "type": "string", "computed": false } },
  "pdf": { "fileId": 1234 }, "attachments": [ { "fileId": 1235, "name": "kaart.pdf", "field": "bijlage" } ],
  "cosign": null }
```

Files are Nextcloud file ids on portaliq's submission object; integriq reads them as the calling app's service account and never accepts bytes in the event.

## D4. The legs

- **zgw.** 1. `POST /zaken` with the mapped case, `zaaktype`, `bronorganisatie`, `verantwoordelijkeOrganisatie`, `registratiedatum`, `startdatum`. 2. `POST /rollen` with `omschrijvingGeneriek: initiator` and the applicant as `natuurlijk_persoon` (BSN) or `niet_natuurlijk_persoon` (KvK). 3. Per file: an EnkelvoudigInformatieObject, uploaded in parts when the Documenten API asks (the upload of `connectors-case-system-document-delivery`), then a ZaakInformatieObject. 4. Optional `zaakeigenschappen`. `externalReference` is the case `identificatie`, `externalUrl` the case url.
- **objects-api.** One `POST /objects` with `type`, `record.typeVersion`, `record.data` from the mapping and `record.startAt`. Files go to the Documenten API when `informatieobjecttype` is set, and their urls land in the mapped data. `externalReference` is the object uuid.
- **stuf-zds.** `genereerZaakidentificatie_Di02`, then `creeerZaak_ZakLk01` with the mapped case and the applicant as heeft-als-initiator, then per file `genereerDocumentidentificatie_Di02` and `voegZaakdocumentToe_Lk01`. `externalReference` is the zaak identificatie.
- **json.** One POST of the mapped body to `path`. When `schema` is set the body is checked first and a mismatch is `mapping-failed`. `externalReference` is the answer's `id` when present, otherwise the submission reference.

## D5. Idempotency and resume

Before the first outside call integriq looks up `registrationRecord` by target and submission reference. `done` answers the stored result. `started` or `failed` resumes after the last step in `steps`: a ZGW case already created is not created again, only its missing documents are. For ZGW, a missing record is also checked outside: a case with `kenmerken` holding the submission reference is reused.

## D6. The admin page

*Beheer > Registratiedoelen* lists slug, title, kind, sources and the last result. The modal shows only the settings of the chosen kind. "Testen" sends a sample hand-over to the target with a dry flag that stops before the first write and shows the mapped body.
