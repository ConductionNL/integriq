# Design: case-system-operations-for-decidiq

## D1. In-process, like SOAP
CallService already answers a `soap` source in-process (SOAPService) and
adapts the result to a PSR-7 response, so call logs, redaction and source
rate limits run unchanged. A `case-system` source is answered the same way by
`CaseSystemOperations::handle(source, endpoint, body): ResponseInterface`.
No HTTP loop back into the instance, no public route.

## D2. The source carries its configuration
`configuration` on the source (all admin-set):
- `zakenSource`, `documentenSource`: uuids of two ordinary sources pointing at
  the ZGW APIs (their own `jwt-zgw` auth). A missing one answers 409 with a
  message naming it.
- `kinds`: object from decidiq's kind to an informatieobjecttype url.
- `meetingZaaktype`: the zaaktype url for create-case.
- `confidentialAs`: the vertrouwelijkheidaanduiding for `confidential: true`
  (default `vertrouwelijk`), `publicAs` for false (default `openbaar`).
- `bronorganisatie`: the RSIN written on new zaken and documents.
- `mock`: true answers from `lib/Settings/case-system-mock.json`.

## D3. The operations onto ZGW
- read-case: a reference that is a url is fetched (GET zaak); otherwise
  GET /zaken?identificatie=<reference>, first result. Answer url,
  identificatie, omschrijving.
- list-documents: GET /zaakinformatieobjecten?zaak=<case>, then each
  informatieobject's titel.
- read-document: GET the informatieobject, then its `inhoud` download, base64.
- add-document: POST enkelvoudiginformatieobjecten (titel, bronorganisatie,
  creatiedatum today, informatieobjecttype from `kinds`, taal nld,
  vertrouwelijkheidaanduiding, inhoud base64, bestandsnaam), then POST
  zaakinformatieobjecten linking it. If the link fails the document is
  deleted again and the answer is the link's error. `ground` goes into
  `beschrijving` when confidential.
- create-case: POST zaken with the meeting zaaktype, omschrijving = title,
  startdatum = date, bronorganisatie.
An unknown kind answers 422 naming the kind; a ZGW error answers its status
and a message from its `detail`, never its body.

## D4. Mock mode
Fixtures hold two cases with documents. add-document and create-case in mock
mode answer a new fixture url and keep it for the request only, so a test
can read back what it wrote within one run of the mock.
