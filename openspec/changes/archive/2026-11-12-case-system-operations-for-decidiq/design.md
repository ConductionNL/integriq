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
- `bronorganisatie`: the RSIN written on new zaken and documents (also as the
  zaak's `verantwoordelijkeOrganisatie`, which the Zaken API requires).
- `auteur`: the author written on new documents (the Documenten API requires
  one); defaults to the source's name.
- `mock`: true answers from `lib/Settings/case-system-mock.json`.

## D3. The operations onto ZGW
- read-case: a reference that is a url is fetched (GET zaak); otherwise
  GET /zaken?identificatie=<reference>, first result. Answer url,
  identificatie, omschrijving.
- list-documents: GET /zaakinformatieobjecten?zaak=<case>, then each
  informatieobject's titel.
- read-document: GET the informatieobject, then its `inhoud` download, base64.
- add-document: POST enkelvoudiginformatieobjecten (titel, bronorganisatie,
  creatiedatum today, informatieobjecttype from `kinds`, taal dut (ISO 639-2/B,
  as the Documenten API asks),
  vertrouwelijkheidaanduiding, inhoud base64, bestandsnaam, auteur), then POST
  zaakinformatieobjecten linking it. If the link fails the document is
  deleted again and the answer is the link's error. `ground` goes into
  `beschrijving` when confidential.
- create-case: POST zaken with the meeting zaaktype, omschrijving = title,
  startdatum = date, bronorganisatie.
An unknown kind answers 422 naming the kind; a ZGW error answers its status
and a message from its `detail`, never its body.

Corrected while building (lane 23, checked against the Zaken API 1.5.1 and
Documenten API 1.4.2 openapi.yaml): every /zaken request carries
`Accept-Crs: EPSG:4326` and `Content-Crs: EPSG:4326`, which the Zaken API
requires; `taal` is `dut`, not `nld`; the zaak needs
`verantwoordelijkeOrganisatie` and the document needs `auteur`. An absolute
address a caller passes (a case or document url) is only sent when it lies
under the named ZGW source's location, so a caller cannot aim the source's
credentials at another host. The tests validate every recorded request
against request schemas converted from those two openapi.yaml files
(tests/fixtures/zgw).

## D4. Mock mode
Fixtures hold two cases with documents. add-document and create-case in mock
mode answer a new fixture url and keep it for the request only, so a test
can read back what it wrote within one run of the mock.
